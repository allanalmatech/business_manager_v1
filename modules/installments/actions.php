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

// Check if tables exist
function table_exists(mysqli $db, string $table): bool {
    $result = $db->query("SHOW TABLES LIKE '" . $db->real_escape_string($table) . "'");
    return $result && $result->num_rows > 0;
}

$hasInstallments = table_exists($db, 'installments');
$hasContacts = table_exists($db, 'contacts');

// Handler functions
function handle_record_payment(): array {
    global $db, $user_id;
    
    $installment_id = (int)($_POST['installment_id'] ?? 0);
    $amount = (float)($_POST['amount'] ?? 0);
    $method = trim((string)($_POST['method'] ?? ''));
    $reference = trim((string)($_POST['reference'] ?? ''));
    $notes = trim((string)($_POST['notes'] ?? ''));
    
    if ($installment_id <= 0 || $amount <= 0 || !$method) {
        return ['success' => false, 'message' => 'Invalid parameters'];
    }
    
    try {
        $db->begin_transaction();
        
        // Verify installment is overdue
        $check = $db->prepare("SELECT amount_due, amount_paid, status FROM installments WHERE id = ? AND status = 'overdue' LIMIT 1");
        if (!$check) throw new Exception('Prepare failed');
        $check->bind_param('i', $installment_id);
        $check->execute();
        $inst = $check->get_result()->fetch_assoc();
        $check->close();
        
        if (!$inst) {
            throw new Exception('Installment not found or not overdue');
        }
        
        $new_paid = (float)$inst['amount_paid'] + $amount;
        $remaining = (float)$inst['amount_due'] - $new_paid;
        
        // Record payment
        $st = $db->prepare("INSERT INTO installment_payments 
            (installment_id, amount, method, reference, notes, user_id, payment_date) 
            VALUES (?, ?, ?, ?, ?, ?, NOW())");
        if (!$st) throw new Exception('Prepare failed');
        $st->bind_param('idsssi', $installment_id, $amount, $method, $reference, $notes, $user_id);
        $st->execute();
        $st->close();
        
        // Update installment totals and status
        $new_status = $remaining <= 0 ? 'completed' : 'active';
        $st = $db->prepare("UPDATE installments SET amount_paid = ?, status = ?, updated_at = NOW() WHERE id = ? LIMIT 1");
        if (!$st) throw new Exception('Prepare failed');
        $st->bind_param('dsi', $new_paid, $new_status, $installment_id);
        $st->execute();
        $st->close();
        
        $db->commit();
        
        if (function_exists('audit_log')) {
            audit_log('installments.overdue_payment', 'installment', (string)$installment_id, "Payment recorded: $amount via $method");
        }
        
        return ['success' => true, 'message' => 'Payment recorded successfully'];
    } catch (Exception $e) {
        $db->rollback();
        return ['success' => false, 'message' => $e->getMessage()];
    }
}

function handle_apply_fee(): array {
    global $db, $user_id;
    
    $installment_id = (int)($_POST['installment_id'] ?? 0);
    $fee_amount = (float)($_POST['fee_amount'] ?? 0);
    $fee_type = trim((string)($_POST['fee_type'] ?? 'fixed'));
    $description = trim((string)($_POST['description'] ?? 'Late Fee'));
    
    if ($installment_id <= 0 || $fee_amount <= 0) {
        return ['success' => false, 'message' => 'Invalid parameters'];
    }
    
    try {
        $db->begin_transaction();
        
        // Get installment details
        $check = $db->prepare("SELECT amount_due, (amount_due - amount_paid) AS remaining_balance, contact_id FROM installments WHERE id = ? AND status = 'overdue' LIMIT 1");
        if (!$check) throw new Exception('Prepare failed');
        $check->bind_param('i', $installment_id);
        $check->execute();
        $inst = $check->get_result()->fetch_assoc();
        $check->close();
        
        if (!$inst) {
            throw new Exception('Installment not found or not overdue');
        }
        
        // Calculate actual fee
        $actual_fee = $fee_type === 'percentage' 
            ? ((float)$inst['remaining_balance'] * $fee_amount) / 100
            : $fee_amount;
        
        // Add fee to total amount due
        $new_total = (float)$inst['amount_due'] + $actual_fee;
        
        // Update installment
        $st = $db->prepare("UPDATE installments SET amount_due = ?, updated_at = NOW() WHERE id = ? LIMIT 1");
        if (!$st) throw new Exception('Prepare failed');
        $st->bind_param('di', $new_total, $installment_id);
        $st->execute();
        $st->close();
        
        $db->commit();
        
        if (function_exists('audit_log')) {
            audit_log('installments.late_fee', 'installment', (string)$installment_id, "Late fee applied: " . number_format($actual_fee, 2));
        }
        
        return ['success' => true, 'message' => 'Late fee applied: ' . number_format($actual_fee, 2)];
    } catch (Exception $e) {
        $db->rollback();
        return ['success' => false, 'message' => $e->getMessage()];
    }
}

function handle_extend_date(): array {
    global $db, $user_id;
    
    $id = (int)($_POST['id'] ?? 0);
    $new_due_date = trim((string)($_POST['new_due_date'] ?? ''));
    $reason = trim((string)($_POST['reason'] ?? ''));
    
    if ($id <= 0 || !$new_due_date || !$reason) {
        return ['success' => false, 'message' => 'Invalid parameters'];
    }
    
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $new_due_date)) {
        return ['success' => false, 'message' => 'Invalid date format'];
    }
    
    if (strtotime($new_due_date) <= time()) {
        return ['success' => false, 'message' => 'Extension date must be in the future'];
    }
    
    $st = $db->prepare("UPDATE installments SET due_date = ?, status = 'extended', updated_at = NOW() WHERE id = ? AND status = 'overdue' LIMIT 1");
    if (!$st) {
        return ['success' => false, 'message' => 'Database error'];
    }
    
    $st->bind_param('si', $new_due_date, $id);
    if ($st->execute() && $st->affected_rows > 0) {
        if (function_exists('audit_log')) {
            audit_log('installments.extend', 'installment', (string)$id, "Extended to: $new_due_date. Reason: $reason");
        }
        return ['success' => true, 'message' => 'Due date extended to ' . $new_due_date];
    } else {
        return ['success' => false, 'message' => 'Installment not found or not overdue'];
    }
    $st->close();
}

function handle_send_reminder(): array {
    global $db, $user_id;
    
    $id = (int)($_POST['id'] ?? 0);
    $channel = trim((string)($_POST['channel'] ?? 'email'));
    
    if ($id <= 0 || !in_array($channel, ['email', 'sms', 'both'])) {
        return ['success' => false, 'message' => 'Invalid parameters'];
    }
    
    try {
        if (function_exists('audit_log')) {
            audit_log('installments.reminder', 'installment', (string)$id, "Reminder queued via $channel");
        }
        
        return ['success' => true, 'message' => "Reminder queued via $channel"];
    } catch (Exception $e) {
        return ['success' => false, 'message' => $e->getMessage()];
    }
}

function handle_mark_writeoff(): array {
    global $db, $user_id;
    
    $id = (int)($_POST['id'] ?? 0);
    $reason = trim((string)($_POST['reason'] ?? ''));
    
    if ($id <= 0 || !$reason) {
        return ['success' => false, 'message' => 'Invalid parameters'];
    }
    
    try {
        $db->begin_transaction();
        
        $st = $db->prepare("UPDATE installments SET status = 'writeoff', updated_at = NOW() WHERE id = ? AND status = 'overdue' LIMIT 1");
        if (!$st) throw new Exception('Prepare failed');
        $st->bind_param('i', $id);
        $st->execute();
        
        if ($st->affected_rows === 0) {
            throw new Exception('Installment not found or not overdue');
        }
        $st->close();
        
        $db->commit();
        
        if (function_exists('audit_log')) {
            audit_log('installments.writeoff', 'installment', (string)$id, "Write-off reason: $reason");
        }
        
        return ['success' => true, 'message' => 'Installment marked as write-off'];
    } catch (Exception $e) {
        $db->rollback();
        return ['success' => false, 'message' => $e->getMessage()];
    }
}

if ($user_id <= 0) {
    header('Location: ' . $base_url . 'login.php');
    exit;
}

require_permission('installments.update');

$action = trim((string)($_POST['action'] ?? ''));
$message = '';
$message_type = '';

// Handle form submissions
if ($action) {
    if ($action === 'record_payment') {
        $result = handle_record_payment();
        $message = $result['message'];
        $message_type = $result['success'] ? 'success' : 'danger';
    } elseif ($action === 'apply_fee') {
        $result = handle_apply_fee();
        $message = $result['message'];
        $message_type = $result['success'] ? 'success' : 'danger';
    } elseif ($action === 'extend_date') {
        $result = handle_extend_date();
        $message = $result['message'];
        $message_type = $result['success'] ? 'success' : 'danger';
    } elseif ($action === 'send_reminder') {
        $result = handle_send_reminder();
        $message = $result['message'];
        $message_type = $result['success'] ? 'success' : 'danger';
    } elseif ($action === 'mark_writeoff') {
        $result = handle_mark_writeoff();
        $message = $result['message'];
        $message_type = $result['success'] ? 'success' : 'danger';
    }
}

// Fetch overdue installments
$overdue = [];
if ($hasInstallments) {
    $contactSelect = $hasContacts ? ", c.name" : ", NULL AS name";
    $contactJoin = $hasContacts ? "LEFT JOIN contacts c ON i.contact_id = c.id" : "";
    
    $st = $db->prepare("
        SELECT i.id, i.contact_id $contactSelect, i.amount_due, i.amount_paid, (i.amount_due - i.amount_paid) AS remaining_balance, 
               i.due_date, i.reference, i.status, i.created_at
        FROM installments i
        $contactJoin
        WHERE i.status = 'overdue'
    ORDER BY i.due_date ASC
");

    if ($st) {
        $st->execute();
        $overdue = $st->get_result()->fetch_all(MYSQLI_ASSOC);
        $st->close();
    }
}

function h2($text) {
    return htmlspecialchars($text ?? '', ENT_QUOTES, 'UTF-8');
}

$page_title = 'Overdue Installments - Actions';
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
            <h4 class="mb-1">Overdue Installments Management</h4>
            <div class="text-muted small">Take action on overdue installment payments</div>
          </div>
          <a href="<?= $GLOBALS['BASE_URL'] ?>/modules/installments/installments.php" class="btn btn-outline-secondary">
            <i class="bi bi-arrow-left"></i> Back to Installments
          </a>
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

        <?php if (empty($overdue)): ?>
          <div class="card shadow-sm">
            <div class="card-body text-center py-5">
              <i class="bi bi-check-circle text-success" style="font-size: 3rem;"></i>
              <h5 class="mt-3 text-success">No Overdue Installments</h5>
              <p class="text-muted">Great! All installments are currently up to date.</p>
              <a href="<?= $GLOBALS['BASE_URL'] ?>/modules/installments/installments.php" class="btn btn-primary">
                <i class="bi bi-list-ul"></i> View All Installments
              </a>
            </div>
          </div>
        <?php else: ?>

          <!-- Summary Card -->
          <div class="card shadow-sm mb-4">
            <div class="card-header bg-danger text-white">
              <h6 class="mb-0">
                <i class="bi bi-exclamation-triangle"></i> Overdue Installments Summary
              </h6>
            </div>
            <div class="card-body">
              <div class="row align-items-center">
                <div class="col-md-3">
                  <div class="text-center">
                    <div class="bg-danger bg-opacity-10 rounded-circle p-3 d-inline-block">
                      <i class="bi bi-calendar-x text-danger" style="font-size: 2rem;"></i>
                    </div>
                    <div class="mt-2">
                      <div class="h4 text-danger mb-0"><?= count($overdue) ?></div>
                      <div class="small text-muted">Overdue Items</div>
                    </div>
                  </div>
                </div>
                <div class="col-md-9">
                  <div class="alert alert-warning mb-0">
                    <h6 class="alert-heading"><i class="bi bi-info-circle"></i> Action Required</h6>
                    <p class="mb-2">The following installments are overdue and require immediate attention. Please take appropriate action:</p>
                    <ul class="mb-0 small">
                      <li><strong>Record Payment:</strong> If payment has been received</li>
                      <li><strong>Apply Late Fee:</strong> Add penalty charges for delayed payment</li>
                      <li><strong>Extend Due Date:</strong> Grant additional time for payment</li>
                      <li><strong>Send Reminder:</strong> Notify customers about overdue payment</li>
                      <li><strong>Mark as Write-off:</strong> For uncollectible amounts</li>
                    </ul>
                  </div>
                </div>
              </div>
            </div>
          </div>

          <!-- Overdue Installments Table -->
          <div class="card shadow-sm">
            <div class="card-header bg-light">
              <h6 class="mb-0">
                <i class="bi bi-list-check"></i> Overdue Installments Requiring Action
              </h6>
            </div>
            <div class="card-body p-0">
              <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                  <thead class="table-light">
                    <tr>
                      <th>ID</th>
                      <th>Contact</th>
                      <th class="text-end">Amount Due</th>
                      <th class="text-end">Paid</th>
                      <th class="text-end">Remaining</th>
                      <th>Due Date</th>
                      <th>Reference</th>
                      <th>Days Overdue</th>
                      <th width="280">Actions</th>
                    </tr>
                  </thead>
                  <tbody>
                    <?php foreach ($overdue as $inst): 
                      $days_overdue = (int)((time() - strtotime($inst['due_date'])) / (60 * 60 * 24));
                      $overdue_severity = $days_overdue > 30 ? 'danger' : ($days_overdue > 14 ? 'warning' : 'info');
                    ?>
                      <tr>
                        <td><strong><?= h2((string)$inst['id']) ?></strong></td>
                        <td>
                          <div class="fw-semibold"><?= h2($inst['name'] ?? 'Unknown Contact') ?></div>
                        </td>
                        <td class="text-end fw-semibold"><?= h2(number_format((float)$inst['amount_due'], 2)) ?></td>
                        <td class="text-end">
                          <div class="d-flex align-items-center justify-content-end">
                            <small class="text-success me-2"><?= h2(number_format((float)$inst['amount_paid'], 2)) ?></small>
                            <div class="progress" style="height: 6px; width: 60px;">
                              <?php 
                                $progress_pct = $inst['amount_due'] > 0 ? (int)((float)$inst['amount_paid'] / (float)$inst['amount_due'] * 100) : 0;
                              ?>
                              <div class="progress-bar bg-success" style="width: <?= $progress_pct ?>%"></div>
                            </div>
                          </div>
                        </td>
                        <td class="text-end text-danger fw-semibold"><?= h2(number_format((float)$inst['remaining_balance'], 2)) ?></td>
                        <td>
                          <div class="text-danger fw-semibold"><?= h2(substr($inst['due_date'], 0, 10)) ?></div>
                          <small class="text-muted"><?= $days_overdue ?> days overdue</small>
                        </td>
                        <td><small><?= h2($inst['reference'] ?? '') ?></small></td>
                        <td>
                          <span class="badge bg-<?= $overdue_severity ?>">
                            <?= $days_overdue ?> days
                          </span>
                        </td>
                        <td>
                          <div class="btn-group-vertical btn-group-sm w-100" role="group">
                            <!-- Record Payment Button -->
                            <button type="button" class="btn btn-success btn-sm w-100 mb-1" data-bs-toggle="modal" data-bs-target="#paymentModal<?= $inst['id'] ?>">
                              <i class="bi bi-cash"></i> Record Payment
                            </button>
                            
                            <!-- Action Dropdown -->
                            <div class="btn-group btn-group-sm w-100" role="group">
                              <button type="button" class="btn btn-outline-warning dropdown-toggle" data-bs-toggle="dropdown">
                                <i class="bi bi-three-dots"></i> More Actions
                              </button>
                              <ul class="dropdown-menu">
                                <li>
                                  <button type="button" class="dropdown-item" data-bs-toggle="modal" data-bs-target="#feeModal<?= $inst['id'] ?>">
                                    <i class="bi bi-plus-circle text-warning"></i> Apply Late Fee
                                  </button>
                                </li>
                                <li>
                                  <button type="button" class="dropdown-item" data-bs-toggle="modal" data-bs-target="#extendModal<?= $inst['id'] ?>">
                                    <i class="bi bi-calendar-plus text-info"></i> Extend Due Date
                                  </button>
                                </li>
                                <li>
                                  <button type="button" class="dropdown-item" data-bs-toggle="modal" data-bs-target="#reminderModal<?= $inst['id'] ?>">
                                    <i class="bi bi-envelope text-primary"></i> Send Reminder
                                  </button>
                                </li>
                                <li><hr class="dropdown-divider"></li>
                                <li>
                                  <button type="button" class="dropdown-item text-danger" data-bs-toggle="modal" data-bs-target="#writeoffModal<?= $inst['id'] ?>">
                                    <i class="bi bi-x-circle"></i> Mark as Write-off
                                  </button>
                                </li>
                              </ul>
                            </div>
                          </div>
                        </td>
                      </tr>

                      <!-- Payment Modal -->
                      <div class="modal fade" id="paymentModal<?= $inst['id'] ?>" tabindex="-1">
                        <div class="modal-dialog">
                          <div class="modal-content">
                            <div class="modal-header">
                              <h5 class="modal-title">
                                <i class="bi bi-cash"></i> Record Payment - Installment #<?= $inst['id'] ?>
                              </h5>
                              <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                            </div>
                            <form method="post">
                              <div class="modal-body">
                                <input type="hidden" name="action" value="record_payment">
                                <input type="hidden" name="installment_id" value="<?= $inst['id'] ?>">
                                
                                <div class="row g-3">
                                  <div class="col-md-6">
                                    <label class="form-label">Amount Due</label>
                                    <input type="text" class="form-control" value="<?= number_format((float)$inst['amount_due'], 2) ?>" readonly>
                                  </div>
                                  <div class="col-md-6">
                                    <label class="form-label">Remaining Balance</label>
                                    <input type="text" class="form-control" value="<?= number_format((float)$inst['remaining_balance'], 2) ?>" readonly>
                                  </div>
                                  <div class="col-md-6">
                                    <label class="form-label">Payment Amount *</label>
                                    <input type="number" name="amount" class="form-control" step="0.01" min="0.01" max="<?= $inst['remaining_balance'] ?>" required>
                                  </div>
                                  <div class="col-md-6">
                                    <label class="form-label">Payment Method *</label>
                                    <select name="method" class="form-select" required>
                                      <option value="">Select Method</option>
                                      <option value="cash">Cash</option>
                                      <option value="bank_transfer">Bank Transfer</option>
                                      <option value="cheque">Cheque</option>
                                      <option value="mobile_money">Mobile Money</option>
                                      <option value="credit_card">Credit Card</option>
                                    </select>
                                  </div>
                                  <div class="col-12">
                                    <label class="form-label">Reference</label>
                                    <input type="text" name="reference" class="form-control" placeholder="Transaction reference">
                                  </div>
                                  <div class="col-12">
                                    <label class="form-label">Notes</label>
                                    <textarea name="notes" class="form-control" rows="2" placeholder="Payment notes"></textarea>
                                  </div>
                                </div>
                              </div>
                              <div class="modal-footer">
                                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                                <button type="submit" class="btn btn-success">
                                  <i class="bi bi-check-circle"></i> Record Payment
                                </button>
                              </div>
                            </form>
                          </div>
                        </div>
                      </div>

                      <!-- Fee Modal -->
                      <div class="modal fade" id="feeModal<?= $inst['id'] ?>" tabindex="-1">
                        <div class="modal-dialog">
                          <div class="modal-content">
                            <div class="modal-header">
                              <h5 class="modal-title">
                                <i class="bi bi-plus-circle"></i> Apply Late Fee - Installment #<?= $inst['id'] ?>
                              </h5>
                              <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                            </div>
                            <form method="post">
                              <div class="modal-body">
                                <input type="hidden" name="action" value="apply_fee">
                                <input type="hidden" name="installment_id" value="<?= $inst['id'] ?>">
                                
                                <div class="row g-3">
                                  <div class="col-md-6">
                                    <label class="form-label">Fee Amount *</label>
                                    <input type="number" name="fee_amount" class="form-control" step="0.01" min="0.01" required>
                                  </div>
                                  <div class="col-md-6">
                                    <label class="form-label">Fee Type *</label>
                                    <select name="fee_type" class="form-select" required>
                                      <option value="fixed">Fixed Amount</option>
                                      <option value="percentage">Percentage of Remaining</option>
                                    </select>
                                  </div>
                                  <div class="col-12">
                                    <label class="form-label">Description</label>
                                    <input type="text" name="description" class="form-control" value="Late Fee">
                                  </div>
                                </div>
                              </div>
                              <div class="modal-footer">
                                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                                <button type="submit" class="btn btn-warning">
                                  <i class="bi bi-plus-circle"></i> Apply Fee
                                </button>
                              </div>
                            </form>
                          </div>
                        </div>
                      </div>

                      <!-- Extend Modal -->
                      <div class="modal fade" id="extendModal<?= $inst['id'] ?>" tabindex="-1">
                        <div class="modal-dialog">
                          <div class="modal-content">
                            <div class="modal-header">
                              <h5 class="modal-title">
                                <i class="bi bi-calendar-plus"></i> Extend Due Date - Installment #<?= $inst['id'] ?>
                              </h5>
                              <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                            </div>
                            <form method="post">
                              <div class="modal-body">
                                <input type="hidden" name="action" value="extend_date">
                                <input type="hidden" name="id" value="<?= $inst['id'] ?>">
                                
                                <div class="row g-3">
                                  <div class="col-12">
                                    <label class="form-label">Current Due Date</label>
                                    <input type="text" class="form-control" value="<?= $inst['due_date'] ?>" readonly>
                                  </div>
                                  <div class="col-12">
                                    <label class="form-label">New Due Date *</label>
                                    <input type="date" name="new_due_date" class="form-control" required>
                                  </div>
                                  <div class="col-12">
                                    <label class="form-label">Reason for Extension *</label>
                                    <textarea name="reason" class="form-control" rows="3" required placeholder="Explain why the due date is being extended"></textarea>
                                  </div>
                                </div>
                              </div>
                              <div class="modal-footer">
                                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                                <button type="submit" class="btn btn-info">
                                  <i class="bi bi-calendar-plus"></i> Extend Date
                                </button>
                              </div>
                            </form>
                          </div>
                        </div>
                      </div>

                      <!-- Reminder Modal -->
                      <div class="modal fade" id="reminderModal<?= $inst['id'] ?>" tabindex="-1">
                        <div class="modal-dialog">
                          <div class="modal-content">
                            <div class="modal-header">
                              <h5 class="modal-title">
                                <i class="bi bi-envelope"></i> Send Reminder - Installment #<?= $inst['id'] ?>
                              </h5>
                              <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                            </div>
                            <form method="post">
                              <div class="modal-body">
                                <input type="hidden" name="action" value="send_reminder">
                                <input type="hidden" name="id" value="<?= $inst['id'] ?>">
                                
                                <div class="row g-3">
                                  <div class="col-12">
                                    <label class="form-label">Reminder Channel *</label>
                                    <select name="channel" class="form-select" required>
                                      <option value="email">Email Only</option>
                                      <option value="sms">SMS Only</option>
                                      <option value="both">Email & SMS</option>
                                    </select>
                                  </div>
                                  <div class="col-12">
                                    <div class="alert alert-info">
                                      <i class="bi bi-info-circle"></i> This will queue a payment reminder to be sent to the customer.
                                    </div>
                                  </div>
                                </div>
                              </div>
                              <div class="modal-footer">
                                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                                <button type="submit" class="btn btn-primary">
                                  <i class="bi bi-envelope"></i> Send Reminder
                                </button>
                              </div>
                            </form>
                          </div>
                        </div>
                      </div>

                      <!-- Writeoff Modal -->
                      <div class="modal fade" id="writeoffModal<?= $inst['id'] ?>" tabindex="-1">
                        <div class="modal-dialog">
                          <div class="modal-content">
                            <div class="modal-header">
                              <h5 class="modal-title">
                                <i class="bi bi-x-circle"></i> Mark as Write-off - Installment #<?= $inst['id'] ?>
                              </h5>
                              <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                            </div>
                            <form method="post">
                              <div class="modal-body">
                                <input type="hidden" name="action" value="mark_writeoff">
                                <input type="hidden" name="id" value="<?= $inst['id'] ?>">
                                
                                <div class="row g-3">
                                  <div class="col-12">
                                    <div class="alert alert-danger">
                                      <i class="bi bi-exclamation-triangle"></i> <strong>Warning:</strong> This will mark the installment as uncollectible and remove it from active tracking.
                                    </div>
                                  </div>
                                  <div class="col-12">
                                    <label class="form-label">Write-off Amount</label>
                                    <input type="text" class="form-control" value="<?= number_format((float)$inst['remaining_balance'], 2) ?>" readonly>
                                  </div>
                                  <div class="col-12">
                                    <label class="form-label">Reason for Write-off *</label>
                                    <textarea name="reason" class="form-control" rows="3" required placeholder="Explain why this amount is being written off"></textarea>
                                  </div>
                                </div>
                              </div>
                              <div class="modal-footer">
                                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                                <button type="submit" class="btn btn-danger">
                                  <i class="bi bi-x-circle"></i> Mark as Write-off
                                </button>
                              </div>
                            </form>
                          </div>
                        </div>
                      </div>

                    <?php endforeach; ?>
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

<?php include __DIR__ . '/../../templates/layout/footer.php'; ?>
