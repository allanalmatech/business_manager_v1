<?php
declare(strict_types=1);
require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/rbac.php';
require_once __DIR__ . '/../../includes/helpers.php';

require_permission('finance.create');

$db = $GLOBALS['db'];
$baseUrl = $GLOBALS['BASE_URL'] ?? '';

$page_title = 'Record Expense';
$page_subtitle = 'Add business expense and track spending';

$message = '';
$error = '';

// Check if required tables exist
$hasFinance = false;
$res = $db->query("SHOW TABLES LIKE 'finance'");
if ($res && $res->num_rows > 0) {
    $hasFinance = true;
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf = trim((string)($_POST['csrf'] ?? ''));
    if (empty($_SESSION['csrf']) || !hash_equals($_SESSION['csrf'], $csrf)) {
        $error = 'CSRF token invalid';
    } else {
        $amount = (float)($_POST['amount'] ?? 0);
        $category = trim((string)($_POST['category'] ?? ''));
        $method = trim((string)($_POST['method'] ?? ''));
        $reference = trim((string)($_POST['reference'] ?? ''));
        $notes = trim((string)($_POST['notes'] ?? ''));
        $vendor = trim((string)($_POST['vendor'] ?? ''));
        $date = trim((string)($_POST['date'] ?? ''));
        
        if ($date === '') $date = date('Y-m-d');
        // Normalize/validate date
        $dt = DateTime::createFromFormat('Y-m-d', $date);
        if (!$dt) $dt = new DateTime(); // fallback today
        $expenseDate = $dt->format('Y-m-d');

        if ($amount <= 0) {
            $error = 'Amount must be greater than zero';
        } elseif ($category === '') {
            $error = 'Expense category is required';
        } elseif ($method === '') {
            $error = 'Payment method is required';
        } else {
            try {
                $db->begin_transaction();

                $user_id = (int)($_SESSION['user']['id'] ?? 0);

                // Insert into finance table
                if ($hasFinance) {
                    $type = 'OUT';
                    $st = $db->prepare("INSERT INTO finance 
                        (user_id, type, amount, method, reference, notes, created_at) 
                        VALUES (?, ?, ?, ?, ?, ?, NOW())");
                    if (!$st) throw new Exception('Prepare failed: ' . $db->error);
                    $st->bind_param('isdsss', $user_id, $type, $amount, $method, $reference, $notes);
                    $st->execute();
                    $financeId = $st->insert_id;
                    $st->close();

                    // Log to audit
                    if (function_exists('audit_log')) {
                        audit_log('finance.create', 'expense', (string)$financeId, "Expense: $category $amount via $method");
                    }
                } else {
                    throw new Exception('Finance table not found');
                }

                $db->commit();
                $message = 'Expense recorded successfully';
                $_POST = []; // Clear form
            } catch (Exception $e) {
                $db->rollback();
                $error = 'Error: ' . $e->getMessage();
            }
        }
    }
}

if (empty($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(32));
}
$csrf = $_SESSION['csrf'];

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
            <a href="<?= $GLOBALS['BASE_URL'] ?>/modules/reports/expenses.php" class="btn btn-outline-secondary">
              <i class="bi bi-list-ul"></i> View Expenses
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

        <?php if (!$hasFinance): ?>
          <div class="alert alert-warning">
            <div class="d-flex align-items-center">
              <i class="bi bi-exclamation-triangle-fill me-3" style="font-size: 1.2rem;"></i>
              <div>
                <strong>Finance table not found.</strong> Please create it first using the schema in the admin panel.
              </div>
            </div>
          </div>
        <?php else: ?>

          <div class="row">
            <div class="col-lg-8 mx-auto">
              <div class="card shadow-sm">
                <div class="card-header bg-primary text-white">
                  <h5 class="mb-0">
                    <i class="bi bi-receipt"></i> New Expense Entry
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
                        <small class="form-text">Enter the expense amount in <?= h(get_currency_code($db)) ?></small>
                      </div>
                      <div class="col-md-6">
                        <label for="category" class="form-label">Category *</label>
                        <select id="category" name="category" class="form-select" required>
                          <option value="">-- Select category --</option>
                          <option value="office_supplies" <?= ($_POST['category'] ?? '') === 'office_supplies' ? 'selected' : '' ?>>📎 Office Supplies</option>
                          <option value="utilities" <?= ($_POST['category'] ?? '') === 'utilities' ? 'selected' : '' ?>>💡 Utilities</option>
                          <option value="rent" <?= ($_POST['category'] ?? '') === 'rent' ? 'selected' : '' ?>>🏠 Rent/Lease</option>
                          <option value="salaries" <?= ($_POST['category'] ?? '') === 'salaries' ? 'selected' : '' ?>>💰 Salaries</option>
                          <option value="marketing" <?= ($_POST['category'] ?? '') === 'marketing' ? 'selected' : '' ?>>📢 Marketing</option>
                          <option value="travel" <?= ($_POST['category'] ?? '') === 'travel' ? 'selected' : '' ?>>✈️ Travel</option>
                          <option value="maintenance" <?= ($_POST['category'] ?? '') === 'maintenance' ? 'selected' : '' ?>>🔧 Maintenance</option>
                          <option value="insurance" <?= ($_POST['category'] ?? '') === 'insurance' ? 'selected' : '' ?>>🛡️ Insurance</option>
                          <option value="taxes" <?= ($_POST['category'] ?? '') === 'taxes' ? 'selected' : '' ?>>📋 Taxes</option>
                          <option value="other" <?= ($_POST['category'] ?? '') === 'other' ? 'selected' : '' ?>>📦 Other</option>
                        </select>
                        <small class="form-text">Select the expense category</small>
                      </div>
                    </div>

                    <div class="row g-3 mt-1">
                      <div class="col-md-6">
                        <label for="method" class="form-label">Payment Method *</label>
                        <select id="method" name="method" class="form-select" required>
                          <option value="">-- Select method --</option>
                          <option value="cash" <?= ($_POST['method'] ?? '') === 'cash' ? 'selected' : '' ?>>💵 Cash</option>
                          <option value="bank_transfer" <?= ($_POST['method'] ?? '') === 'bank_transfer' ? 'selected' : '' ?>>🏦 Bank Transfer</option>
                          <option value="cheque" <?= ($_POST['method'] ?? '') === 'cheque' ? 'selected' : '' ?>>📄 Cheque</option>
                          <option value="mobile_money" <?= ($_POST['method'] ?? '') === 'mobile_money' ? 'selected' : '' ?>>📱 Mobile Money</option>
                          <option value="credit_card" <?= ($_POST['method'] ?? '') === 'credit_card' ? 'selected' : '' ?>>💳 Credit Card</option>
                          <option value="other" <?= ($_POST['method'] ?? '') === 'other' ? 'selected' : '' ?>>🔄 Other</option>
                        </select>
                        <small class="form-text">How the expense was paid</small>
                      </div>
                      <div class="col-md-6">
                        <label for="date" class="form-label">Expense Date</label>
                        <input type="date" id="date" name="date" class="form-control"
                               value="<?= h((string)($_POST['date'] ?? date('Y-m-d'))) ?>">
                        <small class="form-text">Date of the expense</small>
                      </div>
                    </div>

                    <div class="row g-3 mt-1">
                      <div class="col-md-6">
                        <label for="reference" class="form-label">Reference</label>
                        <input type="text" id="reference" name="reference" class="form-control"
                               placeholder="Invoice # or Receipt #"
                               value="<?= h((string)($_POST['reference'] ?? '')) ?>">
                        <small class="form-text">Optional reference number</small>
                      </div>
                      <div class="col-md-6">
                        <label for="vendor" class="form-label">Vendor</label>
                        <input type="text" id="vendor" name="vendor" class="form-control"
                               placeholder="Vendor or company name"
                               value="<?= h((string)($_POST['vendor'] ?? '')) ?>">
                        <small class="form-text">Optional vendor name</small>
                      </div>
                    </div>

                    <div class="row g-3 mt-1">
                      <div class="col-12">
                        <label for="notes" class="form-label">Notes</label>
                        <textarea id="notes" name="notes" class="form-control" rows="3"
                                  placeholder="Description of expense, vendor name, etc."><?= h((string)($_POST['notes'] ?? '')) ?></textarea>
                        <small class="form-text">Optional notes about this expense</small>
                      </div>
                    </div>

                    <div class="d-flex gap-2 mt-3">
                      <button type="submit" class="btn btn-primary">
                        <i class="bi bi-receipt"></i> Record Expense
                      </button>
                      <a href="<?= $GLOBALS['BASE_URL'] ?>/modules/reports/expenses.php" class="btn btn-outline-secondary">
                        <i class="bi bi-x-circle"></i> Cancel
                      </a>
                    </div>
                  </form>
                </div>
              </div>
            </div>
          </div>

          <!-- Expense Statistics -->
          <div class="row mt-4">
            <div class="col-lg-8 mx-auto">
              <div class="card shadow-sm">
                <div class="card-header bg-light">
                  <h6 class="mb-0"><i class="bi bi-graph-up"></i> Expense Statistics (Last 30 Days)</h6>
                </div>
                <div class="card-body">
                  <?php
                  $statsRes = $db->query("SELECT 
                    COUNT(*) AS total_expenses,
                    SUM(amount) AS total_amount,
                    AVG(amount) AS avg_amount,
                    MAX(amount) AS max_amount
                    FROM finance WHERE type='OUT' AND created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)");
                  
                  if ($statsRes && $statsRow = $statsRes->fetch_assoc()):
                  ?>
                    <div class="row g-3">
                      <div class="col-md-3">
                        <div class="text-center">
                          <div class="text-primary mb-2">
                            <i class="bi bi-cash-stack" style="font-size: 2rem;"></i>
                          </div>
                          <div class="small text-muted">Total Expenses</div>
                          <div class="fw-bold text-primary"><?= h(format_currency((float)($statsRow['total_amount'] ?? 0), $db)) ?></div>
                        </div>
                      </div>
                      <div class="col-md-3">
                        <div class="text-center">
                          <div class="text-info mb-2">
                            <i class="bi bi-receipt" style="font-size: 2rem;"></i>
                          </div>
                          <div class="small text-muted">Transactions</div>
                          <div class="fw-bold text-info"><?= h((string)($statsRow['total_expenses'] ?? 0)) ?></div>
                        </div>
                      </div>
                      <div class="col-md-3">
                        <div class="text-center">
                          <div class="text-success mb-2">
                            <i class="bi bi-bar-chart" style="font-size: 2rem;"></i>
                          </div>
                          <div class="small text-muted">Average</div>
                          <div class="fw-bold text-success"><?= h(format_currency((float)($statsRow['avg_amount'] ?? 0), $db)) ?></div>
                        </div>
                      </div>
                      <div class="col-md-3">
                        <div class="text-center">
                          <div class="text-danger mb-2">
                            <i class="bi bi-arrow-up-circle" style="font-size: 2rem;"></i>
                          </div>
                          <div class="small text-muted">Largest</div>
                          <div class="fw-bold text-danger"><?= h(format_currency((float)($statsRow['max_amount'] ?? 0), $db)) ?></div>
                        </div>
                      </div>
                    </div>
                  <?php else: ?>
                    <div class="text-center text-muted p-4">
                      <i class="bi bi-inbox" style="font-size: 2rem;"></i>
                      <div class="fw-semibold">No expense data available</div>
                    </div>
                  <?php endif; ?>
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
