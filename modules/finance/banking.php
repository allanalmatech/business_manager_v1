<?php
declare(strict_types=1);
require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/rbac.php';
require_once __DIR__ . '/../../includes/helpers.php';

require_permission('finance.view');

$db = $GLOBALS['db'];

$page_title = 'Banking';
$page_subtitle = 'Manage bank accounts and reconciliation';

$q = trim((string)($_GET['q'] ?? ''));
$from = trim((string)($_GET['from'] ?? ''));
$to = trim((string)($_GET['to'] ?? ''));
$bank = trim((string)($_GET['bank'] ?? ''));
$status = trim((string)($_GET['status'] ?? ''));
$export = isset($_GET['export']) && $_GET['export'] === 'csv';

$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 50;
$offset = ($page - 1) * $perPage;

// Check if bank_accounts table exists
$hasBankAccounts = false;
$res = $db->query("SHOW TABLES LIKE 'bank_accounts'");
if ($res && $res->num_rows > 0) {
    $hasBankAccounts = true;
}

// Check if bank_transactions table exists
$hasBankTransactions = false;
$res = $db->query("SHOW TABLES LIKE 'bank_transactions'");
if ($res && $res->num_rows > 0) {
    $hasBankTransactions = true;
}

// Build WHERE clause
$where = [];
$types = '';
$params = [];

if ($q !== '') {
    $where[] = "(bt.reference LIKE CONCAT('%',?,'%') OR bt.description LIKE CONCAT('%',?,'%'))";
    $types .= 'ss';
    $params[] = $q;
    $params[] = $q;
}

if ($from !== '') {
    $where[] = 'bt.transaction_date >= ?';
    $types .= 's';
    $params[] = $from . ' 00:00:00';
}

if ($to !== '') {
    $where[] = 'bt.transaction_date <= ?';
    $types .= 's';
    $params[] = $to . ' 23:59:59';
}

if ($bank !== '') {
    $where[] = 'bt.account_id = ?';
    $types .= 'i';
    $params[] = (int)$bank;
}

if ($status !== '') {
    if ($status === 'reconciled') {
        $where[] = 'bt.reconciled = 1';
    } elseif ($status === 'pending') {
        $where[] = 'bt.reconciled = 0';
    }
}

$whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

// Pagination count
$countSql = "SELECT COUNT(*) AS cnt FROM bank_transactions bt $whereSql";
$st = $db->prepare($countSql);
if ($types !== '') {
    $st->bind_param($types, ...$params);
}
$st->execute();
$res = $st->get_result();
$total = (int)($res->fetch_assoc()['cnt'] ?? 0);
$st->close();

// Main select: bank transactions
$selectSql = "SELECT bt.id, bt.account_id, ba.account_name, ba.account_number, ba.bank_name, 
    bt.transaction_date, bt.type, bt.amount, bt.reference, bt.description, 
    bt.reconciled, bt.created_at
    FROM bank_transactions bt
    LEFT JOIN bank_accounts ba ON ba.id = bt.account_id
    $whereSql
    ORDER BY bt.transaction_date DESC
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
$totalsSql = "SELECT 
    IFNULL(SUM(CASE WHEN bt.type='debit' THEN bt.amount ELSE 0 END),0) AS total_debits,
    IFNULL(SUM(CASE WHEN bt.type='credit' THEN bt.amount ELSE 0 END),0) AS total_credits,
    COUNT(*) AS transaction_count,
    IFNULL(SUM(bt.reconciled),0) AS reconciled_count
    FROM bank_transactions bt
    $whereSql";
$st = $db->prepare($totalsSql);
if ($types !== '') {
    $st->bind_param($types, ...$params);
}
$st->execute();
$res = $st->get_result()->fetch_assoc();
$totals = [
    'debits' => (float)($res['total_debits'] ?? 0),
    'credits' => (float)($res['total_credits'] ?? 0),
    'net' => (float)(($res['total_credits'] ?? 0) - ($res['total_debits'] ?? 0)),
    'count' => (int)($res['transaction_count'] ?? 0),
    'reconciled' => (int)($res['reconciled_count'] ?? 0),
];
$st->close();

// Account summary
$accountsSummary = [];
if ($hasBankAccounts) {
    $acctRes = $db->query("SELECT id, account_name, account_number, bank_name, current_balance FROM bank_accounts ORDER BY bank_name, account_name");
    if ($acctRes) {
        while ($acc = $acctRes->fetch_assoc()) {
            $accountsSummary[] = $acc;
        }
    }
}

if ($export) {
    // Stream CSV
    $csvSql = "SELECT bt.id, bt.account_id, ba.account_name, ba.account_number, ba.bank_name,
        bt.transaction_date, bt.type, bt.amount, bt.reference, bt.description,
        bt.reconciled ? 'Yes' : 'No',
        FROM bank_transactions bt
        LEFT JOIN bank_accounts ba ON ba.id = bt.account_id
        $whereSql
        ORDER BY bt.transaction_date DESC";
    $st = $db->prepare($csvSql);
    if ($types !== '') $st->bind_param($types, ...$params);
    $st->execute();
    $res = $st->get_result();

    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="banking_report.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['ID', 'Account', 'Account Number', 'Bank', 'Date', 'Type', 'Amount', 'Reference', 'Description', 'Reconciled']);
    while ($r = $res->fetch_assoc()) {
        fputcsv($out, [
            $r['id'],
            $r['account_name'],
            $r['account_number'],
            $r['bank_name'],
            $r['reference'],
            $r['description'],
            $r['reconciled'] ? 'Yes' : 'No',
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
      <div class="container-fluid py-3">
        <div class="d-flex justify-content-between align-items-center mb-4">
          <div>
            <h4 class="mb-1"><?= h($page_title) ?></h4>
            <div class="text-muted small"><?= h($page_subtitle) ?></div>
          </div>
          <div>
            <a href="<?= $GLOBALS['BASE_URL'] ?>/modules/finance/capital_in.php" class="btn btn-outline-primary me-2">
              <i class="bi bi-plus-circle"></i> Deposit
            </a>
            <a href="<?= $GLOBALS['BASE_URL'] ?>/modules/finance/capital_out.php" class="btn btn-outline-danger me-2">
              <i class="bi bi-dash-circle"></i> Withdrawal
            </a>
            <a class="btn btn-outline-secondary" href="?<?= h(http_build_query(array_merge($_GET, ['export'=>'csv']))) ?>">
              <i class="bi bi-download"></i> Export CSV
            </a>
          </div>
        </div>

        <?php if (!$hasBankAccounts || !$hasBankTransactions): ?>
          <div class="alert alert-warning">
            <div class="d-flex align-items-center">
              <i class="bi bi-exclamation-triangle-fill me-3" style="font-size: 1.5rem;"></i>
              <div>
                <strong>Banking tables not found.</strong> Please create the required tables first.
                <div class="mt-2">
                  <a href="javascript:void(0)" class="btn btn-sm btn-outline-primary" onclick="showSql()">
                    <i class="bi bi-code-slash"></i> Show SQL
                  </a>
                </div>
              </div>
            </div>
            <div id="sqlSection" class="mt-3" style="display: none;">
              <pre class="small bg-light p-3 border rounded"><?= h(file_get_contents(__DIR__ . '/../../sql/create_banking_tables.sql')) ?></pre>
            </div>
          </div>
        <?php endif; ?>

        <?php if ($hasBankAccounts && count($accountsSummary) > 0): ?>
          <!-- Account Summary Cards -->
          <div class="row mb-4">
            <?php foreach ($accountsSummary as $acc): ?>
              <div class="col-md-4 mb-3">
                <div class="card border-0 bg-light shadow-sm">
                  <div class="card-body">
                    <div class="d-flex justify-content-between align-items-start mb-2">
                      <div>
                        <div class="small text-muted fw-bold"><?= h($acc['bank_name']) ?></div>
                        <div class="fw-semibold"><?= h($acc['account_name']) ?></div>
                        <div class="small text-secondary"><?= h($acc['account_number']) ?></div>
                      </div>
                      <div class="badge bg-primary">
                        <i class="bi bi-bank"></i>
                      </div>
                    </div>
                    <div class="h4 mb-0 text-primary">
                      <?= h(number_format((float)$acc['current_balance'], 2)) ?>
                    </div>
                    <div class="small text-muted">Current Balance</div>
                  </div>
                </div>
              </div>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>

        <!-- Transaction Summary -->
        <?php if ($hasBankTransactions && $rows): ?>
          <div class="row mb-4">
            <div class="col-md-3 mb-3">
              <div class="card border-0 bg-success bg-opacity-10">
                <div class="card-body text-center">
                  <div class="fs-2 fw-bold text-success"><?= h(number_format($totals['credits'], 2)) ?></div>
                  <div class="small text-muted">Total Credits</div>
                </div>
              </div>
            </div>
            <div class="col-md-3 mb-3">
              <div class="card border-0 bg-danger bg-opacity-10">
                <div class="card-body text-center">
                  <div class="fs-2 fw-bold text-danger"><?= h(number_format($totals['debits'], 2)) ?></div>
                  <div class="small text-muted">Total Debits</div>
                </div>
              </div>
            </div>
            <div class="col-md-3 mb-3">
              <div class="card border-0 bg-primary bg-opacity-10">
                <div class="card-body text-center">
                  <div class="fs-2 fw-bold text-primary"><?= h(number_format($totals['net'], 2)) ?></div>
                  <div class="small text-muted">Net Flow</div>
                </div>
              </div>
            </div>
            <div class="col-md-3 mb-3">
              <div class="card border-0 bg-info bg-opacity-10">
                <div class="card-body text-center">
                  <div class="fs-2 fw-bold text-info"><?= h($totals['reconciled'] . ' / ' . $totals['count']) ?></div>
                  <div class="small text-muted">Reconciled</div>
                </div>
              </div>
            </div>
          </div>
        <?php endif; ?>

        <!-- Filters -->
        <div class="card shadow-sm mb-4">
          <div class="card-header bg-light">
            <h6 class="mb-0"><i class="bi bi-funnel"></i> Transaction Filters</h6>
          </div>
          <div class="card-body">
            <form method="get" class="row g-3">
              <div class="col-md-3">
                <label class="form-label small text-muted">Search</label>
                <input type="text" name="q" value="<?= h($q) ?>" class="form-control" placeholder="Search reference/description...">
              </div>
              <div class="col-md-2">
                <label class="form-label small text-muted">From Date</label>
                <input type="date" name="from" value="<?= h($from) ?>" class="form-control">
              </div>
              <div class="col-md-2">
                <label class="form-label small text-muted">To Date</label>
                <input type="date" name="to" value="<?= h($to) ?>" class="form-control">
              </div>
              <?php if ($hasBankAccounts): ?>
                <div class="col-md-2">
                  <label class="form-label small text-muted">Account</label>
                  <select name="bank" class="form-select">
                    <option value="">All accounts</option>
                    <?php foreach ($accountsSummary as $acc): ?>
                      <option value="<?= (int)$acc['id'] ?>" <?= $bank === (string)$acc['id'] ? 'selected' : '' ?>>
                        <?= h($acc['bank_name'] . ' - ' . $acc['account_number']) ?>
                      </option>
                    <?php endforeach; ?>
                  </select>
                </div>
              <?php endif; ?>
              <div class="col-md-2">
                <label class="form-label small text-muted">Status</label>
                <select name="status" class="form-select">
                  <option value="">All statuses</option>
                  <option value="reconciled" <?= $status === 'reconciled' ? 'selected' : '' ?>>Reconciled</option>
                  <option value="pending" <?= $status === 'pending' ? 'selected' : '' ?>>Pending</option>
                </select>
              </div>
              <div class="col-md-1">
                <label class="form-label small text-muted">&nbsp;</label>
                <button type="submit" class="btn btn-primary w-100">
                  <i class="bi bi-search"></i> Filter
                </button>
              </div>
            </form>
          </div>
        </div>

        <!-- Transactions Table -->
        <div class="card shadow-sm">
          <div class="card-header bg-light d-flex justify-content-between align-items-center">
            <h6 class="mb-0"><i class="bi bi-list-ul"></i> Bank Transactions</h6>
            <div class="small text-muted">
              Showing <strong><?= count($rows) ?></strong> records
            </div>
          </div>
          <div class="card-body p-0">
            <div class="table-responsive">
              <table class="table table-sm table-hover align-middle mb-0">
                <thead class="table-light">
                  <tr>
                    <th width="15%">Bank</th>
                    <th width="15%">Account</th>
                    <th width="12%">Date</th>
                    <th width="10%">Type</th>
                    <th width="15%" class="text-end">Amount</th>
                    <th width="15%">Reference</th>
                    <th width="13%">Description</th>
                    <th width="5%">Status</th>
                  </tr>
                </thead>
                <tbody>
                <?php if (!$rows): ?>
                  <tr>
                    <td colspan="8" class="text-center text-muted py-5">
                      <div class="mb-3">
                        <i class="bi bi-inbox" style="font-size: 3rem;"></i>
                      </div>
                      <div class="fw-semibold">No transactions found</div>
                      <div class="small">Try adjusting your search criteria or date range</div>
                    </td>
                  </tr>
                <?php else: foreach ($rows as $r): ?>
                  <tr>
                    <td>
                      <span class="text-muted">
                        <i class="bi bi-bank"></i> <?= h($r['bank_name'] ?? 'N/A') ?>
                      </span>
                    </td>
                    <td>
                      <div class="small">
                        <div class="fw-semibold"><?= h($r['account_name'] ?? 'N/A') ?></div>
                        <div class="text-muted"><?= h($r['account_number'] ?? '') ?></div>
                      </div>
                    </td>
                    <td>
                      <small class="text-muted">
                        <?= h(date('M j, Y', strtotime($r['transaction_date']))) ?>
                      </small>
                    </td>
                    <td>
                      <span class="badge bg-<?= $r['type'] === 'credit' ? 'success' : 'danger' ?>">
                        <i class="bi bi-arrow-<?= $r['type'] === 'credit' ? 'up' : 'down' ?>"></i>
                        <?= h(ucfirst($r['type'])) ?>
                      </span>
                    </td>
                    <td class="text-end">
                      <span class="fw-semibold <?= $r['type'] === 'credit' ? 'text-success' : 'text-danger' ?>">
                        <?= h(number_format((float)$r['amount'], 2)) ?>
                      </span>
                    </td>
                    <td>
                      <code class="small"><?= h($r['reference'] ?? '-') ?></code>
                    </td>
                    <td>
                      <small class="text-truncate d-block" title="<?= h($r['description'] ?? '') ?>">
                        <?= h(substr($r['description'] ?? '', 0, 25)) ?>
                        <?= strlen($r['description'] ?? '') > 25 ? '...' : '' ?>
                      </small>
                    </td>
                    <td>
                      <?php if ($r['reconciled']): ?>
                        <span class="badge bg-success">
                          <i class="bi bi-check-circle"></i>
                        </span>
                      <?php else: ?>
                        <span class="badge bg-warning">
                          <i class="bi bi-clock"></i>
                        </span>
                      <?php endif; ?>
                    </td>
                  </tr>
                <?php endforeach; endif; ?>
                </tbody>
              </table>
            </div>
          </div>
        </div>

        <!-- Pagination -->
        <?php if ($total > $perPage): ?>
        <div class="d-flex justify-content-between align-items-center mt-3">
          <div class="text-muted small">
            Showing <?= (($page - 1) * $perPage) + 1 ?> to <?= min($page * $perPage, $total) ?> of <?= $total ?> entries
          </div>
          <nav aria-label="Banking pagination">
            <ul class="pagination mb-0">
              <?php 
              $lastPage = max(1, (int)ceil($total / $perPage));
              $prevPage = max(1, $page - 1);
              $nextPage = min($lastPage, $page + 1);
              ?>
              
              <li class="page-item <?= $page === 1 ? 'disabled' : '' ?>">
                <a class="page-link" href="?<?= h(http_build_query(array_merge($_GET, ['page' => $prevPage]))) ?>">
                  <i class="bi bi-chevron-left"></i>
                </a>
              </li>
              
              <?php 
              $startPage = max(1, $page - 2);
              $endPage = min($lastPage, $page + 2);
              
              for ($p = $startPage; $p <= $endPage; $p++): ?>
                <li class="page-item <?= $p === $page ? 'active' : '' ?>">
                  <a class="page-link" href="?<?= h(http_build_query(array_merge($_GET, ['page' => $p]))) ?>"><?= $p ?></a>
                </li>
              <?php endfor; ?>
              
              <li class="page-item <?= $page === $lastPage ? 'disabled' : '' ?>">
                <a class="page-link" href="?<?= h(http_build_query(array_merge($_GET, ['page' => $nextPage]))) ?>">
                  <i class="bi bi-chevron-right"></i>
                </a>
              </li>
            </ul>
          </nav>
        </div>
        <?php endif; ?>

      </div>
    </main>
  </div>
</div>

<script>
function showSql() {
  const sqlSection = document.getElementById('sqlSection');
  sqlSection.style.display = sqlSection.style.display === 'none' ? 'block' : 'none';
}
</script>

<?php require_once __DIR__ . '/../../templates/layout/footer.php';
