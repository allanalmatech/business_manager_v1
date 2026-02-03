<?php
declare(strict_types=1);
require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/rbac.php';
require_once __DIR__ . '/../../includes/helpers.php';

require_permission('finance.view');

$db = $GLOBALS['db'];

$page_title = 'Payment Vouchers';
$page_subtitle = 'Record and track payment vouchers and approvals';

$q = trim((string)($_GET['q'] ?? ''));
$from = trim((string)($_GET['from'] ?? ''));
$to = trim((string)($_GET['to'] ?? ''));
$status = trim((string)($_GET['status'] ?? ''));
$export = isset($_GET['export']) && $_GET['export'] === 'csv';
$action = trim((string)($_GET['action'] ?? ''));

// Handle form submission for creating new vouchers
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'create') {
    $csrf = trim((string)($_POST['csrf'] ?? ''));
    if (empty($_SESSION['csrf']) || !hash_equals($_SESSION['csrf'], $csrf)) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'CSRF token invalid']);
        exit;
    }
    
    $voucher_no = trim((string)($_POST['voucher_no'] ?? ''));
    $voucher_date = trim((string)($_POST['voucher_date'] ?? ''));
    $payee = trim((string)($_POST['payee'] ?? ''));
    $amount = (float)($_POST['amount'] ?? 0);
    $description = trim((string)($_POST['description'] ?? ''));
    $payment_method = trim((string)($_POST['payment_method'] ?? ''));
    $reference = trim((string)($_POST['reference'] ?? ''));
    $notes = trim((string)($_POST['notes'] ?? ''));
    
    if ($voucher_no === '' || $voucher_date === '' || $payee === '' || $amount <= 0 || $description === '') {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'Please fill in all required fields']);
        exit;
    }
    
    try {
        if ($hasVouchers) {
            // Check if voucher number already exists
            $checkSql = "SELECT id FROM vouchers WHERE voucher_no = ?";
            $st = $db->prepare($checkSql);
            $st->bind_param('s', $voucher_no);
            $st->execute();
            if ($st->get_result()->num_rows > 0) {
                header('Content-Type: application/json');
                echo json_encode(['success' => false, 'message' => 'Voucher number already exists']);
                exit;
            }
            $st->close();
            
            // Insert new voucher
            $insertSql = "INSERT INTO vouchers (voucher_no, voucher_date, payee, amount, description, payment_method, reference, notes, status, created_by) 
                          VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'draft', ?)";
            $st = $db->prepare($insertSql);
            $user_id = (int)($_SESSION['user']['id'] ?? 0);
            $st->bind_param('sssdsdssi', $voucher_no, $voucher_date, $payee, $amount, $description, $payment_method, $reference, $notes, $user_id);
            $st->execute();
            $st->close();
            
            header('Content-Type: application/json');
            echo json_encode(['success' => true, 'message' => 'Voucher created successfully']);
        } else {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'message' => 'Vouchers table not found']);
        }
    } catch (Exception $e) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
    }
    exit;
}

$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 50;
$offset = ($page - 1) * $perPage;

// Check if vouchers table exists
$hasVouchers = false;
$res = $db->query("SHOW TABLES LIKE 'vouchers'");
if ($res && $res->num_rows > 0) {
    $hasVouchers = true;
}

// Build WHERE clause
$where = [];
$types = '';
$params = [];

if ($q !== '') {
    $where[] = "(v.voucher_no LIKE CONCAT('%',?,'%') OR v.description LIKE CONCAT('%',?,'%') OR v.payee LIKE CONCAT('%',?,'%'))";
    $types .= 'sss';
    $params[] = $q;
    $params[] = $q;
    $params[] = $q;
}

if ($from !== '') {
    $where[] = 'v.voucher_date >= ?';
    $types .= 's';
    $params[] = $from . ' 00:00:00';
}

if ($to !== '') {
    $where[] = 'v.voucher_date <= ?';
    $types .= 's';
    $params[] = $to . ' 23:59:59';
}

if ($status !== '') {
    $where[] = 'v.status = ?';
    $types .= 's';
    $params[] = $status;
}

$whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

// Pagination count
if ($hasVouchers) {
    $countSql = "SELECT COUNT(*) AS cnt FROM vouchers v $whereSql";
    $st = $db->prepare($countSql);
    if ($types !== '') {
        $st->bind_param($types, ...$params);
    }
    $st->execute();
    $res = $st->get_result();
    $total = (int)($res->fetch_assoc()['cnt'] ?? 0);
    $st->close();
} else {
    $total = 0;
}

// Main select
$rows = [];
if ($hasVouchers) {
    $selectSql = "SELECT v.id, v.voucher_no, v.voucher_date, v.payee, v.amount, v.description, v.status, 
        v.approved_by, v.approved_at, v.created_by, v.created_at
        FROM vouchers v
        $whereSql
        ORDER BY v.voucher_date DESC
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
}

// Totals
$totals = ['count' => 0, 'amount' => 0, 'pending' => 0, 'approved' => 0];
if ($hasVouchers) {
    $totalsSql = "SELECT COUNT(*) AS cnt, IFNULL(SUM(v.amount),0) AS total_amount,
        SUM(CASE WHEN v.status='pending' THEN 1 ELSE 0 END) AS pending_count,
        SUM(CASE WHEN v.status='approved' THEN 1 ELSE 0 END) AS approved_count
        FROM vouchers v $whereSql";
    $st = $db->prepare($totalsSql);
    if ($types !== '') {
        $st->bind_param($types, ...$params);
    }
    $st->execute();
    $res = $st->get_result()->fetch_assoc();
    $st->close();
    
    $totals = [
        'count' => (int)($res['cnt'] ?? 0),
        'amount' => (float)($res['total_amount'] ?? 0),
        'pending' => (int)($res['pending_count'] ?? 0),
        'approved' => (int)($res['approved_count'] ?? 0),
    ];
}

if ($export && $hasVouchers) {
    // Stream CSV
    $csvSql = "SELECT v.id, v.voucher_no, v.voucher_date, v.payee, v.amount, v.description, v.status, v.approved_at
        FROM vouchers v
        $whereSql
        ORDER BY v.voucher_date DESC";
    $st = $db->prepare($csvSql);
    if ($types !== '') $st->bind_param($types, ...$params);
    $st->execute();
    $res = $st->get_result();

    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="vouchers_report.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Voucher No', 'Date', 'Payee', 'Amount', 'Description', 'Status', 'Approved Date']);
    while ($r = $res->fetch_assoc()) {
        fputcsv($out, [
            $r['voucher_no'],
            $r['voucher_date'],
            $r['payee'],
            $r['amount'],
            $r['description'],
            $r['status'],
            $r['approved_at'] ?? '',
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
          <div class="gap-2 d-flex">
            <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#newVoucherModal">
              <i class="bi bi-plus-circle"></i> New Voucher
            </button>
            <?php if ($hasVouchers): ?>
              <a class="btn btn-outline-secondary" href="?<?= h(http_build_query(array_merge($_GET, ['export'=>'csv']))) ?>">
                <i class="bi bi-download"></i> Export CSV
              </a>
            <?php endif; ?>
          </div>
        </div>

        <?php if (!$hasVouchers): ?>
          <div class="alert alert-warning">
            <div class="d-flex align-items-center">
              <i class="bi bi-exclamation-triangle-fill me-3" style="font-size: 1.2rem;"></i>
              <div>
                <strong>Vouchers table not found.</strong> Please create it using the schema below:
                <pre class="mt-2 mb-0 small bg-light p-2 border rounded">
CREATE TABLE vouchers (
  id INT AUTO_INCREMENT PRIMARY KEY,
  voucher_no VARCHAR(30) NOT NULL UNIQUE,
  voucher_date DATETIME NOT NULL,
  payee VARCHAR(150) NOT NULL,
  amount DECIMAL(12,2) NOT NULL,
  description TEXT,
  status ENUM('draft','pending','approved','rejected','paid') DEFAULT 'draft',
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  created_by INT,
  approved_by INT,
  approved_at DATETIME NULL,
  INDEX idx_status (status),
  INDEX idx_date (voucher_date),
  INDEX idx_voucher_no (voucher_no)
);
                </pre>
              </div>
            </div>
          </div>
        <?php else: ?>

          <!-- Search and Filter -->
          <div class="card shadow-sm mb-4">
            <div class="card-header bg-light">
              <h6 class="mb-0"><i class="bi bi-funnel"></i> Search & Filter Vouchers</h6>
            </div>
            <div class="card-body">
              <form method="get" class="row g-3">
                <div class="col-md-4">
                  <label for="q" class="form-label">Search</label>
                  <input type="text" id="q" name="q" value="<?= h($q) ?>" class="form-control" placeholder="Search voucher/payee/description">
                </div>
                <div class="col-md-2">
                  <label for="from" class="form-label">From Date</label>
                  <input type="date" id="from" name="from" value="<?= h($from) ?>" class="form-control">
                </div>
                <div class="col-md-2">
                  <label for="to" class="form-label">To Date</label>
                  <input type="date" id="to" name="to" value="<?= h($to) ?>" class="form-control">
                </div>
                <div class="col-md-2">
                  <label for="status" class="form-label">Status</label>
                  <select id="status" name="status" class="form-select">
                    <option value="">All statuses</option>
                    <option value="draft" <?= $status === 'draft' ? 'selected' : '' ?>> Draft</option>
                    <option value="pending" <?= $status === 'pending' ? 'selected' : '' ?>> Pending Approval</option>
                    <option value="approved" <?= $status === 'approved' ? 'selected' : '' ?>> Approved</option>
                    <option value="rejected" <?= $status === 'rejected' ? 'selected' : '' ?>> Rejected</option>
                    <option value="paid" <?= $status === 'paid' ? 'selected' : '' ?>> Paid</option>
                  </select>
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

          <!-- Summary Cards -->
          <div class="row g-3 mb-4">
            <div class="col-md-3">
              <div class="card shadow-sm">
                <div class="card-body">
                  <div class="d-flex align-items-center">
                    <div class="flex-shrink-0">
                      <div class="bg-primary bg-opacity-10 rounded-circle p-3">
                        <i class="bi bi-receipt text-primary" style="font-size: 1.5rem;"></i>
                      </div>
                    </div>
                    <div class="flex-grow-1 ms-3">
                      <div class="small text-muted">Total Vouchers</div>
                      <div class="h5 mb-0 text-primary"><?= h((string)$totals['count']) ?></div>
                    </div>
                  </div>
                </div>
              </div>
            </div>
            <div class="col-md-3">
              <div class="card shadow-sm">
                <div class="card-body">
                  <div class="d-flex align-items-center">
                    <div class="flex-shrink-0">
                      <div class="bg-success bg-opacity-10 rounded-circle p-3">
                        <i class="bi bi-cash-stack text-success" style="font-size: 1.5rem;"></i>
                      </div>
                    </div>
                    <div class="flex-grow-1 ms-3">
                      <div class="small text-muted">Total Amount</div>
                      <div class="h5 mb-0 text-success"><?= h(format_currency((float)$totals['amount'], $db)) ?></div>
                    </div>
                  </div>
                </div>
              </div>
            </div>
            <div class="col-md-3">
              <div class="card shadow-sm">
                <div class="card-body">
                  <div class="d-flex align-items-center">
                    <div class="flex-shrink-0">
                      <div class="bg-warning bg-opacity-10 rounded-circle p-3">
                        <i class="bi bi-clock-history text-warning" style="font-size: 1.5rem;"></i>
                      </div>
                    </div>
                    <div class="flex-grow-1 ms-3">
                      <div class="small text-muted">Pending Approval</div>
                      <div class="h5 mb-0 text-warning"><?= h((string)$totals['pending']) ?></div>
                    </div>
                  </div>
                </div>
              </div>
            </div>
            <div class="col-md-3">
              <div class="card shadow-sm">
                <div class="card-body">
                  <div class="d-flex align-items-center">
                    <div class="flex-shrink-0">
                      <div class="bg-info bg-opacity-10 rounded-circle p-3">
                        <i class="bi bi-check-circle text-info" style="font-size: 1.5rem;"></i>
                      </div>
                    </div>
                    <div class="flex-grow-1 ms-3">
                      <div class="small text-muted">Approved</div>
                      <div class="h5 mb-0 text-info"><?= h((string)$totals['approved']) ?></div>
                    </div>
                  </div>
                </div>
              </div>
            </div>
          </div>

          <!-- Vouchers Table -->
          <div class="card shadow-sm">
            <div class="card-header bg-light">
              <h6 class="mb-0"><i class="bi bi-list-ul"></i> Payment Vouchers</h6>
            </div>
            <div class="card-body p-0">
              <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                  <thead class="table-light">
                    <tr>
                      <th>Voucher No</th>
                      <th>Date</th>
                      <th>Payee</th>
                      <th class="text-end">Amount</th>
                      <th>Description</th>
                      <th>Status</th>
                      <th>Approved Date</th>
                      <th width="120">Actions</th>
                    </tr>
                  </thead>
                  <tbody>
                    <?php if ($rows): ?>
                      <?php foreach ($rows as $r): ?>
                        <?php
                          $statusColor = match($r['status']) {
                            'draft' => 'secondary',
                            'pending' => 'warning',
                            'approved' => 'success',
                            'rejected' => 'danger',
                            'paid' => 'info',
                            default => 'secondary'
                          };
                          
                          $statusIcon = match($r['status']) {
                            'draft' => '📝',
                            'pending' => '⏳',
                            'approved' => '✅',
                            'rejected' => '❌',
                            'paid' => '💰',
                            default => '📄'
                          };
                        ?>
                        <tr>
                          <td><strong><?= h($r['voucher_no']) ?></strong></td>
                          <td><?= h(substr($r['voucher_date'], 0, 10)) ?></td>
                          <td><?= h($r['payee']) ?></td>
                          <td class="text-end fw-semibold"><?= h(format_currency((float)$r['amount'], $db)) ?></td>
                          <td class="text-truncate" title="<?= h($r['description'] ?? '') ?>">
                            <small><?= h(substr($r['description'] ?? '', 0, 30)) ?></small>
                          </td>
                          <td>
                            <span class="badge bg-<?= $statusColor ?>">
                              <?= $statusIcon ?> <?= h(ucfirst($r['status'])) ?>
                            </span>
                          </td>
                          <td><?= h($r['approved_at'] ? substr($r['approved_at'], 0, 10) : '-') ?></td>
                          <td>
                            <div class="btn-group btn-group-sm" role="group">
                              <a href="<?= $GLOBALS['BASE_URL'] ?>/modules/finance/vouchers.php?action=view&id=<?= (int)$r['id'] ?>" 
                                 class="btn btn-outline-primary" title="View">
                                <i class="bi bi-eye"></i>
                              </a>
                              <?php if ($r['status'] === 'draft'): ?>
                                <a href="<?= $GLOBALS['BASE_URL'] ?>/modules/finance/vouchers.php?action=edit&id=<?= (int)$r['id'] ?>" 
                                   class="btn btn-outline-secondary" title="Edit">
                                  <i class="bi bi-pencil"></i>
                                </a>
                              <?php endif; ?>
                            </div>
                          </td>
                        </tr>
                      <?php endforeach; ?>
                    <?php else: ?>
                      <tr>
                        <td colspan="8" class="text-center text-muted p-4">
                          <i class="bi bi-inbox" style="font-size: 2rem;"></i>
                          <div class="fw-semibold">No vouchers found</div>
                        </td>
                      </tr>
                    <?php endif; ?>
                  </tbody>
                </table>
              </div>
            </div>
          </div>

          <!-- Pagination -->
          <?php if ($total > $perPage): ?>
            <div class="mt-4">
              <?php $lastPage = max(1, (int)ceil($total / $perPage)); ?>
              <nav aria-label="pagination">
                <ul class="pagination pagination-sm justify-content-center">
                  <?php for ($p = 1; $p <= $lastPage; $p++): ?>
                    <li class="page-item <?= $p === $page ? 'active' : '' ?>">
                      <a class="page-link" href="?<?= h(http_build_query(array_merge($_GET, ['page'=>$p]))) ?>"><?= $p ?></a>
                    </li>
                  <?php endfor; ?>
                </ul>
              </nav>
            </div>
          <?php endif; ?>

          <!-- Information Panel -->
          <div class="card shadow-sm mt-4">
            <div class="card-header bg-info text-white">
              <h6 class="mb-0">
                <i class="bi bi-info-circle"></i> About Payment Vouchers
              </h6>
            </div>
            <div class="card-body">
              <div class="row">
                <div class="col-md-6">
                  <h6 class="text-primary">Voucher Workflow:</h6>
                  <ol class="small">
                    <li><strong>Draft:</strong> Create new voucher with payment details</li>
                    <li><strong>Pending Approval:</strong> Submit for manager review</li>
                    <li><strong>Approved:</strong> Authorized for payment processing</li>
                    <li><strong>Paid:</strong> Payment completed and recorded</li>
                    <li><strong>Rejected:</strong> Voucher denied with reason</li>
                  </ol>
                </div>
                <div class="col-md-6">
                  <h6 class="text-success">Best Practices:</h6>
                  <ul class="small">
                    <li><strong>Accurate Information:</strong> Ensure payee and amount are correct</li>
                    <li><strong>Proper Documentation:</strong> Include clear descriptions and references</li>
                    <li><strong>Approval Process:</strong> Follow company authorization procedures</li>
                    <li><strong>Record Keeping:</strong> Maintain proper audit trail for all payments</li>
                    <li><strong>Regular Review:</strong> Monitor voucher status and follow up on pending items</li>
                  </ul>
                </div>
              </div>
            </div>
          </div>

        <?php endif; ?>
      </div>
    </main>
  </div>
</div>

<!-- New Voucher Modal -->
<div class="modal fade" id="newVoucherModal" tabindex="-1" aria-labelledby="newVoucherModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <div class="modal-header bg-primary text-white">
        <h5 class="modal-title" id="newVoucherModalLabel">
          <i class="bi bi-plus-circle"></i> Create New Payment Voucher
        </h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <form method="post" action="<?= $GLOBALS['BASE_URL'] ?>/modules/finance/vouchers.php?action=create" class="needs-validation" novalidate>
        <div class="modal-body">
          <input type="hidden" name="csrf" value="<?= h($_SESSION['csrf'] ?? '') ?>">
          
          <div class="row g-3">
            <div class="col-md-6">
              <label for="voucher_no" class="form-label">Voucher Number *</label>
              <input type="text" id="voucher_no" name="voucher_no" class="form-control" 
                     placeholder="e.g., PV-2024-001" required>
              <small class="form-text">Unique voucher identification number</small>
            </div>
            <div class="col-md-6">
              <label for="voucher_date" class="form-label">Voucher Date *</label>
              <input type="date" id="voucher_date" name="voucher_date" class="form-control" 
                     value="<?= h(date('Y-m-d')) ?>" required>
              <small class="form-text">Date of voucher creation</small>
            </div>
          </div>

          <div class="row g-3">
            <div class="col-md-6">
              <label for="payee" class="form-label">Payee *</label>
              <input type="text" id="payee" name="payee" class="form-control" 
                     placeholder="Name of person or company to be paid" required>
              <small class="form-text">Person or company receiving payment</small>
            </div>
            <div class="col-md-6">
              <label for="amount" class="form-label">Amount *</label>
              <div class="input-group">
                <span class="input-group-text"><?= h(get_currency_symbol($db)) ?></span>
                <input type="number" id="amount" name="amount" class="form-control" 
                       placeholder="0.00" step="0.01" min="0" required>
              </div>
              <small class="form-text">Payment amount in <?= h(get_currency_code($db)) ?></small>
            </div>
          </div>

          <div class="row g-3">
            <div class="col-12">
              <label for="description" class="form-label">Description *</label>
              <textarea id="description" name="description" class="form-control" rows="3" 
                        placeholder="Payment description, invoice number, purpose, etc." required></textarea>
              <small class="form-text">Detailed description of the payment purpose</small>
            </div>
          </div>

          <div class="row g-3">
            <div class="col-md-6">
              <label for="payment_method" class="form-label">Payment Method</label>
              <select id="payment_method" name="payment_method" class="form-select">
                <option value="">-- Select method --</option>
                <option value="cash">💵 Cash</option>
                <option value="bank_transfer">🏦 Bank Transfer</option>
                <option value="cheque">📄 Cheque</option>
                <option value="mobile_money">📱 Mobile Money</option>
                <option value="credit_card">💳 Credit Card</option>
                <option value="other">🔄 Other</option>
              </select>
              <small class="form-text">How the payment will be made</small>
            </div>
            <div class="col-md-6">
              <label for="reference" class="form-label">Reference</label>
              <input type="text" id="reference" name="reference" class="form-control" 
                     placeholder="Invoice #, Receipt #, etc.">
              <small class="form-text">Optional reference number</small>
            </div>
          </div>

          <div class="row g-3">
            <div class="col-12">
              <label for="notes" class="form-label">Additional Notes</label>
              <textarea id="notes" name="notes" class="form-control" rows="2" 
                        placeholder="Any additional information or special instructions"></textarea>
              <small class="form-text">Optional additional notes</small>
            </div>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
            <i class="bi bi-x-circle"></i> Cancel
          </button>
          <button type="submit" class="btn btn-primary">
            <i class="bi bi-check-circle"></i> Create Voucher
          </button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- Success Modal -->
<div class="modal fade" id="successModal" tabindex="-1" aria-labelledby="successModalLabel" aria-hidden="true">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header bg-success text-white">
        <h5 class="modal-title" id="successModalLabel">
          <i class="bi bi-check-circle"></i> Success
        </h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        <div class="text-center">
          <i class="bi bi-check-circle text-success" style="font-size: 3rem;"></i>
          <h5 class="mt-3">Voucher Created Successfully!</h5>
          <p class="text-muted" id="successMessage">Your payment voucher has been created and is ready for approval.</p>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
        <button type="button" class="btn btn-primary" onclick="location.reload()">Refresh List</button>
      </div>
    </div>
  </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // Auto-generate voucher number
    const voucherNoInput = document.getElementById('voucher_no');
    if (voucherNoInput) {
        const today = new Date();
        const year = today.getFullYear();
        const random = Math.floor(Math.random() * 1000).toString().padStart(3, '0');
        voucherNoInput.value = `PV-${year}-${random}`;
    }

    // Handle form submission
    const voucherForm = document.querySelector('#newVoucherModal form');
    if (voucherForm) {
        voucherForm.addEventListener('submit', function(e) {
            e.preventDefault();
            
            const formData = new FormData(voucherForm);
            
            fetch(voucherForm.action, {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    // Close the modal
                    const modal = bootstrap.Modal.getInstance(document.getElementById('newVoucherModal'));
                    modal.hide();
                    
                    // Show success modal
                    const successModal = new bootstrap.Modal(document.getElementById('successModal'));
                    successModal.show();
                    
                    // Reset form
                    voucherForm.reset();
                    
                    // Auto-generate new voucher number
                    const today = new Date();
                    const year = today.getFullYear();
                    const random = Math.floor(Math.random() * 1000).toString().padStart(3, '0');
                    voucherNoInput.value = `PV-${year}-${random}`;
                } else {
                    alert('Error: ' + (data.message || 'Failed to create voucher'));
                }
            })
            .catch(error => {
                console.error('Error:', error);
                alert('Error: Failed to create voucher');
            });
        });
    }
});
</script>

<?php require_once __DIR__ . '/../../templates/layout/footer.php';
