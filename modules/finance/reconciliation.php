<?php
declare(strict_types=1);
require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/rbac.php';
require_once __DIR__ . '/../../includes/helpers.php';

require_permission('finance.view');

$db = $GLOBALS['db'];

$page_title = 'Bank Reconciliation';
$page_subtitle = 'Match bank statements with system records';

// Check if bank tables exist
$hasBankTables = false;
$res = $db->query("SHOW TABLES LIKE 'bank_transactions'");
if ($res && $res->num_rows > 0) {
    $hasBankTables = true;
}

// Get bank accounts
$accounts = [];
if ($hasBankTables) {
    $res = $db->query("SELECT id, bank_name, account_name, account_number, current_balance FROM bank_accounts WHERE is_active=1 ORDER BY bank_name, account_name");
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $accounts[] = $row;
        }
    }
}

$account_id = isset($_GET['account']) ? (int)$_GET['account'] : (count($accounts) > 0 ? (int)$accounts[0]['id'] : null);
$from = trim((string)($_GET['from'] ?? ''));
$to = trim((string)($_GET['to'] ?? ''));

// Get current account
$currentAccount = null;
foreach ($accounts as $acc) {
    if ($acc['id'] == $account_id) {
        $currentAccount = $acc;
        break;
    }
}

// Handle reconciliation actions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $hasBankTables) {
    $transaction_id = isset($_POST['transaction_id']) ? (int)$_POST['transaction_id'] : 0;
    $reconciled = isset($_POST['reconciled']) ? (int)$_POST['reconciled'] : 0;
    
    if ($transaction_id > 0) {
        $st = $db->prepare("UPDATE bank_transactions SET reconciled = ?, reconciliation_date = NOW() WHERE id = ? LIMIT 1");
        if ($st) {
            $st->bind_param('ii', $reconciled, $transaction_id);
            $st->execute();
            $st->close();
        }
    }
}

// Get transactions
$unreconciled = [];
$reconciled_trans = [];
$unrecRes = [];
$recRes = [];

if ($currentAccount && $hasBankTables) {
    // Build WHERE clause
    $where = "WHERE account_id = ?";
    $params = [$account_id];
    $types = "i";
    
    if ($from !== '') {
        $where .= " AND transaction_date >= ?";
        $params[] = $from;
        $types .= "s";
    }
    if ($to !== '') {
        $where .= " AND transaction_date <= ?";
        $params[] = $to;
        $types .= "s";
    }
    
    // Unreconciled transactions
    $sql = "SELECT * FROM bank_transactions $where AND reconciled = 0 ORDER BY transaction_date DESC, created_at DESC";
    $st = $db->prepare($sql);
    if ($st) {
        $st->bind_param($types, ...$params);
        $st->execute();
        $result = $st->get_result();
        while ($row = $result->fetch_assoc()) {
            $unreconciled[] = $row;
        }
        $st->close();
    }
    
    // Unreconciled totals
    $sql = "SELECT 
        SUM(CASE WHEN type = 'credit' THEN amount ELSE 0 END) AS unrec_credits,
        SUM(CASE WHEN type = 'debit' THEN amount ELSE 0 END) AS unrec_debits
        FROM bank_transactions $where AND reconciled = 0";
    $st = $db->prepare($sql);
    if ($st) {
        $st->bind_param($types, ...$params);
        $st->execute();
        $unrecRes = $st->get_result()->fetch_assoc();
        $st->close();
    }
    
    // Reconciled transactions
    $sql = "SELECT * FROM bank_transactions $where AND reconciled = 1 ORDER BY transaction_date DESC, created_at DESC LIMIT 50";
    $st = $db->prepare($sql);
    if ($st) {
        $st->bind_param($types, ...$params);
        $st->execute();
        $result = $st->get_result();
        while ($row = $result->fetch_assoc()) {
            $reconciled_trans[] = $row;
        }
        $st->close();
    }
    
    // Reconciled totals
    $sql = "SELECT 
        SUM(CASE WHEN type = 'credit' THEN amount ELSE 0 END) AS rec_credits,
        SUM(CASE WHEN type = 'debit' THEN amount ELSE 0 END) AS rec_debits
        FROM bank_transactions $where AND reconciled = 1";
    $st = $db->prepare($sql);
    if ($st) {
        $st->bind_param($types, ...$params);
        $st->execute();
        $recRes = $st->get_result()->fetch_assoc();
        $st->close();
    }
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
            <a href="<?= $GLOBALS['BASE_URL'] ?>/modules/finance/banking.php" class="btn btn-outline-secondary">
              <i class="bi bi-arrow-left"></i> Banking Dashboard
            </a>
          </div>
        </div>

        <?php if (!$hasBankTables || count($accounts) === 0): ?>
          <div class="alert alert-warning">
            <div class="d-flex align-items-center">
              <i class="bi bi-exclamation-triangle-fill me-3" style="font-size: 1.2rem;"></i>
              <div>
                <strong>No bank accounts found.</strong> Please create bank accounts first in the banking module.
              </div>
            </div>
          </div>
        <?php else: ?>

          <!-- Account Selection -->
          <div class="card shadow-sm mb-4">
            <div class="card-header bg-light">
              <h6 class="mb-0"><i class="bi bi-funnel"></i> Account Selection & Filters</h6>
            </div>
            <div class="card-body">
              <form method="get" class="row g-3">
                <div class="col-md-4">
                  <label for="account" class="form-label">Select Account</label>
                  <select name="account" class="form-select" onchange="this.form.submit()">
                    <?php foreach ($accounts as $acc): ?>
                      <option value="<?= (int)$acc['id'] ?>" <?= $account_id == $acc['id'] ? 'selected' : '' ?>>
                        <?= h($acc['bank_name'] . ' - ' . $acc['account_number']) ?>
                      </option>
                    <?php endforeach; ?>
                  </select>
                </div>
                <div class="col-md-3">
                  <label for="from" class="form-label">From Date</label>
                  <input type="date" name="from" value="<?= h($from) ?>" class="form-control" placeholder="From">
                </div>
                <div class="col-md-3">
                  <label for="to" class="form-label">To Date</label>
                  <input type="date" name="to" value="<?= h($to) ?>" class="form-control" placeholder="To">
                </div>
                <div class="col-md-2">
                  <label class="form-label">&nbsp;</label>
                  <div>
                    <button class="btn btn-primary w-100">
                      <i class="bi bi-search"></i> Filter
                    </button>
                  </div>
                </div>
              </form>
            </div>
          </div>

          <?php if ($currentAccount): ?>
            <!-- Account Summary Cards -->
            <div class="row g-3 mb-4">
              <div class="col-md-4">
                <div class="card shadow-sm">
                  <div class="card-body">
                    <div class="d-flex align-items-center">
                      <div class="flex-shrink-0">
                        <div class="bg-primary bg-opacity-10 rounded-circle p-3">
                          <i class="bi bi-bank text-primary" style="font-size: 1.5rem;"></i>
                        </div>
                      </div>
                      <div class="flex-grow-1 ms-3">
                        <div class="small text-muted">Account</div>
                        <div class="fw-bold"><?= h($currentAccount['account_name']) ?></div>
                        <div class="small text-secondary"><?= h($currentAccount['bank_name']) ?></div>
                        <div class="small text-secondary"><?= h($currentAccount['account_number']) ?></div>
                      </div>
                    </div>
                  </div>
                </div>
              </div>
              <div class="col-md-4">
                <div class="card shadow-sm">
                  <div class="card-body">
                    <div class="d-flex align-items-center">
                      <div class="flex-shrink-0">
                        <div class="bg-success bg-opacity-10 rounded-circle p-3">
                          <i class="bi bi-cash-stack text-success" style="font-size: 1.5rem;"></i>
                        </div>
                      </div>
                      <div class="flex-grow-1 ms-3">
                        <div class="small text-muted">System Balance</div>
                        <div class="h5 mb-0 text-success"><?= h(format_currency((float)$currentAccount['current_balance'], $db)) ?></div>
                      </div>
                    </div>
                  </div>
                </div>
              </div>
              <div class="col-md-4">
                <div class="card shadow-sm">
                  <div class="card-body">
                    <div class="d-flex align-items-center">
                      <div class="flex-shrink-0">
                        <div class="bg-warning bg-opacity-10 rounded-circle p-3">
                          <i class="bi bi-clock-history text-warning" style="font-size: 1.5rem;"></i>
                        </div>
                      </div>
                      <div class="flex-grow-1 ms-3">
                        <div class="small text-muted">Pending Reconciliation</div>
                        <div class="h5 mb-0 text-warning"><?= h((string)count($unreconciled)) ?> transactions</div>
                      </div>
                    </div>
                  </div>
                </div>
              </div>
            </div>
          <?php endif; ?>

          <div class="row g-4">
            <!-- Unreconciled Transactions -->
            <div class="col-lg-6">
              <div class="card shadow-sm">
                <div class="card-header bg-warning text-dark">
                  <h6 class="mb-0">
                    <i class="bi bi-exclamation-triangle"></i> Pending Reconciliation
                  </h6>
                </div>
                <div class="card-body p-0">
                  <?php if ($unreconciled && count($unreconciled) > 0): ?>
                    <div class="table-responsive">
                      <table class="table table-sm table-hover mb-0">
                        <thead class="table-light">
                          <tr>
                            <th width="100px">Date</th>
                            <th>Type</th>
                            <th class="text-end">Amount</th>
                            <th>Reference</th>
                            <th width="60px">Action</th>
                          </tr>
                        </thead>
                        <tbody>
                          <?php foreach ($unreconciled as $t): ?>
                            <tr>
                              <td><?= h(substr($t['transaction_date'], 0, 10)) ?></td>
                              <td>
                                <span class="badge bg-<?= $t['type'] === 'credit' ? 'success' : 'danger' ?>">
                                  <?= h(ucfirst($t['type'])) ?>
                                </span>
                              </td>
                              <td class="text-end fw-semibold">
                                <?= h(format_currency((float)$t['amount'], $db)) ?>
                              </td>
                              <td class="text-truncate" title="<?= h($t['reference'] ?? $t['description'] ?? '') ?>">
                                <small><?= h(substr($t['reference'] ?? $t['description'] ?? '', 0, 20)) ?></small>
                              </td>
                              <td>
                                <form method="post" style="display:inline;">
                                  <input type="hidden" name="transaction_id" value="<?= (int)$t['id'] ?>">
                                  <input type="hidden" name="reconciled" value="1">
                                  <button type="submit" class="btn btn-sm btn-outline-success" title="Mark as reconciled">
                                    <i class="bi bi-check-lg"></i>
                                  </button>
                                </form>
                              </td>
                            </tr>
                          <?php endforeach; ?>
                        </tbody>
                      </table>
                    </div>
                    <div class="card-footer bg-light">
                      <div class="row small">
                        <div class="col-6">
                          <strong>Credits:</strong> <?= h(format_currency((float)($unrecRes['unrec_credits'] ?? 0), $db)) ?>
                        </div>
                        <div class="col-6 text-end">
                          <strong>Debits:</strong> <?= h(format_currency((float)($unrecRes['unrec_debits'] ?? 0), $db)) ?>
                        </div>
                      </div>
                    </div>
                  <?php else: ?>
                    <div class="p-4 text-muted text-center">
                      <i class="bi bi-check-circle" style="font-size: 2rem;"></i>
                      <div class="fw-semibold mt-2">All transactions reconciled!</div>
                    </div>
                  <?php endif; ?>
                </div>
              </div>
            </div>

            <!-- Reconciled Transactions -->
            <div class="col-lg-6">
              <div class="card shadow-sm">
                <div class="card-header bg-success text-white">
                  <h6 class="mb-0">
                    <i class="bi bi-check-circle"></i> Reconciled Transactions
                  </h6>
                </div>
                <div class="card-body p-0">
                  <?php if ($reconciled_trans && count($reconciled_trans) > 0): ?>
                    <div class="table-responsive">
                      <table class="table table-sm table-hover mb-0">
                        <thead class="table-light">
                          <tr>
                            <th width="100px">Date</th>
                            <th>Type</th>
                            <th class="text-end">Amount</th>
                            <th>Reference</th>
                            <th width="60px">Action</th>
                          </tr>
                        </thead>
                        <tbody>
                          <?php foreach ($reconciled_trans as $t): ?>
                            <tr class="table-light">
                              <td><?= h(substr($t['transaction_date'], 0, 10)) ?></td>
                              <td>
                                <span class="badge bg-<?= $t['type'] === 'credit' ? 'success' : 'danger' ?>">
                                  <?= h(ucfirst($t['type'])) ?>
                                </span>
                              </td>
                              <td class="text-end fw-semibold">
                                <?= h(format_currency((float)$t['amount'], $db)) ?>
                              </td>
                              <td class="text-truncate" title="<?= h($t['reference'] ?? $t['description'] ?? '') ?>">
                                <small><?= h(substr($t['reference'] ?? $t['description'] ?? '', 0, 20)) ?></small>
                              </td>
                              <td>
                                <form method="post" style="display:inline;">
                                  <input type="hidden" name="transaction_id" value="<?= (int)$t['id'] ?>">
                                  <input type="hidden" name="reconciled" value="0">
                                  <button type="submit" class="btn btn-sm btn-outline-secondary" title="Undo reconciliation">
                                    <i class="bi bi-arrow-counterclockwise"></i>
                                  </button>
                                </form>
                              </td>
                            </tr>
                          <?php endforeach; ?>
                        </tbody>
                      </table>
                    </div>
                    <div class="card-footer bg-light">
                      <div class="row small">
                        <div class="col-6">
                          <strong>Credits:</strong> <?= h(format_currency((float)($recRes['rec_credits'] ?? 0), $db)) ?>
                        </div>
                        <div class="col-6 text-end">
                          <strong>Debits:</strong> <?= h(format_currency((float)($recRes['rec_debits'] ?? 0), $db)) ?>
                        </div>
                      </div>
                    </div>
                  <?php else: ?>
                    <div class="p-4 text-muted text-center">
                      <i class="bi bi-inbox" style="font-size: 2rem;"></i>
                      <div class="fw-semibold mt-2">No reconciled transactions yet.</div>
                    </div>
                  <?php endif; ?>
                </div>
              </div>
            </div>
          </div>

          <!-- Instructions -->
          <div class="row mt-4">
            <div class="col-12">
              <div class="card shadow-sm">
                <div class="card-header bg-info text-white">
                  <h6 class="mb-0">
                    <i class="bi bi-info-circle"></i> Reconciliation Instructions
                  </h6>
                </div>
                <div class="card-body">
                  <div class="row">
                    <div class="col-md-6">
                      <h6 class="text-primary">How to Reconcile:</h6>
                      <ol class="small">
                        <li><strong>Select account:</strong> Choose the bank account to reconcile</li>
                        <li><strong>Set date range:</strong> Filter transactions for the statement period</li>
                        <li><strong>Compare amounts:</strong> Match system transactions with bank statement</li>
                        <li><strong>Mark reconciled:</strong> Click ✓ to mark matched transactions</li>
                        <li><strong>Verify totals:</strong> Ensure credits and debits match statement</li>
                      </ol>
                    </div>
                    <div class="col-md-6">
                      <h6 class="text-success">Best Practices:</h6>
                      <ol class="small">
                        <li><strong>Verify amounts:</strong> Ensure credit and debit amounts match exactly</li>
                        <li><strong>Mark reconciled:</strong> Click ✓ to mark each matched transaction as reconciled</li>
                        <li><strong>Find discrepancies:</strong> Any unmatched transactions may indicate errors or timing differences</li>
                        <li><strong>Update balance:</strong> Once reconciled, the account balance should match your bank statement</li>
                        <li><strong>Regular reconciliation:</strong> Reconcile monthly for accurate financial tracking</li>
                      </ol>
                    </div>
                  </div>
                </div>
              </div>
            </div>
          </div>

        <?php endif; ?>

      </div>
    </main>
  </div>
</div>

<?php require_once __DIR__ . '/../../templates/layout/footer.php';
