<?php
declare(strict_types=1);
require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/helpers.php';
require_once __DIR__ . '/../../includes/rbac.php';

if (function_exists('require_admin_login')) require_admin_login();
require_permission('reports.view');

$db = $GLOBALS['db'];

$page_title = 'Profit Report';
$page_subtitle = 'Revenue, COGS and gross profit by sale';

$q = trim((string)($_GET['q'] ?? ''));
$from = trim((string)($_GET['from'] ?? ''));
$to = trim((string)($_GET['to'] ?? ''));
$location = trim((string)($_GET['location'] ?? ''));
$export = isset($_GET['export']) && $_GET['export'] === 'csv';

$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 50;
$offset = ($page - 1) * $perPage;

// Build WHERE clause
$where = [];
$types = '';
$params = [];

if ($q !== '') {
    $where[] = "(s.doc_no LIKE CONCAT('%',?,'%') OR s.doc_no LIKE CONCAT(?,'%'))";
    $types .= 'ss';
    $params[] = $q;
    $params[] = $q;
}

if ($from !== '') {
    $where[] = 's.created_at >= ?';
    $types .= 's';
    $params[] = $from . ' 00:00:00';
}

if ($to !== '') {
    $where[] = 's.created_at <= ?';
    $types .= 's';
    $params[] = $to . ' 23:59:59';
}

if ($location !== '') {
    $where[] = 's.selling_location_id = ?';
    $types .= 'i';
    $params[] = (int)$location;
}

$whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

// pagination count (distinct sales)
$countSql = "SELECT COUNT(DISTINCT s.id) AS cnt FROM sales s $whereSql";
$st = $db->prepare($countSql);
if ($types !== '') {
    $st->bind_param($types, ...$params);
}
$st->execute();
$res = $st->get_result();
$total = (int)($res->fetch_assoc()['cnt'] ?? 0);
$st->close();

// Main select: per-sale revenue and COGS
$selectSql = "SELECT s.id, s.doc_no, s.created_at, s.grand_total AS revenue,
    COALESCE(SUM(COALESCE(p.cost_price, 0) * COALESCE(NULLIF(si.qty_base, 0), si.qty_input, 0)), 0) AS cogs,
    (s.grand_total - COALESCE(SUM(COALESCE(p.cost_price, 0) * COALESCE(NULLIF(si.qty_base, 0), si.qty_input, 0)), 0)) AS gross_profit
    FROM sales s
    LEFT JOIN sale_items si ON si.sale_id = s.id
    LEFT JOIN products p ON p.id = si.product_id
    $whereSql
    GROUP BY s.id
    ORDER BY s.created_at DESC
    LIMIT ? OFFSET ?";

$st = $db->prepare($selectSql);
// bind params + limits
$bindParams = $params;
$bindTypes = $types;
$bindTypes .= 'ii';
$bindParams[] = $perPage;
$bindParams[] = $offset;
if ($bindTypes !== '') {
    // mysqli requires references for bind_param
    $refs = [];
    foreach ($bindParams as $k => $v) $refs[$k] = &$bindParams[$k];
    array_unshift($refs, $bindTypes);
    call_user_func_array([$st, 'bind_param'], $refs);
}
$st->execute();
$rows = $st->get_result()->fetch_all(MYSQLI_ASSOC);
$st->close();

// Totals across the filtered set
$totalsSql = "SELECT IFNULL(SUM(s.grand_total),0) AS revenue, COALESCE(SUM(COALESCE(p.cost_price, 0) * COALESCE(NULLIF(si.qty_base, 0), si.qty_input, 0)), 0) AS cogs
    FROM sales s
    LEFT JOIN sale_items si ON si.sale_id = s.id
    LEFT JOIN products p ON p.id = si.product_id
    $whereSql";
$st = $db->prepare($totalsSql);
if ($types !== '') {
    $st->bind_param($types, ...$params);
}
$st->execute();
$res = $st->get_result()->fetch_assoc();
$totals = [
    'revenue' => (float)($res['revenue'] ?? 0),
    'cogs' => (float)($res['cogs'] ?? 0),
    'gross_profit' => (float)(($res['revenue'] ?? 0) - ($res['cogs'] ?? 0)),
];

// Calculate pagination
$lastPage = max(1, (int)ceil($total / $perPage));

if ($export) {
    // Stream CSV of the full filtered set (no pagination)
    $csvSql = "SELECT s.id, s.doc_no, s.created_at, s.grand_total AS revenue,
        COALESCE(SUM(COALESCE(p.cost_price, 0) * COALESCE(NULLIF(si.qty_base, 0), si.qty_input, 0)), 0) AS cogs,
        (s.grand_total - COALESCE(SUM(COALESCE(p.cost_price, 0) * COALESCE(NULLIF(si.qty_base, 0), si.qty_input, 0)), 0)) AS gross_profit
        FROM sales s
        LEFT JOIN sale_items si ON si.sale_id = s.id
        LEFT JOIN products p ON p.id = si.product_id
        $whereSql
        GROUP BY s.id
        ORDER BY s.created_at DESC";
    $st = $db->prepare($csvSql);
    if ($types !== '') $st->bind_param($types, ...$params);
    $st->execute();
    $res = $st->get_result();

    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="profit_report.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Sale ID', 'Doc No', 'Date', 'Revenue', 'COGS', 'Gross Profit']);
    while ($r = $res->fetch_assoc()) {
        fputcsv($out, [$r['id'], $r['doc_no'], $r['created_at'], $r['revenue'], $r['cogs'], $r['gross_profit']]);
    }
    fclose($out);
    exit;
}

require_once __DIR__ . '/../../templates/layout/header.php';
?>

<div class="app-shell">
  <?php require_once __DIR__ . '/../../templates/layout/sidebar.php'; ?>
  <div class="app-content">
    <?php require_once __DIR__ . '/../../templates/layout/topbar.php'; ?>

    <main class="page-wrap">
      <div class="container-fluid py-3">
        <div class="d-flex justify-content-between align-items-center mb-4">
          <div>
            <h4 class="mb-1"><?= h($page_title) ?></h4>
            <div class="text-muted small"><?= h($page_subtitle) ?></div>
          </div>
          <div>
            <a class="btn btn-outline-primary" href="?<?= h(http_build_query(array_merge($_GET, ['export'=>'csv']))) ?>">
              <i class="bi bi-download"></i> Export CSV
            </a>
          </div>
        </div>

        <!-- Summary Cards -->
        <div class="row mb-4">
          <div class="col-md-4 mb-3">
            <div class="card border-0 bg-success bg-opacity-10">
              <div class="card-body text-center">
                <div class="fs-2 fw-bold text-success"><?= h(number_format($totals['revenue'],2)) ?></div>
                <div class="small text-muted">Total Revenue</div>
              </div>
            </div>
          </div>
          <div class="col-md-4 mb-3">
            <div class="card border-0 bg-danger bg-opacity-10">
              <div class="card-body text-center">
                <div class="fs-2 fw-bold text-danger"><?= h(number_format($totals['cogs'],2)) ?></div>
                <div class="small text-muted">Cost of Goods</div>
              </div>
            </div>
          </div>
          <div class="col-md-4 mb-3">
            <div class="card border-0 bg-primary bg-opacity-10">
              <div class="card-body text-center">
                <div class="fs-2 fw-bold text-primary"><?= h(number_format($totals['gross_profit'],2)) ?></div>
                <div class="small text-muted">Gross Profit</div>
              </div>
            </div>
          </div>
        </div>

        <!-- Report Filters -->
        <div class="card shadow-sm mb-4">
          <div class="card-header bg-light">
            <h6 class="mb-0"><i class="bi bi-funnel"></i> Report Filters</h6>
          </div>
          <div class="card-body">
            <form method="get" class="row g-3">
              <div class="col-md-3">
                <label class="form-label small text-muted">Search</label>
                <input type="text" name="q" value="<?= h($q) ?>" class="form-control" placeholder="Search document number...">
              </div>
              <div class="col-md-2">
                <label class="form-label small text-muted">From Date</label>
                <input type="date" name="from" value="<?= h($from) ?>" class="form-control">
              </div>
              <div class="col-md-2">
                <label class="form-label small text-muted">To Date</label>
                <input type="date" name="to" value="<?= h($to) ?>" class="form-control">
              </div>
              <div class="col-md-3">
                <label class="form-label small text-muted">Location</label>
                <select name="location" class="form-select">
                  <option value="">All locations</option>
                  <?php
                  $locs = $db->query("SELECT id, name FROM locations ORDER BY name ASC");
                  while ($l = $locs->fetch_assoc()): ?>
                    <option value="<?= (int)$l['id'] ?>" <?= $location === (string)$l['id'] ? 'selected' : '' ?>><?= h($l['name']) ?></option>
                  <?php endwhile; ?>
                </select>
              </div>
              <div class="col-md-2">
                <label class="form-label small text-muted">&nbsp;</label>
                <button type="submit" class="btn btn-primary w-100">
                  <i class="bi bi-search"></i> Filter
                </button>
              </div>
            </form>
          </div>
        </div>

        <!-- Profit Report Table -->
        <div class="card shadow-sm">
          <div class="card-header bg-light d-flex justify-content-between align-items-center">
            <h6 class="mb-0"><i class="bi bi-graph-up"></i> Profit Details</h6>
            <div class="small text-muted">
              Showing <strong><?= count($rows) ?></strong> records
            </div>
          </div>
          <div class="card-body p-0">
            <div class="table-responsive">
              <table class="table table-sm table-hover align-middle mb-0">
                <thead class="table-light">
                  <tr>
                    <th width="20%">Sale Document</th>
                    <th width="15%">Date</th>
                    <th width="20%" class="text-end">Revenue</th>
                    <th width="20%" class="text-end">COGS</th>
                    <th width="25%" class="text-end">Gross Profit</th>
                  </tr>
                </thead>
                <tbody>
                  <?php if (!$rows): ?>
                    <tr>
                      <td colspan="5" class="text-center text-muted py-5">
                        <div class="mb-3">
                          <i class="bi bi-graph-down" style="font-size: 3rem;"></i>
                        </div>
                        <div class="fw-semibold">No sales data found</div>
                        <div class="small">Try adjusting your search criteria or date range</div>
                      </td>
                    </tr>
                  <?php else: foreach ($rows as $r): ?>
                    <tr>
                      <td>
                        <a href="<?= $GLOBALS['BASE_URL'] ?>/modules/pos/sale_view.php?id=<?= (int)$r['id'] ?>" class="text-decoration-none" target="_blank">
                          <i class="bi bi-receipt"></i> <?= h($r['doc_no']) ?>
                        </a>
                      </td>
                      <td>
                        <small class="text-muted">
                          <?= date('M j, Y', strtotime($r['created_at'])) ?>
                        </small>
                      </td>
                      <td class="text-end">
                        <span class="text-success fw-semibold">
                          <?= h(number_format((float)$r['revenue'],2)) ?>
                        </span>
                      </td>
                      <td class="text-end">
                        <span class="text-danger fw-semibold">
                          <?= h(number_format((float)$r['cogs'],2)) ?>
                        </span>
                      </td>
                      <td class="text-end">
                        <span class="fw-semibold 
                          <?= (float)$r['gross_profit'] >= 0 ? 'text-success' : 'text-danger' ?>">
                          <?= h(number_format((float)$r['gross_profit'],2)) ?>
                        </span>
                      </td>
                    </tr>
                  <?php endforeach; endif; ?>
                </tbody>
              </table>
            </div>
          </div>
          <?php if (!empty($rows)): ?>
          <div class="card-footer bg-light">
            <div class="d-flex justify-content-between align-items-center">
              <div class="small text-muted">
                Page <strong><?= $page ?></strong> of <strong><?= $lastPage ?></strong>
              </div>
              <nav>
                <ul class="pagination pagination-sm mb-0">
                  <?php for ($p = 1; $p <= $lastPage; $p++): ?>
                    <li class="page-item <?= $p === $page ? 'active' : '' ?>">
                      <a class="page-link" href="?<?= h(http_build_query(array_merge($_GET, ['page'=>$p]))) ?>"><?= $p ?></a>
                    </li>
                  <?php endfor; ?>
                </ul>
              </nav>
            </div>
          </div>
          <?php endif; ?>
        </div>
      </div>
    </main>
  </div>
</div>

<?php require_once __DIR__ . '/../../templates/layout/footer.php';
