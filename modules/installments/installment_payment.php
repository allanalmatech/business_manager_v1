<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/rbac.php';
require_once __DIR__ . '/../../includes/helpers.php';

$db = $GLOBALS['db'] ?? null;
$base_url = $GLOBALS['BASE_URL'] ?? '/';

if (!($db instanceof mysqli)) {
    die('Database not available');
}

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$user_id = (int)($_SESSION['user']['id'] ?? 0);
if ($user_id <= 0) {
    header('Location: ' . $base_url . 'login.php');
    exit;
}

require_permission('installments.create');

$message = '';
$message_type = '';
$installment = null;
$payments = [];
$payment_methods = [
    'cash' => 'Cash',
    'bank_transfer' => 'Bank Transfer',
    'cheque' => 'Cheque',
    'mobile_money' => 'Mobile Money',
    'credit_card' => 'Credit Card'
];

$installment_id = (int)($_GET['id'] ?? 0);

// Handle payment recording
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] === 'record_payment') {
        $result = handle_record_payment($installment_id);
        $message = $result['message'];
        $message_type = $result['success'] ? 'success' : 'danger';
    }
}

// Check if tables exist
function table_exists(mysqli $db, string $table): bool {
    $result = $db->query("SHOW TABLES LIKE '" . $db->real_escape_string($table) . "'");
    return $result && $result->num_rows > 0;
}

$hasInstallments = table_exists($db, 'installments');
$hasContacts = table_exists($db, 'contacts');
$hasUsers = table_exists($db, 'users');

// Fetch installment details
if ($installment_id > 0 && $hasInstallments) {
    $contactSelect = $hasContacts ? ", c.name" : ", NULL AS name";
    $contactJoin = $hasContacts ? "LEFT JOIN contacts c ON i.contact_id = c.id" : "";
    
    $st = $db->prepare("
        SELECT i.id, i.contact_id $contactSelect, i.amount_due, i.amount_paid, (i.amount_due - i.amount_paid) AS remaining_balance,
               i.due_date, i.reference, i.status, i.created_at, i.updated_at
        FROM installments i
        $contactJoin
        WHERE i.id = ?
        LIMIT 1
    ");
    
    if ($st) {
        $st->bind_param('i', $installment_id);
        $st->execute();
        $installment = $st->get_result()->fetch_assoc();
        $st->close();
    }
    
    if (!$installment) {
        $message = 'Installment not found';
        $message_type = 'danger';
        $installment_id = 0;
    }
}

// Fetch payment history
if ($installment_id > 0 && $installment && $hasInstallments) {
    $userSelect = $hasUsers ? ", u.full_name as user_name" : ", NULL AS user_name";
    $userJoin = $hasUsers ? "LEFT JOIN users u ON ip.user_id = u.id" : "";
    
    $st = $db->prepare("
        SELECT ip.id, ip.amount, ip.method, ip.reference, ip.notes, ip.user_id, ip.payment_date $userSelect
        FROM installment_payments ip
        $userJoin
        WHERE ip.installment_id = ?
        ORDER BY ip.payment_date DESC
    ");
    
    if ($st) {
        $st->bind_param('i', $installment_id);
        $st->execute();
        $payments = $st->get_result()->fetch_all(MYSQLI_ASSOC);
        $st->close();
    }
}

function h2($text) {
    return htmlspecialchars($text ?? '', ENT_QUOTES, 'UTF-8');
}

function handle_record_payment(int $id): array {
    global $db, $user_id;
    
    $amount = (float)($_POST['amount'] ?? 0);
    $method = trim((string)($_POST['method'] ?? ''));
    $reference = trim((string)($_POST['reference'] ?? ''));
    $notes = trim((string)($_POST['notes'] ?? ''));
    
    if ($id <= 0 || $amount <= 0 || !$method) {
        return ['success' => false, 'message' => 'Invalid parameters'];
    }
    
    try {
        $db->begin_transaction();
        
        // Verify installment exists and get current amounts
        $check = $db->prepare("SELECT amount_due, amount_paid, status FROM installments WHERE id = ? LIMIT 1");
        if (!$check) throw new Exception('Prepare failed');
        $check->bind_param('i', $id);
        $check->execute();
        $inst = $check->get_result()->fetch_assoc();
        $check->close();
        
        if (!$inst) {
            throw new Exception('Installment not found');
        }
        
        $remaining_balance = (float)$inst['amount_due'] - (float)$inst['amount_paid'];
        
        // Check if payment exceeds remaining balance
        if ($amount > $remaining_balance) {
            throw new Exception('Payment exceeds remaining balance of ' . number_format($remaining_balance, 2));
        }
        
        $new_paid = (float)$inst['amount_paid'] + $amount;
        $new_remaining = (float)$inst['amount_due'] - $new_paid;
        
        // Record payment
        $st = $db->prepare("INSERT INTO installment_payments 
            (installment_id, amount, method, reference, notes, user_id, payment_date) 
            VALUES (?, ?, ?, ?, ?, ?, NOW())");
        if (!$st) throw new Exception('Prepare failed');
        $st->bind_param('idsssi', $id, $amount, $method, $reference, $notes, $user_id);
        $st->execute();
        $payment_id = $st->insert_id;
        $st->close();
        
        // Update installment totals
        $new_status = $new_remaining <= 0 ? 'completed' : $inst['status'];
        $st = $db->prepare("UPDATE installments SET amount_paid = ?, status = ?, updated_at = NOW() WHERE id = ? LIMIT 1");
        if (!$st) throw new Exception('Prepare failed');
        $st->bind_param('dsi', $new_paid, $new_status, $id);
        $st->execute();
        $st->close();
        
        $db->commit();
        
        if (function_exists('audit_log')) {
            audit_log('installments.payment', 'installment_payment', (string)$payment_id, "Payment recorded: $amount via $method");
        }
        
        return ['success' => true, 'message' => 'Payment recorded successfully'];
    } catch (Exception $e) {
        $db->rollback();
        return ['success' => false, 'message' => $e->getMessage()];
    }
}

$page_title = 'Record Installment Payment';
include __DIR__ . '/../../templates/layout/header.php';
?>
<div class="app-shell">
  <?php require_once __DIR__ . '/../../templates/layout/sidebar.php'; ?>
  <div class="app-content">
    <?php require_once __DIR__ . '/../../templates/layout/topbar.php'; ?>

    <main class="page-wrap">
      <div class="container-fluid py-3">
        <div class="d-flex justify-content-between align-items-center mb-4">
          <div>
            <h4 class="mb-1">Record Installment Payment</h4>
            <div class="text-muted small">Process payment for installment schedule</div>
          </div>
          <div class="gap-2 d-flex">
            <?php if ($installment_id > 0 && $installment): ?>
              <a href="<?= $base_url ?>modules/installments/installment_view.php?id=<?= $installment_id ?>" class="btn btn-outline-primary">
                <i class="bi bi-eye"></i> View Details
              </a>
            <?php endif; ?>
            <a href="<?= $base_url ?>modules/installments/installments.php" class="btn btn-outline-secondary">
              <i class="bi bi-arrow-left"></i> Back to Installments
            </a>
          </div>
        </div>

        <?php if ($message): ?>
          <div class="alert alert-<?= $message_type ?> alert-dismissible fade show" role="alert">
            <div class="d-flex align-items-center">
              <i class="bi bi-<?= $message_type === 'success' ? 'check-circle-fill' : 'exclamation-triangle-fill' ?> me-2"></i>
              <?= h2($message) ?>
            </div>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
          </div>
        <?php endif; ?>

        <?php if (!$installment || $installment_id <= 0): ?>
          <div class="card shadow-sm">
            <div class="card-body text-center py-5">
              <i class="bi bi-exclamation-triangle text-warning" style="font-size: 3rem;"></i>
              <h5 class="mt-3">No Installment Selected</h5>
              <p class="text-muted">Please select an installment to record a payment.</p>
              <a href="<?= $base_url ?>modules/installments/installments.php" class="btn btn-primary">
                <i class="bi bi-list-ul"></i> View Installments
              </a>
            </div>
          </div>
        <?php else: ?>

          <div class="row g-4">
            <!-- Installment Summary -->
            <div class="col-lg-4">
              <div class="card shadow-sm h-100">
                <div class="card-header bg-primary text-white">
                  <h6 class="mb-0">
                    <i class="bi bi-info-circle"></i> Installment Summary
                  </h6>
                </div>
                <div class="card-body">
                  <div class="mb-3">
                    <label class="form-label text-muted small">Installment ID</label>
                    <div class="fw-semibold">#<?= h2((string)$installment['id']) ?></div>
                  </div>
                  
                  <div class="mb-3">
                    <label class="form-label text-muted small">Contact</label>
                    <div class="fw-semibold"><?= h2($installment['name'] ?? 'Unknown Contact') ?></div>
                  </div>
                  
                  <div class="mb-3">
                    <label class="form-label text-muted small">Status</label>
                    <div>
                      <?php 
                      $statusColor = match($installment['status']) {
                        'completed' => 'success',
                        'active' => 'primary',
                        'overdue' => 'danger',
                        'due_soon' => 'warning',
                        'extended' => 'info',
                        default => 'secondary'
                      };
                      ?>
                      <span class="badge bg-<?= $statusColor ?>">
                        <?= h2(str_replace('_', ' ', ucfirst($installment['status']))) ?>
                      </span>
                    </div>
                  </div>
                  
                  <hr>
                  
                  <div class="row g-3">
                    <div class="col-6">
                      <label class="form-label text-muted small">Amount Due</label>
                      <div class="h5 mb-0"><?= h2(number_format((float)$installment['amount_due'], 2)) ?></div>
                    </div>
                    <div class="col-6">
                      <label class="form-label text-muted small">Paid</label>
                      <div class="h5 mb-0 text-success"><?= h2(number_format((float)$installment['amount_paid'], 2)) ?></div>
                    </div>
                    <div class="col-12">
                      <label class="form-label text-muted small">Remaining Balance</label>
                      <div class="h5 mb-0 text-danger"><?= h2(number_format((float)$installment['remaining_balance'], 2)) ?></div>
                    </div>
                  </div>
                  
                  <div class="mt-3">
                    <label class="form-label text-muted small">Payment Progress</label>
                    <?php 
                    $progress = $installment['amount_due'] > 0 ? ((float)$installment['amount_paid'] / (float)$installment['amount_due']) * 100 : 0;
                    ?>
                    <div class="progress" style="height: 8px;">
                      <div class="progress-bar bg-success" role="progressbar" style="width: <?= round($progress, 1) ?>%">
                      </div>
                    </div>
                    <small class="text-muted"><?= round($progress, 1) ?>% paid</small>
                  </div>
                  
                  <hr>
                  
                  <div class="row g-2">
                    <div class="col-12">
                      <label class="form-label text-muted small">Due Date</label>
                      <div class="small"><?= h2(substr($installment['due_date'], 0, 10)) ?></div>
                    </div>
                    <?php if (!empty($installment['reference'])): ?>
                    <div class="col-12">
                      <label class="form-label text-muted small">Reference</label>
                      <div class="small"><?= h2($installment['reference']) ?></div>
                    </div>
                    <?php endif; ?>
                  </div>
                </div>
              </div>
            </div>

            <!-- Payment Recording Form -->
            <div class="col-lg-5">
              <div class="card shadow-sm h-100">
                <div class="card-header bg-success text-white">
                  <h6 class="mb-0">
                    <i class="bi bi-cash"></i> Record Payment
                  </h6>
                </div>
                <div class="card-body">
                  <form method="POST">
                    <input type="hidden" name="action" value="record_payment">
                    
                    <div class="mb-3">
                      <label for="amount" class="form-label">Payment Amount *</label>
                      <div class="input-group">
                        <span class="input-group-text">$</span>
                        <input type="number" class="form-control" id="amount" name="amount" 
                               step="0.01" min="0.01" max="<?= $installment['remaining_balance'] ?>" 
                               placeholder="0.00" required>
                      </div>
                      <div class="form-text">
                        <small class="text-muted">Maximum amount: <?= h2(number_format((float)$installment['remaining_balance'], 2)) ?></small>
                      </div>
                    </div>
                    
                    <div class="mb-3">
                      <label for="method" class="form-label">Payment Method *</label>
                      <select class="form-select" id="method" name="method" required>
                        <option value="">-- Select Method --</option>
                        <?php foreach ($payment_methods as $key => $label): ?>
                          <option value="<?= h2($key) ?>"><?= h2($label) ?></option>
                        <?php endforeach; ?>
                      </select>
                    </div>
                    
                    <div class="mb-3">
                      <label for="reference" class="form-label">Reference / Receipt #</label>
                      <input type="text" class="form-control" id="reference" name="reference" 
                             placeholder="e.g., CHK-12345 or TXN-ABC123">
                    </div>
                    
                    <div class="mb-4">
                      <label for="notes" class="form-label">Notes / Comments</label>
                      <textarea class="form-control" id="notes" name="notes" rows="3" 
                                placeholder="Additional payment details..."></textarea>
                    </div>
                    
                    <div class="d-grid">
                      <button type="submit" class="btn btn-success btn-lg">
                        <i class="bi bi-check-circle"></i> Record Payment
                      </button>
                    </div>
                  </form>
                </div>
              </div>
            </div>

            <!-- Payment History -->
            <div class="col-lg-3">
              <div class="card shadow-sm h-100">
                <div class="card-header bg-light">
                  <h6 class="mb-0">
                    <i class="bi bi-clock-history"></i> Payment History
                    <span class="badge bg-primary rounded-pill float-end"><?= count($payments) ?></span>
                  </h6>
                </div>
                <div class="card-body" style="max-height: 500px; overflow-y: auto;">
                  <?php if (empty($payments)): ?>
                    <div class="text-center text-muted py-4">
                      <i class="bi bi-inbox" style="font-size: 2rem;"></i>
                      <div class="small mt-2">No payments recorded yet</div>
                    </div>
                  <?php else: ?>
                    <?php foreach ($payments as $payment): ?>
                      <div class="mb-3 pb-3 border-bottom">
                        <div class="d-flex justify-content-between align-items-start">
                          <div>
                            <div class="fw-semibold"><?= h2(number_format((float)$payment['amount'], 2)) ?></div>
                            <small class="text-muted">
                              <i class="bi bi-calendar"></i> <?= h2(substr($payment['payment_date'], 0, 10)) ?>
                            </small>
                          </div>
                          <span class="badge bg-success"><?= h2(ucfirst($payment['method'])) ?></span>
                        </div>
                        <?php if (!empty($payment['reference'])): ?>
                          <small class="text-muted d-block mt-2">
                            <i class="bi bi-receipt"></i> Ref: <?= h2($payment['reference']) ?>
                          </small>
                        <?php endif; ?>
                        <?php if (!empty($payment['user_name'])): ?>
                          <small class="text-muted d-block">
                            <i class="bi bi-person"></i> By: <?= h2($payment['user_name']) ?>
                          </small>
                        <?php endif; ?>
                      </div>
                    <?php endforeach; ?>
                  <?php endif; ?>
                </div>
              </div>
            </div>
          </div>

          <!-- Quick Actions -->
          <div class="card shadow-sm mt-4">
            <div class="card-header bg-light">
              <h6 class="mb-0">
                <i class="bi bi-lightning"></i> Quick Actions
              </h6>
            </div>
            <div class="card-body">
              <div class="btn-group" role="group">
                <a href="<?= $base_url ?>modules/installments/installment_view.php?id=<?= $installment_id ?>" 
                   class="btn btn-outline-primary">
                  <i class="bi bi-eye"></i> View Full Details
                </a>
                <?php if ($installment['status'] === 'overdue'): ?>
                  <a href="<?= $base_url ?>modules/installments/actions.php" 
                     class="btn btn-outline-warning">
                    <i class="bi bi-exclamation-triangle"></i> Manage Overdue
                  </a>
                <?php endif; ?>
                <a href="<?= $base_url ?>modules/installments/installments.php" 
                   class="btn btn-outline-secondary">
                  <i class="bi bi-list-ul"></i> All Installments
                </a>
                <?php if ($installment['status'] !== 'completed'): ?>
                  <a href="<?= $base_url ?>modules/installments/installment_payment.php?id=<?= $installment_id ?>" 
                     class="btn btn-outline-success">
                    <i class="bi bi-plus-circle"></i> Another Payment
                  </a>
                <?php endif; ?>
              </div>
            </div>
          </div>

        <?php endif; ?>

      </div>
    </main>
  </div>
</div>

<?php include __DIR__ . '/../../templates/layout/footer.php'; ?>
