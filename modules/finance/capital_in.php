<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/rbac.php';
require_once __DIR__ . '/../../includes/helpers.php';

require_permission('finance.create');

$db = $GLOBALS['db'];
$baseUrl = $GLOBALS['BASE_URL'] ?? '';

$page_title = 'Deposit / Capital In';
$page_subtitle = 'Record incoming funds and deposits';

$message = '';
$error = '';

// Ensure session
if (session_status() !== PHP_SESSION_ACTIVE) {
  session_start();
}

// Check if tables exist
function table_exists(mysqli $db, string $table): bool {
  $t = $db->real_escape_string($table);
  $res = $db->query("SHOW TABLES LIKE '{$t}'");
  return ($res && $res->num_rows > 0);
}

$hasFinance         = table_exists($db, 'finance');
$hasBankAccounts    = table_exists($db, 'bank_accounts');
$hasBankTransactions= table_exists($db, 'bank_transactions');

// CSRF init
if (empty($_SESSION['csrf'])) {
  $_SESSION['csrf'] = bin2hex(random_bytes(32));
}
$csrf = $_SESSION['csrf'];

// Get list of bank accounts
$bankAccounts = [];
if ($hasBankAccounts) {
  $res = $db->query("SELECT id, bank_name, account_name, account_number FROM bank_accounts WHERE is_active=1 ORDER BY bank_name, account_name");
  if ($res) {
    while ($row = $res->fetch_assoc()) $bankAccounts[] = $row;
  }
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $postedCsrf = trim((string)($_POST['csrf'] ?? ''));
  if (!hash_equals($_SESSION['csrf'], $postedCsrf)) {
    $error = 'CSRF token invalid';
  } else {

    $amount    = (float)($_POST['amount'] ?? 0);
    $method    = trim((string)($_POST['method'] ?? ''));
    $reference = trim((string)($_POST['reference'] ?? ''));
    $notes     = trim((string)($_POST['notes'] ?? ''));
    $account_id= isset($_POST['account_id']) && $_POST['account_id'] !== '' ? (int)$_POST['account_id'] : null;

    $date = trim((string)($_POST['date'] ?? ''));
    if ($date === '') $date = date('Y-m-d');
    // Normalize/validate date
    $dt = DateTime::createFromFormat('Y-m-d', $date);
    if (!$dt) $dt = new DateTime(); // fallback today
    $txDate = $dt->format('Y-m-d');

    // server-side rules
    $needsBank = in_array($method, ['bank_transfer', 'cheque'], true);

    if ($amount <= 0) {
      $error = 'Amount must be greater than zero';
    } elseif ($method === '') {
      $error = 'Payment method is required';
    } elseif ($needsBank && (!$hasBankAccounts || !$account_id)) {
      $error = 'Please select a bank account for Bank Transfer or Cheque.';
    } else {
      try {
        $db->begin_transaction();

        $user_id = (int)($_SESSION['user']['id'] ?? 0);
        $type = 'IN';

        // Insert into finance table (if exists)
        if ($hasFinance) {
          $st = $db->prepare("
            INSERT INTO finance (user_id, type, amount, method, reference, notes, created_at)
            VALUES (?, ?, ?, ?, ?, ?, NOW())
          ");
          if (!$st) throw new Exception('Prepare failed (finance): ' . $db->error);

          // i s d s s s
          $st->bind_param('isdsss', $user_id, $type, $amount, $method, $reference, $notes);
          $st->execute();
          $st->close();
        }

        // Insert into bank_transactions + update bank_accounts balance (if tables exist)
        if ($hasBankAccounts && $hasBankTransactions && $account_id) {
          $desc = trim($method . ': ' . $notes);

          $st = $db->prepare("
            INSERT INTO bank_transactions
              (account_id, transaction_date, type, amount, reference, description, reconciled, created_at)
            VALUES
              (?, ?, 'credit', ?, ?, ?, 0, NOW())
          ");
          if (!$st) throw new Exception('Prepare failed (bank_transactions): ' . $db->error);

          // i s d s s
          $st->bind_param('isdss', $account_id, $txDate, $amount, $reference, $desc);
          $st->execute();
          $st->close();

          // Update account balance
          $st = $db->prepare("UPDATE bank_accounts SET balance = balance + ? WHERE id = ? LIMIT 1");
          if (!$st) throw new Exception('Prepare failed (bank_accounts update): ' . $db->error);
          $st->bind_param('di', $amount, $account_id);
          $st->execute();
          $st->close();
        }

        $db->commit();
        $message = 'Capital deposit recorded successfully';
        $_POST = []; // Clear form

      } catch (Exception $e) {
        $db->rollback();
        $error = 'Error: ' . $e->getMessage();
      }
    }
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
            <a href="<?= h($baseUrl) ?>/modules/finance/banking.php" class="btn btn-outline-secondary">
              <i class="bi bi-arrow-left"></i> Back to Banking
            </a>
          </div>
        </div>

        <?php if ($message): ?>
          <div class="alert alert-success alert-dismissible fade show" role="alert">
            <div class="d-flex align-items-center">
              <i class="bi bi-check-circle-fill me-3" style="font-size: 1.2rem;"></i>
              <div>
                <?= h($message) ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
              </div>
            </div>
          </div>
        <?php endif; ?>

        <?php if ($error): ?>
          <div class="alert alert-danger alert-dismissible fade show" role="alert">
            <div class="d-flex align-items-center">
              <i class="bi bi-exclamation-triangle-fill me-3" style="font-size: 1.2rem;"></i>
              <div>
                <?= h($error) ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
              </div>
            </div>
          </div>
        <?php endif; ?>

        <div class="row">
          <div class="col-md-8 mx-auto">
            <div class="card shadow-sm">
              <div class="card-header bg-success text-white">
                <h5 class="mb-0">
                  <i class="bi bi-plus-circle"></i> Record Capital Deposit
                </h5>
              </div>
              <div class="card-body">
                <form method="post" class="needs-validation" novalidate>
                  <input type="hidden" name="csrf" value="<?= h($csrf) ?>">

                  <div class="row g-3">
                    <div class="col-md-6">
                      <label for="amount" class="form-label">Amount *</label>
                      <div class="input-group">
                        <span class="input-group-text"><?= h(get_currency_symbol($db)) ?></span>
                        <input type="number" id="amount" name="amount" class="form-control"
                               placeholder="0.00" step="0.01" min="0"
                               value="<?= h((string)($_POST['amount'] ?? '')) ?>" required>
                      </div>
                      <small class="form-text">Enter the deposit amount in <?= h(get_currency_code($db)) ?></small>
                    </div>
                    <div class="col-md-6">
                      <label for="method" class="form-label">Payment Method *</label>
                      <select id="method" name="method" class="form-select" required>
                        <option value="">-- Select method --</option>
                        <option value="cash" <?= ($_POST['method'] ?? '') === 'cash' ? 'selected' : '' ?>>Cash</option>
                        <option value="bank_transfer" <?= ($_POST['method'] ?? '') === 'bank_transfer' ? 'selected' : '' ?>>Bank Transfer</option>
                        <option value="cheque" <?= ($_POST['method'] ?? '') === 'cheque' ? 'selected' : '' ?>>Cheque</option>
                        <option value="mobile_money" <?= ($_POST['method'] ?? '') === 'mobile_money' ? 'selected' : '' ?>>Mobile Money</option>
                      </select>
                      <small class="form-text">How the funds were received</small>
                    </div>
                  </div>

                  <?php if ($hasBankAccounts && count($bankAccounts) > 0): ?>
                    <div class="row g-3 mt-1" id="bankAccountRow" hidden aria-hidden="true">
                      <div class="col-12">
                        <label for="account_id" class="form-label">Bank Account *</label>
                        <select id="account_id" name="account_id" class="form-select">
                          <option value="">-- Select account --</option>
                          <?php foreach ($bankAccounts as $acc): ?>
                            <option value="<?= (int)$acc['id'] ?>" <?= ($_POST['account_id'] ?? '') === (string)$acc['id'] ? 'selected' : '' ?>>
                              <?= h($acc['bank_name'] . ' - ' . $acc['account_number']) ?>
                            </option>
                          <?php endforeach; ?>
                        </select>
                        <small class="form-text">Select the bank account for this transaction</small>
                        <div class="invalid-feedback">Please select a bank account for this payment method.</div>
                      </div>
                    </div>
                  <?php endif; ?>

                  <div class="row g-3 mt-1">
                    <div class="col-md-6">
                      <label for="reference" class="form-label">Reference</label>
                      <input type="text" id="reference" name="reference" class="form-control"
                             placeholder="Transaction reference"
                             value="<?= h((string)($_POST['reference'] ?? '')) ?>">
                      <small class="form-text">Optional reference number</small>
                    </div>
                    <div class="col-md-6">
                      <label for="date" class="form-label">Transaction Date</label>
                      <input type="date" id="date" name="date" class="form-control"
                             value="<?= h((string)($_POST['date'] ?? date('Y-m-d'))) ?>">
                      <small class="form-text">Date of transaction</small>
                    </div>
                  </div>

                  <div class="row g-3 mt-1">
                    <div class="col-12">
                      <label for="notes" class="form-label">Notes</label>
                      <textarea id="notes" name="notes" class="form-control" rows="3"
                                placeholder="Additional notes about this deposit"><?= h((string)($_POST['notes'] ?? '')) ?></textarea>
                      <small class="form-text">Optional notes about this deposit</small>
                    </div>
                  </div>

                  <div class="d-flex gap-2 mt-3">
                    <button type="submit" class="btn btn-success">
                      <i class="bi bi-check-circle"></i> Record Deposit
                    </button>
                    <a href="<?= h($baseUrl) ?>/modules/finance/banking.php" class="btn btn-outline-secondary">
                      <i class="bi bi-x-circle"></i> Cancel
                    </a>
                  </div>
                </form>
              </div>
            </div>
          </div>
        </div>

        <!-- Recent Deposits -->
        <?php if ($hasBankTransactions): ?>
          <div class="card shadow-sm mt-4">
            <div class="card-header bg-light">
              <h6 class="mb-0"><i class="bi bi-clock-history"></i> Recent Deposits</h6>
            </div>
            <div class="card-body p-0">
              <div class="table-responsive">
                <table class="table table-sm table-hover align-middle mb-0">
                  <thead class="table-light">
                    <tr>
                      <th width="15%">Amount</th>
                      <th width="15%">Method</th>
                      <th width="20%">Reference</th>
                      <th width="20%">Account</th>
                      <th width="15%">Date</th>
                      <th width="15%">Status</th>
                    </tr>
                  </thead>
                  <tbody>
                    <?php
                      $recentSql = "
                        SELECT bt.*, ba.account_name, ba.account_number, ba.bank_name
                        FROM bank_transactions bt
                        LEFT JOIN bank_accounts ba ON ba.id = bt.account_id
                        WHERE bt.type = 'credit'
                        ORDER BY bt.transaction_date DESC, bt.created_at DESC
                        LIMIT 5
                      ";
                      $recent = $db->query($recentSql);
                    ?>

                    <?php if ($recent && $recent->num_rows > 0): ?>
                      <?php while ($row = $recent->fetch_assoc()): ?>
                        <tr>
                          <td class="fw-semibold text-success">
                            <?= h(format_currency((float)$row['amount'], $db)) ?>
                          </td>
                          <td>
                            <?php
                              $method = (string)($row['method'] ?? '');
                              $methodIcons = [
                                'cash' => '💵',
                                'bank_transfer' => '🏦',
                                'cheque' => '📄',
                                'mobile_money' => '📱'
                              ];
                            ?>
                            <span class="badge bg-primary">
                              <?= $methodIcons[$method] ?? '💰' ?>
                              <?= h($method !== '' ? ucwords(str_replace('_',' ', $method)) : 'Deposit') ?>
                            </span>
                          </td>
                          <td><code class="small"><?= h($row['reference'] ?? '-') ?></code></td>
                          <td>
                            <div class="small">
                              <div class="fw-semibold"><?= h($row['account_name'] ?? 'N/A') ?></div>
                              <div class="text-muted"><?= h($row['bank_name'] ?? '') ?> <?= h($row['account_number'] ?? '') ?></div>
                            </div>
                          </td>
                          <td>
                            <small class="text-muted">
                              <?php
                                $d = $row['transaction_date'] ?? ($row['created_at'] ?? null);
                                echo $d ? h(date('M j, Y', strtotime((string)$d))) : '-';
                              ?>
                            </small>
                          </td>
                          <td>
                            <?php if (!empty($row['reconciled'])): ?>
                              <span class="badge bg-success"><i class="bi bi-check-circle"></i> Reconciled</span>
                            <?php else: ?>
                              <span class="badge bg-warning"><i class="bi bi-clock"></i> Pending</span>
                            <?php endif; ?>
                          </td>
                        </tr>
                      <?php endwhile; ?>
                    <?php else: ?>
                      <tr>
                        <td colspan="6" class="text-center text-muted p-4">
                          <i class="bi bi-inbox" style="font-size: 2rem;"></i>
                          <div class="fw-semibold">No recent deposits found</div>
                        </td>
                      </tr>
                    <?php endif; ?>

                  </tbody>
                </table>
              </div>
            </div>
          </div>
        <?php endif; ?>

      </div>
    </main>
  </div>
</div>

<script>
(() => {
  "use strict";

  const METHODS_REQUIRING_BANK = new Set(["bank_transfer", "cheque"]);

  const $ = (sel, root = document) => root.querySelector(sel);
  const on = (el, evt, fn, opts) => el && el.addEventListener(evt, fn, opts);

  document.addEventListener("DOMContentLoaded", () => {
    const methodSelect   = $("#method");
    const bankAccountRow = $("#bankAccountRow");
    const accountSelect  = $("#account_id");
    const form           = $("form.needs-validation");

    if (!methodSelect || !bankAccountRow || !accountSelect) return;

    const bankRequired = () => METHODS_REQUIRING_BANK.has(methodSelect.value);

    function setBankRowVisible(visible) {
      bankAccountRow.hidden = !visible;
      bankAccountRow.setAttribute("aria-hidden", String(!visible));

      accountSelect.disabled = !visible;
      accountSelect.required = visible;

      if (!visible) {
        accountSelect.value = "";
        accountSelect.setCustomValidity("");
        accountSelect.classList.remove("is-invalid");
      }
    }

    function validateBankSelection() {
      if (!bankRequired()) {
        accountSelect.setCustomValidity("");
        accountSelect.classList.remove("is-invalid");
        return true;
      }

      const ok = Boolean(accountSelect.value);
      accountSelect.setCustomValidity(ok ? "" : "Bank account is required");
      accountSelect.classList.toggle("is-invalid", !ok);
      return ok;
    }

    setBankRowVisible(bankRequired());
    validateBankSelection();

    on(methodSelect, "change", () => {
      setBankRowVisible(bankRequired());
      validateBankSelection();
    });

    on(accountSelect, "change", () => validateBankSelection());

    if (form) {
      on(form, "submit", (e) => {
        const ok = validateBankSelection();
        if (!ok || !form.checkValidity()) {
          e.preventDefault();
          e.stopPropagation();
        }
        form.classList.add("was-validated");
      });
    }
  });
})();
</script>

<?php require_once __DIR__ . '/../../templates/layout/footer.php'; ?>