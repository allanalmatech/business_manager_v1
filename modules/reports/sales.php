<?php
declare(strict_types=1);
require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/rbac.php';
require_once __DIR__ . '/../../includes/helpers.php';

require_permission('sales.view');

$db = $GLOBALS['db'];

$page_title = 'Sales Report';
$page_subtitle = 'View and analyze sales transactions';

$q = trim((string)($_GET['q'] ?? ''));
$from = trim((string)($_GET['from'] ?? ''));
$to = trim((string)($_GET['to'] ?? ''));
$status = trim((string)($_GET['status'] ?? ''));
$location = trim((string)($_GET['location'] ?? ''));
$export = isset($_GET['export']) && $_GET['export'] === 'csv';

$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 50;
$offset = ($page - 1) * $perPage;

// Check if contacts table exists for customer info
$hasCustomers = false;
$res = $db->query("SHOW TABLES LIKE 'contacts'");
if ($res && $res->num_rows > 0) {
    $hasCustomers = true;
}

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

if ($status !== '') {
    $where[] = 's.status = ?';
    $types .= 's';
    $params[] = $status;
}

if ($location !== '') {
    $where[] = 's.selling_location_id = ?';
    $types .= 'i';
    $params[] = (int)$location;
}

$whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

// pagination count
$countSql = "SELECT COUNT(*) AS cnt FROM sales s $whereSql";
$st = $db->prepare($countSql);
if ($types !== '') {
    $st->bind_param($types, ...$params);
}
$st->execute();
$res = $st->get_result();
$total = (int)($res->fetch_assoc()['cnt'] ?? 0);
$st->close();

// Main select
$customerSelect = $hasCustomers ? ", c.name AS customer_name" : ", NULL AS customer_name";
$customerJoin = $hasCustomers ? "LEFT JOIN contacts c ON c.id = s.customer_id" : "";

$selectSql = "SELECT s.id, s.doc_no, s.created_at, s.grand_total, s.status, s.payment_status $customerSelect
    FROM sales s
    $customerJoin
    $whereSql
    ORDER BY s.created_at DESC
    LIMIT ? OFFSET ?";

$st = $db->prepare($selectSql);
$bindParams = $params;
$bindTypes = $types;
$bindTypes .= 'ii';
$bindParams[] = $perPage;
$bindParams[] = $offset;
if ($bindTypes !== '') {
    $refs = [];
    foreach ($bindParams as $k => $v) $refs[$k] = &$bindParams[$k];
    array_unshift($refs, $bindTypes);
    call_user_func_array([$st, 'bind_param'], $refs);
}
$st->execute();
$rows = $st->get_result()->fetch_all(MYSQLI_ASSOC);
$st->close();

// Totals
$totalsSql = "SELECT COUNT(*) AS cnt, IFNULL(SUM(s.grand_total),0) AS total_revenue FROM sales s $whereSql";
$st = $db->prepare($totalsSql);
if ($types !== '') {
    $st->bind_param($types, ...$params);
}
$st->execute();
$res = $st->get_result()->fetch_assoc();
$totals = [
    'count' => (int)($res['cnt'] ?? 0),
    'revenue' => (float)($res['total_revenue'] ?? 0),
];
$st->close();

if ($export) {
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="sales_report.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Sale ID', 'Doc No', 'Date', 'Customer', 'Amount', 'Status', 'Payment Status']);
    foreach ($rows as $r) {
        fputcsv($out, [
            $r['id'],
            $r['doc_no'],
            $r['created_at'],
            $r['customer_name'] ?? 'Walk-in',
            $r['grand_total'],
            $r['status'],
            $r['payment_status'],
        ]);
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
      <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
          <h4 class="fw-bold mb-0"><?= h($page_title) ?></h4>
          <div class="text-muted small"><?= h($page_subtitle) ?></div>
        </div>
        <div class="d-flex gap-2">
          <a class="btn btn-outline-primary shadow-sm" href="?<?= h(http_build_query(array_merge($_GET, ['export'=>'csv']))) ?>">
            <i class="bi bi-download me-1"></i> Export CSV
          </a>
        </div>
      </div>

      <!-- Stats Cards -->
      <div class="row g-3 mb-4">
        <div class="col-md-4">
          <div class="card border-0 shadow-sm rounded-4 h-100">
            <div class="card-body p-4 d-flex align-items-center">
              <div class="bg-success bg-opacity-10 text-success p-3 rounded-4 me-3">
                <i class="bi bi-cart-check fs-3"></i>
              </div>
              <div>
                <div class="text-muted small fw-bold text-uppercase">Total Sales</div>
                <div class="fs-3 fw-bold"><?= h((string)$totals['count']) ?></div>
              </div>
            </div>
          </div>
        </div>
        <div class="col-md-4">
          <div class="card border-0 shadow-sm rounded-4 h-100">
            <div class="card-body p-4 d-flex align-items-center">
              <div class="bg-primary bg-opacity-10 text-primary p-3 rounded-4 me-3">
                <i class="bi bi-currency-dollar fs-3"></i>
              </div>
              <div>
                <div class="text-muted small fw-bold text-uppercase">Total Revenue</div>
                <div class="fs-3 fw-bold"><?= h(number_format($totals['revenue'], 2)) ?></div>
              </div>
            </div>
          </div>
        </div>
        <div class="col-md-4">
          <div class="card border-0 shadow-sm rounded-4 h-100">
            <div class="card-body p-4 d-flex align-items-center">
              <div class="bg-info bg-opacity-10 text-info p-3 rounded-4 me-3">
                <i class="bi bi-graph-up fs-3"></i>
              </div>
              <div>
                <div class="text-muted small fw-bold text-uppercase">Average Ticket</div>
                <div class="fs-3 fw-bold"><?= h(number_format($totals['revenue'] / max(1, $totals['count']), 2)) ?></div>
              </div>
            </div>
          </div>
        </div>
      </div>

      <!-- Filters -->
      <div class="card border-0 shadow-sm rounded-4 mb-4">
        <div class="card-body p-4">
          <form method="get" class="row g-3">
            <div class="col-md-3">
              <label class="form-label small fw-bold">Search</label>
              <input type="text" name="q" value="<?= h($q) ?>" class="form-control" placeholder="Doc number...">
            </div>
            <div class="col-md-2">
              <label class="form-label small fw-bold">From</label>
              <input type="date" name="from" value="<?= h($from) ?>" class="form-control">
            </div>
            <div class="col-md-2">
              <label class="form-label small fw-bold">To</label>
              <input type="date" name="to" value="<?= h($to) ?>" class="form-control">
            </div>
            <div class="col-md-2">
              <label class="form-label small fw-bold">Status</label>
              <select name="status" class="form-select">
                <option value="">All Status</option>
                <option value="confirmed" <?= $status === 'confirmed' ? 'selected' : '' ?>>Confirmed</option>
                <option value="draft" <?= $status === 'draft' ? 'selected' : '' ?>>Draft</option>
                <option value="voided" <?= $status === 'voided' ? 'selected' : '' ?>>Voided</option>
              </select>
            </div>
            <div class="col-md-3 d-flex align-items-end">
              <button type="submit" class="btn btn-primary w-100">
                <i class="bi bi-filter me-1"></i> Apply Filters
              </button>
              <a href="?" class="btn btn-link text-muted ms-2">Clear</a>
            </div>
          </form>
        </div>
      </div>

      <!-- Table -->
      <div class="card border-0 shadow-sm rounded-4 overflow-hidden">
        <div class="card-body p-0">
          <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
              <thead class="bg-light">
                <tr>
                  <th class="ps-4">Document</th>
                  <th>Date & Time</th>
                  <th>Customer</th>
                  <th class="text-end">Total Amount</th>
                  <th class="text-center">Status</th>
                  <th class="text-center">Payment</th>
                  <th class="text-end pe-4">Actions</th>
                </tr>
              </thead>
              <tbody>
                <?php if (!$rows): ?>
                  <tr>
                    <td colspan="7" class="text-center p-5 text-muted">
                      <i class="bi bi-search fs-1 d-block mb-3 opacity-25"></i>
                      No sales found matching your criteria.
                    </td>
                  </tr>
                <?php else: foreach ($rows as $r): ?>
                  <tr>
                    <td class="ps-4 fw-bold text-primary">
                      <i class="bi bi-receipt me-1"></i> <?= h($r['doc_no']) ?>
                    </td>
                    <td>
                      <div class="small fw-semibold"><?= date('M d, Y', strtotime($r['created_at'])) ?></div>
                      <div class="x-small text-muted"><?= date('H:i', strtotime($r['created_at'])) ?></div>
                    </td>
                    <td>
                      <div class="fw-semibold"><?= h($r['customer_name'] ?? 'Walk-in Customer') ?></div>
                    </td>
                    <td class="text-end fw-bold">
                      <?= h(number_format((float)$r['grand_total'], 2)) ?>
                    </td>
                    <td class="text-center">
                      <?php
                      $s = strtolower((string)$r['status']);
                      $badge = 'bg-secondary';
                      if ($s === 'confirmed') $badge = 'bg-success';
                      if ($s === 'voided') $badge = 'bg-danger';
                      ?>
                      <span class="badge <?= $badge ?> rounded-pill px-3"><?= ucfirst($s) ?></span>
                    </td>
                    <td class="text-center">
                      <?php
                      $ps = strtolower((string)$r['payment_status']);
                      $pbadge = 'bg-warning text-dark';
                      if ($ps === 'paid') $pbadge = 'bg-success';
                      if ($ps === 'partial') $pbadge = 'bg-info';
                      ?>
                      <span class="badge <?= $pbadge ?> rounded-pill px-3"><?= ucfirst($ps) ?></span>
                    </td>
                    <td class="text-end pe-4">
                      <a href="<?= $GLOBALS['BASE_URL'] ?>/modules/pos/sale_view.php?id=<?= (int)$r['id'] ?>" class="btn btn-sm btn-outline-secondary" target="_blank">
                        <i class="bi bi-eye"></i> View
                      </a>
                    </td>
                  </tr>
                <?php endforeach; endif; ?>
              </tbody>
            </table>
          </div>
        </div>
      </div>
    </main>
  </div>
</div>
<?php require_once __DIR__ . '/../../templates/layout/footer.php'; ?>
