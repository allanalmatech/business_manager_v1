<?php
// modules/reports/inventory.php
declare(strict_types=1);

require_once dirname(dirname(__DIR__)) . '/includes/bootstrap.php';
require_once dirname(dirname(__DIR__)) . '/includes/auth.php';
require_once dirname(dirname(__DIR__)) . '/includes/helpers.php';
require_once dirname(dirname(__DIR__)) . '/includes/rbac.php';

if (function_exists('require_admin_login')) require_admin_login();
require_permission('products.view');

$db = $GLOBALS['db'] ?? null;
$BASE_URL = $GLOBALS['BASE_URL'] ?? '';

$page_title = 'Inventory Report';
$page_subtitle = 'Products stock by location';

// filters
$q = trim((string)($_GET['q'] ?? ''));
$locationId = (int)($_GET['location_id'] ?? 0);
$categoryId = (int)($_GET['category_id'] ?? 0);
$lowOnly = (int)($_GET['low'] ?? 0) === 1;
$page = max(1, (int)($_GET['page'] ?? 1));
$limit = 50;
$offset = ($page - 1) * $limit;

if (!$db instanceof mysqli) {
  http_response_code(500);
  die('DB not available');
}

$where = '1=1';
$types = '';
$params = [];

if ($q !== '') {
  $where .= ' AND (p.name LIKE ? OR p.sku LIKE ?)';
  $like = "%{$q}%";
  $types .= 'ss';
  $params[] = $like; $params[] = $like;
}
if ($categoryId > 0) { $where .= ' AND p.category_id = ?'; $types .= 'i'; $params[] = $categoryId; }
if ($lowOnly) { $where .= ' AND COALESCE(s.qty_base,0) <= COALESCE(s.low_level_base,p.low_level_base)'; }
if ($locationId > 0) { $where .= ' AND s.location_id = ?'; $types .= 'i'; $params[] = $locationId; }

// count
$cntSql = "SELECT COUNT(*) AS cnt
           FROM products p
           LEFT JOIN stock_by_location s ON s.product_id = p.id" .
           ($locationId>0 ? " AND s.location_id = $locationId" : "") .
           "
           WHERE $where";

$stc = $db->prepare($cntSql);
if ($types !== '') $stc->bind_param($types, ...$params);
$stc->execute();
$total = (int)($stc->get_result()->fetch_assoc()['cnt'] ?? 0);
$stc->close();

$pages = max(1, (int)ceil($total / $limit));

$sql = "SELECT p.id,p.sku,p.name,p.unit_type,p.unit_name,p.pieces_per_box,
                COALESCE(s.qty_base,0) AS qty_base, COALESCE(s.low_level_base,p.low_level_base) AS low_level_base,
                p.is_active,p.cost_price,p.wholesale_price,p.retail_price, c.name AS category_name,
                COALESCE(l.name,'—') AS location_name, l.low_alert_qty, l.low_alert_type
         FROM products p
         LEFT JOIN product_categories c ON c.id = p.category_id
         LEFT JOIN stock_by_location s ON s.product_id = p.id" . ($locationId>0 ? " AND s.location_id = $locationId" : "") . "
         LEFT JOIN locations l ON l.id = s.location_id
         WHERE $where
         ORDER BY p.name ASC
         LIMIT ? OFFSET ?";

$st = $db->prepare($sql);
$bindTypes = $types . 'ii';
$bind = $params; $bind[] = $limit; $bind[] = $offset;
$st->bind_param($bindTypes, ...$bind);
$st->execute();
$res = $st->get_result();
$rows = [];
while ($r = $res->fetch_assoc()) {
  $r['stock_display'] = format_stock($r);
  // low calculation similar to stock_levels
  $isLow = false;
  if ((float)$r['qty_base'] > 0) {
    $alertQty = (float)($r['low_alert_qty'] ?? $r['low_level_base'] ?? 0);
    $alertType = $r['low_alert_type'] ?? null;
    $factor = 1;
    if ($alertType === 'cartons') $factor = max(1,(int)($r['pieces_per_box'] ?? 1));
    elseif ($alertType === 'dozens') $factor = 12;
    elseif ($alertType === 'pairs') $factor = 2;
    $isLow = ((float)$r['qty_base'] <= ($alertQty * $factor));
  }
  $r['is_low'] = $isLow ? 1 : 0;
  $rows[] = $r;
}
$st->close();

// CSV export
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
  header('Content-Type: text/csv');
  header('Content-Disposition: attachment; filename="inventory_report.csv"');
  $out = fopen('php://output','w');
  fputcsv($out, ['ID','SKU','Product','Category','Location','Stock (display)','Base Qty','Low Level','Status','Wholesale','Retail']);
  foreach ($rows as $r) {
    fputcsv($out, [
      $r['id'],$r['sku'],$r['name'],$r['category_name'],$r['location_name'],$r['stock_display'],$r['qty_base'],$r['low_level_base'],((int)$r['is_active']? 'Active':'Disabled'),$r['wholesale_price'],$r['retail_price']
    ]);
  }
  fclose($out);
  exit;
}

require_once dirname(dirname(__DIR__)) . '/templates/layout/header.php';
?>
<div class="app-shell">
  <?php require_once dirname(dirname(__DIR__)) . '/templates/layout/sidebar.php'; ?>

  <div class="app-content">
    <?php require_once dirname(dirname(__DIR__)) . '/templates/layout/topbar.php'; ?>

    <main class="page-wrap">
      <div class="container-fluid py-3">

        <div class="d-flex justify-content-between align-items-center mb-3">
          <form class="d-flex gap-2" method="get">
            <input name="q" class="form-control" style="min-width:260px" value="<?= h($q) ?>" placeholder="Search name or SKU...">

            <select name="category_id" class="form-select" style="max-width:220px">
              <option value="">All Categories</option>
              <?php
                $cres = $db->query("SELECT id,name FROM product_categories ORDER BY name");
                while ($c = $cres->fetch_assoc()) echo '<option value="' . h($c['id']) . '"' . ($categoryId == $c['id'] ? ' selected' : '') . '>' . h($c['name']) . '</option>';
              ?>
            </select>

            <select name="location_id" class="form-select" style="max-width:220px">
              <option value="">All Locations</option>
              <?php
                $lres = $db->query("SELECT id,name FROM locations WHERE is_active=1 ORDER BY name");
                while ($l = $lres->fetch_assoc()) echo '<option value="' . h($l['id']) . '"' . ($locationId == $l['id'] ? ' selected' : '') . '>' . h($l['name']) . '</option>';
              ?>
            </select>

            <label class="d-flex align-items-center gap-2 mb-0">
              <input type="checkbox" name="low" value="1" <?= $lowOnly ? 'checked' : '' ?>> Low stock only
            </label>

            <button class="btn btn-primary">Filter</button>
            <a class="btn btn-outline-secondary" href="?">Reset</a>
          </form>

          <div>
            <a class="btn btn-outline-secondary" href="?<?= h(http_build_query(array_merge($_GET, ['export'=>'csv']))) ?>">Export CSV</a>
          </div>
        </div>

        <div class="card shadow-sm">
          <div class="card-body">
            <div class="table-responsive">
              <table class="table table-sm align-middle">
                <thead class="table-light">
                  <tr>
                    <th>SKU</th>
                    <th>Product</th>
                    <th>Category</th>
                    <th>Location</th>
                    <th>Stock</th>
                    <th class="text-end">Base Qty</th>
                    <th class="text-end">Low Level</th>
                    <th>Status</th>
                    <th class="text-end">Prices</th>
                  </tr>
                </thead>
                <tbody>
                  <?php if (!$rows): ?>
                    <tr><td colspan="9" class="text-center text-muted">No products found.</td></tr>
                  <?php endif; ?>

                  <?php foreach ($rows as $p): ?>
                    <?php
                      $lowBadge = $p['is_low'] ? '<span class="badge bg-danger">LOW</span>' : '<span class="badge bg-success">OK</span>';
                      $unitLabel = $p['unit_type'];
                      if ($p['unit_type'] === 'units') $unitLabel .= ' (' . h((string)($p['unit_name'] ?? '')) . ')';
                      elseif ($p['unit_type'] === 'boxes') $unitLabel .= ' • ' . (int)($p['pieces_per_box'] ?? 0) . ' pcs/box';
                      $activeBadge = ((int)$p['is_active'] === 1) ? '<span class="badge bg-success">Active</span>' : '<span class="badge bg-secondary">Disabled</span>';
                      $prices = 'W: ' . number_format((float)$p['wholesale_price'], 0) . ' • R: ' . number_format((float)$p['retail_price'], 0);
                    ?>
                    <tr class="<?= $p['is_low'] ? 'table-danger' : '' ?>">
                      <td><?= h((string)$p['sku']) ?></td>
                      <td class="fw-semibold"><?= h((string)$p['name']) ?></td>
                      <td><?= h((string)($p['category_name'] ?? '')) ?></td>
                      <td><?= h((string)($p['location_name'] ?? '—')) ?></td>
                      <td>
                        <?= h((string)$p['stock_display']) ?>
                        <div class="small text-muted"><?= $lowBadge ?></div>
                      </td>
                      <td class="text-end"><?= h((string)rtrim(rtrim(number_format((float)$p['qty_base'],2,'.',''), '0'), '.')) ?></td>
                      <td class="text-end"><?= h((string)rtrim(rtrim(number_format((float)$p['low_level_base'],2,'.',''), '0'), '.')) ?></td>
                      <td><?= $activeBadge ?></td>
                      <td class="text-end small"><?= h($prices) ?></td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>

            <div class="d-flex justify-content-between align-items-center mt-3">
              <div class="small text-muted">Showing <?= count($rows) ?> of <?= $total ?></div>
              <nav>
                <ul class="pagination pagination-sm mb-0">
                  <li class="page-item <?= $page<=1 ? 'disabled' : '' ?>">
                    <a class="page-link" href="?<?= h(http_build_query(array_merge($_GET, ['page'=>max(1,$page-1)]))) ?>">Prev</a>
                  </li>
                  <?php for ($p = max(1,$page-2); $p <= min($pages,$page+2); $p++): ?>
                    <li class="page-item <?= $p === $page ? 'active' : '' ?>">
                      <a class="page-link" href="?<?= h(http_build_query(array_merge($_GET, ['page'=>$p]))) ?>"><?=$p?></a>
                    </li>
                  <?php endfor; ?>
                  <li class="page-item <?= $page>=$pages ? 'disabled' : '' ?>">
                    <a class="page-link" href="?<?= h(http_build_query(array_merge($_GET, ['page'=>min($pages,$page+1)]))) ?>">Next</a>
                  </li>
                </ul>
              </nav>
            </div>

          </div>
        </div>

      </div>
    </main>
  </div>
</div>

<?php require_once dirname(dirname(__DIR__)) . '/templates/layout/footer.php';
