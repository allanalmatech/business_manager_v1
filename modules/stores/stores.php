<?php
// modules/stores/stores.php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/rbac.php';
require_once __DIR__ . '/../../includes/helpers.php';

require_permission('stores.manage');

$BASE_URL = $GLOBALS['BASE_URL'] ?? '';
$page_title = "Stores";
$page_subtitle = "Manage locations and their low-stock thresholds";

$db = $GLOBALS['db'];
if (!$db instanceof mysqli) {
  http_response_code(500);
  die("DB not available");
}

// Handle actions
$action = $_POST['action'] ?? '';
if ($action === 'save') {
  require_permission('stores.update');
  $id = (int)($_POST['id'] ?? 0);
  $name = trim((string)($_POST['name'] ?? ''));
  $is_active = (int)($_POST['is_active'] ?? 1);
  $low_alert_qty = (float)($_POST['low_alert_qty'] ?? 0);
  $low_alert_type = trim((string)($_POST['low_alert_type'] ?? 'pieces'));

  if ($name === '') {
    $error = "Name is required";
  } else {
    if ($id > 0) {
      $stmt = $db->prepare("UPDATE locations SET name=?, is_active=?, low_alert_qty=?, low_alert_type=? WHERE id=?");
      $stmt->bind_param("sidsi", $name, $is_active, $low_alert_qty, $low_alert_type, $id);
      $stmt->execute();
      $stmt->close();
      audit_log('stores.update', 'location', (string)$id, "Updated store: $name");
    } else {
      $stmt = $db->prepare("INSERT INTO locations (name, is_active, low_alert_qty, low_alert_type) VALUES (?,?,?,?)");
      $stmt->bind_param("sids", $name, $is_active, $low_alert_qty, $low_alert_type);
      $stmt->execute();
      $stmt->close();
      audit_log('stores.create', 'location', (string)$stmt->insert_id, "Created store: $name");
    }
    header("Location: " . $BASE_URL . "/modules/stores/stores.php");
    exit;
  }
}

if ($action === 'delete') {
  require_permission('stores.delete');
  $id = (int)($_POST['id'] ?? 0);
  if ($id <= 0) die("Invalid id");
  $stmt = $db->prepare("DELETE FROM locations WHERE id=?");
  $stmt->bind_param("i", $id);
  $stmt->execute();
  $stmt->close();
  audit_log('stores.delete', 'location', (string)$id, "Deleted store");
  header("Location: " . $BASE_URL . "/modules/stores/stores.php");
  exit;
}

// Fetch list
$res = $db->query("SELECT id, name, is_active, low_alert_qty, low_alert_type, created_at FROM locations ORDER BY name ASC");
$rows = [];
while ($r = $res->fetch_assoc()) $rows[] = $r;

require_once __DIR__ . '/../../templates/layout/header.php';
?>
<div class="app-shell">
  <?php require_once __DIR__ . '/../../templates/layout/sidebar.php'; ?>

  <div class="app-content">
    <?php require_once __DIR__ . '/../../templates/layout/topbar.php'; ?>

    <main class="page-wrap">
      <div class="d-flex flex-wrap gap-2 align-items-center justify-content-between mb-3">
        <div>
          <h4 class="mb-0">Stores / Locations</h4>
          <small class="text-muted">Manage stores and per-location low-stock alerts</small>
        </div>
        <?php if (user_has_permission('stores.create')): ?>
          <button class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#storeModal">+ Add Store</button>
        <?php endif; ?>
      </div>

      <div class="card shadow-sm rounded-4">
        <div class="card-body">
          <div class="table-responsive">
            <table class="table table-sm align-middle">
              <thead>
                <tr>
                  <th>Name</th>
                  <th>Low Alert Qty</th>
                  <th>Low Alert Type</th>
                  <th>Status</th>
                  <th class="text-end">Created</th>
                  <th class="text-end">Actions</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($rows as $r): ?>
                  <tr>
                    <td><?= h($r['name']) ?></td>
                    <td><?= h((string)$r['low_alert_qty']) ?></td>
                    <td><?= h(ucfirst((string)$r['low_alert_type'])) ?></td>
                    <td><?= $r['is_active'] ? '<span class="badge bg-success">Active</span>' : '<span class="badge bg-secondary">Disabled</span>' ?></td>
                    <td class="text-end small"><?= h((string)$r['created_at']) ?></td>
                    <td class="text-end">
                      <?php if (user_has_permission('stores.update')): ?>
                        <button class="btn btn-sm btn-outline-primary" data-edit="<?= (int)$r['id'] ?>" data-name="<?= h($r['name']) ?>" data-is_active="<?= (int)$r['is_active'] ?>" data-low_alert_qty="<?= h((string)$r['low_alert_qty']) ?>" data-low_alert_type="<?= h($r['low_alert_type']) ?>">Edit</button>
                      <?php endif; ?>
                      <?php if (user_has_permission('stores.delete')): ?>
                        <button class="btn btn-sm btn-outline-danger" data-delete="<?= (int)$r['id'] ?>" data-name="<?= h($r['name']) ?>">Delete</button>
                      <?php endif; ?>
                    </td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        </div>
      </div>

      <!-- Modal -->
      <div class="modal fade" id="storeModal" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered p-3">
          <div class="modal-content">
            <div class="modal-header">
              <h6 class="modal-title">Store</h6>
              <button class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-3">
              <form id="storeForm" method="post">
              <input type="hidden" name="id" id="editId">
              <div class="mb-3">
                <label class="form-label">Name *</label>
                <input class="form-control" name="name" id="editName" required>
              </div>
              <div class="row g-2">
                <div class="col-md-6">
                  <label class="form-label">Low Alert Qty</label>
                  <input class="form-control" name="low_alert_qty" id="editLowAlertQty" type="number" step="0.01" min="0">
                  <div class="form-text">Trigger low-stock alert when stock at this location falls at or below this quantity.</div>
                </div>
                <div class="col-md-6">
                  <label class="form-label">Low Alert Type</label>
                  <select class="form-select" name="low_alert_type" id="editLowAlertType">
                    <option value="pieces">Pieces</option>
                    <option value="cartons">Cartons</option>
                    <option value="dozens">Dozens</option>
                    <option value="pairs">Pairs</option>
                    <option value="units">Units</option>
                  </select>
                </div>
              </div>
              <div class="mb-3">
                <div class="form-check">
                  <input class="form-check-input" type="checkbox" name="is_active" id="editIsActive" checked>
                  <label class="form-check-label" for="editIsActive">Active</label>
                </div>
              </div>
              <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" class="btn btn-primary">Save</button>
              </div>
            </form>
          </div>
        </div>
      </div>

    </main>
  </div>
</div>

<?php require_once __DIR__ . '/../../templates/layout/footer.php'; ?>

<script>
const modal = new bootstrap.Modal(document.getElementById('storeModal'));
const form = document.getElementById('storeForm');

// Edit
document.addEventListener('click', (e) => {
  const btn = e.target.closest('[data-edit]');
  if (!btn) return;
  const id = btn.dataset.edit;
  document.getElementById('editId').value = id;
  document.getElementById('editName').value = btn.dataset.name || '';
  document.getElementById('editLowAlertQty').value = btn.dataset.low_alert_qty || '';
  document.getElementById('editLowAlertType').value = btn.dataset.low_alert_type || 'pieces';
  document.getElementById('editIsActive').checked = btn.dataset.is_active === '1';
  modal.show();
});

// Delete
document.addEventListener('click', (e) => {
  const btn = e.target.closest('[data-delete]');
  if (!btn) return;
  if (!confirm('Delete store "' + btn.dataset.name + '"?')) return;
  const fd = new FormData();
  fd.append('action', 'delete');
  fd.append('id', btn.dataset.delete);
  fd.append('csrf', window.APP?.CSRF || '');
  fetch('<?= $BASE_URL ?>/modules/stores/stores.php', {method:'POST', body:fd})
    .then(r => r.text())
    .then(() => location.reload());
});

// Submit
form.addEventListener('submit', (e) => {
  e.preventDefault();
  const fd = new FormData(form);
  fd.append('action', 'save');
  fd.append('csrf', window.APP?.CSRF || '');
  fetch('<?= $BASE_URL ?>/modules/stores/stores.php', {method:'POST', body:fd})
    .then(r => r.text())
    .then(() => location.reload());
});
</script>
