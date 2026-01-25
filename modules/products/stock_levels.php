<?php
// modules/products/stock_levels.php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/rbac.php';
require_once __DIR__ . '/../../includes/helpers.php';

require_permission('products.view');

$db = $GLOBALS['db'];
$BASE_URL = $GLOBALS['BASE_URL'] ?? '';

$page_title = "Stock Levels";
$page_subtitle = "Current inventory status and low stock alerts";

$q = trim((string)($_GET['q'] ?? ''));
$locationId = (int)($_GET['location_id'] ?? 0);
$lowOnly = (int)($_GET['low'] ?? 0) === 1;

if (!$db instanceof mysqli) {
  http_response_code(500);
  die("DB not available");
}

// Build query
$where = "1=1";
$types = "";
$params = [];

if ($q !== '') {
  $where .= " AND (p.name LIKE ? OR p.sku LIKE ?)";
  $like = "%{$q}%";
  $types .= "ss";
  $params[] = $like;
  $params[] = $like;
}

if ($lowOnly) {
  $where .= " AND p.qty_base <= p.low_level_base";
}
if ($locationId > 0) {
  $where .= " AND s.location_id = ?";
  $types .= "i";
  $params[] = $locationId;
}

$sql = "SELECT
               p.id, p.sku, p.name, p.unit_type, p.unit_name, p.pieces_per_box,
               COALESCE(s.qty_base, 0) AS qty_base,
               COALESCE(s.low_level_base, 0) AS low_level_base,
               p.is_active,
               p.cost_price, p.wholesale_price, p.retail_price,
               c.name AS category_name,
               COALESCE(l.name, '—') AS location_name,
               l.low_alert_qty,
               l.low_alert_type
        FROM products p
        LEFT JOIN product_categories c ON c.id = p.category_id
        LEFT JOIN stock_by_location s ON s.product_id = p.id" . ($locationId > 0 ? " AND s.location_id = $locationId" : "") . "
        LEFT JOIN locations l ON l.id = s.location_id
        WHERE {$where}
        ORDER BY (COALESCE(s.qty_base,0) <= l.low_alert_qty) DESC, p.name ASC
        LIMIT 800";

$stmt = $db->prepare($sql);
if ($types !== '') $stmt->bind_param($types, ...$params);
$stmt->execute();
$res = $stmt->get_result();

$rows = [];
while ($r = $res->fetch_assoc()) {
  $r['stock_display'] = format_stock($r);
  // Determine low stock based on location settings
  $isLow = false;
  if ($r['qty_base'] > 0 && $r['low_alert_qty'] > 0) {
    $alertQty = (float)$r['low_alert_qty'];
    $alertType = $r['low_alert_type'];
    // Convert alert quantity to base units for comparison
    $factor = 1;
    if ($alertType === 'cartons') {
      $factor = max(1, (int)($r['pieces_per_box'] ?? 0));
    } elseif ($alertType === 'dozens') {
      $factor = 12;
    } elseif ($alertType === 'pairs') {
      $factor = 2;
    }
    $isLow = ((float)$r['qty_base'] <= ($alertQty * $factor));
  }
  $r['is_low'] = $isLow ? 1 : 0;
  $rows[] = $r;
}
$stmt->close();

require_once __DIR__ . '/../../templates/layout/header.php';
?>
<div class="app-shell">
  <?php require_once __DIR__ . '/../../templates/layout/sidebar.php'; ?>

  <div class="app-content">
    <?php require_once __DIR__ . '/../../templates/layout/topbar.php'; ?>

    <main class="page-wrap">

      <div class="d-flex flex-wrap gap-2 align-items-center justify-content-between mb-3">
        <form class="d-flex flex-wrap gap-2 align-items-center" method="get">
          <input
            name="q"
            value="<?= h($q) ?>"
            class="form-control form-control-sm"
            style="max-width:320px"
            placeholder="Search by name or SKU..."
          >

          <select name="location_id" class="form-select form-select-sm" style="max-width:200px">
            <option value="">All Locations</option>
            <?php
              $locRes = $db->query("SELECT id, name FROM locations WHERE is_active=1 ORDER BY name ASC");
              while ($loc = $locRes->fetch_assoc()) {
                echo '<option value="' . h((string)$loc['id']) . '"' . ($locationId == $loc['id'] ? ' selected' : '') . '>' . h($loc['name']) . '</option>';
              }
            ?>
          </select>

          <label class="d-flex align-items-center gap-2 small">
            <input type="checkbox" name="low" value="1" <?= $lowOnly ? 'checked' : '' ?>>
            Low stock only
          </label>

          <button class="btn btn-sm btn-outline-secondary">Filter</button>
          <a class="btn btn-sm btn-outline-secondary" href="<?= h($BASE_URL) ?>/modules/products/stock_levels.php">Reset</a>
        </form>

        <div class="small text-muted">
          Showing: <b><?= count($rows) ?></b>
        </div>
      </div>

      <div class="card shadow-sm rounded-4">
        <div class="card-body">

          <div class="table-responsive">
            <table class="table table-sm align-middle">
              <thead>
                <tr>
                  <th>SKU</th>
                  <th>Product</th>
                  <th>Category</th>
                  <th>Location</th>
                  <th>Unit</th>
                  <th>Stock</th>
                  <th class="text-end">Base Qty</th>
                  <th class="text-end">Low Level</th>
                  <th>Status</th>
                  <th class="text-end">Prices</th>
                </tr>
              </thead>
              <tbody>
                <?php if (!$rows): ?>
                  <tr><td colspan="10" class="text-muted">No products found.</td></tr>
                <?php endif; ?>

                <?php foreach ($rows as $p): ?>
                  <?php
                    $lowBadge = $p['is_low']
                      ? '<span class="badge bg-danger">LOW</span>'
                      : '<span class="badge bg-success">OK</span>';

                    $unitLabel = $p['unit_type'];
                    if ($p['unit_type'] === 'units') {
                      $unitLabel .= " (" . h((string)($p['unit_name'] ?? '')) . ")";
                    } elseif ($p['unit_type'] === 'boxes') {
                      $unitLabel .= " • " . (int)($p['pieces_per_box'] ?? 0) . " pcs/box";
                    }

                    $activeBadge = ((int)$p['is_active'] === 1)
                      ? '<span class="badge bg-success">Active</span>'
                      : '<span class="badge bg-secondary">Disabled</span>';

                    $prices = "W: " . number_format((float)$p['wholesale_price'], 0)
                            . " • R: " . number_format((float)$p['retail_price'], 0);
                  ?>
                  <tr class="<?= $p['is_low'] ? 'table-danger' : '' ?>">
                    <td><?= h((string)$p['sku']) ?></td>
                    <td class="fw-semibold"><?= h((string)$p['name']) ?></td>
                    <td><?= h((string)($p['category_name'] ?? '')) ?></td>
                    <td><?= h((string)($p['location_name'] ?? '—')) ?></td>
                    <td><?= h($unitLabel) ?></td>

                    <td>
                      <?= h((string)$p['stock_display']) ?>
                      <div class="small text-muted"><?= $lowBadge ?></div>
                    </td>

                    <td class="text-end">
                      <?= h((string)rtrim(rtrim(number_format((float)$p['qty_base'], 2, '.', ''), '0'), '.')) ?>
                    </td>
                    <td class="text-end">
                      <?= h((string)rtrim(rtrim(number_format((float)$p['low_level_base'], 2, '.', ''), '0'), '.')) ?>
                    </td>
                    <td><?= $activeBadge ?></td>
                    <td class="text-end small"><?= h($prices) ?></td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>

          <div class="small text-muted mt-2">
            Note: Stock is stored in <b>base quantity</b> (pieces for boxes/dozens/pairs; exact units for kg/liters).
            The “Stock” column shows the human format like “cartons and pieces”.
          </div>

        </div>
      </div>

    </main>
  </div>
</div>

<!-- Movements Modal -->
<div class="modal fade" id="mdlMoves" tabindex="-1">
  <div class="modal-dialog modal-xl modal-dialog-scrollable">
    <div class="modal-content">
      <div class="modal-header">
        <h6 class="modal-title">Stock Movements</h6>
        <button class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <div class="table-responsive">
          <table class="table table-sm align-middle">
            <thead>
              <tr>
                <th>#</th>
                <th>When</th>
                <th>Type</th>
                <th>From</th>
                <th>To</th>
                <th class="text-end">Before</th>
                <th class="text-end">Change</th>
                <th class="text-end">After</th>
                <th>Note</th>
              </tr>
            </thead>
            <tbody id="movesBody"></tbody>
          </table>
        </div>
      </div>
    </div>
  </div>
</div>

<script>
const BASE_URL = <?= json_encode($BASE_URL) ?>;
const mdlMoves = new bootstrap.Modal(document.getElementById('mdlMoves'));
const movesBody = document.getElementById('movesBody');

async function openMovements(productId){
  movesBody.innerHTML = `<tr><td colspan="9" class="text-muted">Loading…</td></tr>`;
  mdlMoves.show();

  const res = await fetch(BASE_URL + "/api/stock.php?action=movements&product_id=" + encodeURIComponent(productId) + "&limit=80");
  const json = await res.json();

  if(!json.ok){
    movesBody.innerHTML = `<tr><td colspan="9" class="text-danger">${escapeHtml(json.error || 'Failed')}</td></tr>`;
    return;
  }

  if(!json.data.length){
    movesBody.innerHTML = `<tr><td colspan="9" class="text-muted">No movements yet.</td></tr>`;
    return;
  }

  movesBody.innerHTML = json.data.map(r => {
    const chg = Number(r.qty_change || 0);
    const badge = chg >= 0
      ? `<span class="badge bg-success">+${chg}</span>`
      : `<span class="badge bg-danger">${chg}</span>`;

    return `
      <tr>
        <td>${r.id}</td>
        <td class="small">${escapeHtml(r.created_at || '')}</td>
        <td>${escapeHtml(r.movement_type || '')}</td>
        <td>${escapeHtml(r.from_loc || '—')}</td>
        <td>${escapeHtml(r.to_loc || '—')}</td>
        <td class="text-end">${escapeHtml(r.qty_before || '')}</td>
        <td class="text-end">${badge}</td>
        <td class="text-end">${escapeHtml(r.qty_after || '')}</td>
        <td class="small text-muted">${escapeHtml(r.note || '')}</td>
      </tr>
    `;
  }).join('');
}

function escapeHtml(s){
  return String(s ?? '').replace(/[&<>"']/g, m => ({
    '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'
  }[m]));
}

document.addEventListener('click', (e)=>{
  const btn = e.target.closest('[data-movements]');
  if(!btn) return;
  openMovements(btn.getAttribute('data-movements'));
});
</script>

<?php require_once __DIR__ . '/../../templates/layout/footer.php'; ?>
