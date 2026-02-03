<?php
// admin/settings.php
declare(strict_types=1);

require_once dirname(dirname(__DIR__)) . '/includes/bootstrap.php';
require_once dirname(dirname(__DIR__)) . '/includes/auth.php';
require_once dirname(dirname(__DIR__)) . '/includes/helpers.php';
require_once dirname(dirname(__DIR__)) . '/includes/rbac.php';

if (function_exists('require_admin_login')) require_admin_login();
require_permission('settings.manage');

$db = $GLOBALS['db'] ?? null;

function table_exists(mysqli $db, string $table): bool {
  $r = $db->query("SHOW TABLES LIKE '" . $db->real_escape_string($table) . "'");
  return $r && $r->num_rows > 0;
}

$page_title = "Settings";
$page_subtitle = "Manage global system settings";

require_once dirname(dirname(__DIR__)) . '/templates/layout/header.php';
?>
<div class="app-shell">
  <?php require_once dirname(dirname(__DIR__)) . '/templates/layout/sidebar.php'; ?>

  <div class="app-content">
    <?php require_once dirname(dirname(__DIR__)) . '/templates/layout/topbar.php'; ?>

    <main class="page-wrap">
      <div class="container-fluid py-3">

    <!--    <div class="mb-3">
          <h4 class="mb-1"><?= h($page_title) ?></h4>
          <div class="text-muted small"><?= h($page_subtitle) ?></div>
        </div> -->

        <?php if (!$db instanceof mysqli): ?>
          <div class="alert alert-danger">Database not available.</div>
        <?php else: ?>
          <?php
            if (!table_exists($db,'settings')) {
              echo '<div class="alert alert-warning"><b>settings</b> table not found.</div>';
              echo '<pre class="small bg-light border rounded p-2 mb-0">' . h(
"CREATE TABLE settings (
  id INT AUTO_INCREMENT PRIMARY KEY,
  `key` VARCHAR(100) NOT NULL UNIQUE,
  `value` TEXT DEFAULT NULL,
  `description` VARCHAR(255) DEFAULT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);"
          ) . '</pre>';
          require_once dirname(dirname(__DIR__)) . '/templates/layout/footer.php'; exit;
        }

        $rs = $db->query("SELECT `key`, `value`, `description`, updated_at FROM settings ORDER BY `key` ASC");
        $rows = $rs ? $rs->fetch_all(MYSQLI_ASSOC) : [];
      ?>

      <div class="card shadow-sm mb-3">
        <div class="card-body">
          <form id="formAddSetting" class="row g-2">
            <div class="col-md-4">
              <input class="form-control" name="key" placeholder="key e.g. business_name" required>
            </div>
            <div class="col-md-6">
              <input class="form-control" name="value" placeholder="value" required>
            </div>
            <div class="col-md-2 d-grid">
              <button class="btn btn-primary" type="submit">Save</button>
            </div>
          </form>
          <div class="small text-muted mt-2">Uses an upsert (insert/update) via API.</div>
        </div>
      </div>

      <div class="card shadow-sm">
        <div class="card-body">
          <div class="table-responsive">
            <table class="table table-sm table-hover align-middle">
              <thead class="table-light">
                <tr>
                  <th>Key</th>
                  <th>Value</th>
                  <th>Description</th>
                  <th>Updated</th>
                  <th class="text-end">Actions</th>
                </tr>
              </thead>
              <tbody>
                <?php if (!$rows): ?>
                  <tr><td colspan="5" class="text-center text-muted py-4">No settings yet.</td></tr>
                <?php else: foreach ($rows as $r): ?>
                  <tr>
                    <td class="fw-semibold"><?= h($r['key']) ?></td>
                    <td style="max-width:520px; white-space:nowrap; overflow:hidden; text-overflow:ellipsis;">
                      <?= h($r['value'] ?? '') ?>
                    </td>
                    <td><?= h($r['description'] ?? '') ?></td>
                    <td><?= h($r['updated_at'] ?? '') ?></td>
                    <td class="text-end">
                      <button class="btn btn-sm btn-outline-secondary btnEdit"
                              data-key="<?= h($r['key']) ?>"
                              data-val="<?= h($r['value'] ?? '') ?>"
                              title="Edit Setting">
                        <i class="bi bi-pencil"></i>
                      </button>
                      <button class="btn btn-sm btn-outline-danger btnDelete"
                              data-key="<?= h($r['key']) ?>"
                              title="Delete Setting">
                        <i class="bi bi-trash"></i>
                      </button>
                    </td>
                  </tr>
                <?php endforeach; endif; ?>
              </tbody>
            </table>
          </div>
        </div>
      </div>

    <?php endif; ?>
  </div>
</div>

<div class="modal fade" id="modalEditSetting" tabindex="-1">
  <div class="modal-dialog">
    <form class="modal-content" id="formEditSetting">
      <div class="modal-header">
        <h5 class="modal-title">Edit Setting</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <div class="mb-2">
          <label class="form-label">Key</label>
          <input class="form-control" name="key" id="edit_key" readonly>
        </div>
        <div class="mb-2">
          <label class="form-label">Value</label>
          <textarea class="form-control" name="value" id="edit_val" rows="4"></textarea>
        </div>
      </div>
      <div class="modal-footer">
        <button class="btn btn-outline-secondary" type="button" data-bs-dismiss="modal">Cancel</button>
        <button class="btn btn-primary" type="submit">Update</button>
      </div>
    </form>
  </div>
</div>

<script>
(async function(){
  const post = async (url, fd) => {
    try {
      const res = await fetch(url, {method:'POST', body: fd, credentials:'same-origin'});
      
      // Check if response is OK
      if (!res.ok) {
        throw new Error(`HTTP error! status: ${res.status}`);
      }
      
      // Get response text first to debug
      const responseText = await res.text();
      console.log('Raw response:', responseText);
      
      // Try to parse as JSON
      try {
        return JSON.parse(responseText);
      } catch (parseError) {
        console.error('JSON parse error:', parseError);
        console.error('Response text:', responseText);
        throw new Error('Invalid JSON response: ' + responseText.substring(0, 200));
      }
    } catch (error) {
      console.error('Fetch error:', error);
      throw error;
    }
  };

  document.getElementById('formAddSetting')?.addEventListener('submit', async (e)=>{
    e.preventDefault();
    const j = await post('api/settings/upsert.php', new FormData(e.target));
    alert(j.message || (j.success ? 'Saved' : 'Failed'));
    if (j.success) location.reload();
  });

  document.querySelectorAll('.btnEdit').forEach(btn=>{
    btn.addEventListener('click', ()=>{
      edit_key.value = btn.dataset.key || '';
      edit_val.value = btn.dataset.val || '';
      new bootstrap.Modal(document.getElementById('modalEditSetting')).show();
    });
  });

  document.getElementById('formEditSetting')?.addEventListener('submit', async (e)=>{
    e.preventDefault();
    const j = await post('api/settings/upsert.php', new FormData(e.target));
    alert(j.message || (j.success ? 'Updated' : 'Failed'));
    if (j.success) location.reload();
  });

  document.querySelectorAll('.btnDelete').forEach(btn=>{
    btn.addEventListener('click', async ()=>{
      if (!confirm('Delete this setting?')) return;
      const fd = new FormData();
      fd.append('key', btn.dataset.key || '');
      const j = await post('api/settings/delete.php', fd);
      alert(j.message || (j.success ? 'Deleted' : 'Failed'));
      if (j.success) location.reload();
    });
  });
})();
</script>

<?php require_once dirname(dirname(__DIR__)) . '/templates/layout/footer.php'; ?>