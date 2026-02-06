<?php
declare(strict_types=1);
require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/rbac.php';
require_once __DIR__ . '/../../includes/helpers.php';

require_permission('installments.view');

$db = $GLOBALS['db'];

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($id <= 0) {
    header("Location: " . $GLOBALS['BASE_URL'] . "/modules/installments/installments.php");
    exit;
}

// Check if installments table exists
$hasInstallments = false;
$res = $db->query("SHOW TABLES LIKE 'installments'");
if ($res && $res->num_rows > 0) {
    $hasInstallments = true;
}

if (!$hasInstallments) {
    die("Installments table not found");
}

// Check if installment_payments table exists
$hasPayments = false;
$res = $db->query("SHOW TABLES LIKE 'installment_payments'");
if ($res && $res->num_rows > 0) {
    $hasPayments = true;
}

// Get installment details
$installment = null;
$st = $db->prepare("SELECT i.*, c.name AS contact_name, c.phone, c.email FROM installments i 
    LEFT JOIN contacts c ON c.id = i.contact_id 
    WHERE i.id = ? LIMIT 1");
if ($st) {
    $st->bind_param('i', $id);
    $st->execute();
    $installment = $st->get_result()->fetch_assoc();
    $st->close();
}

if (!$installment) {
    echo '<div class="alert alert-danger">Installment not found</div>';
    exit;
}

$page_title = 'Installment Details';
$page_subtitle = 'ID #' . $installment['id'];

// Get payment history if payments table exists
$payments = [];
if ($hasPayments) {
    $res = $db->query("SELECT * FROM installment_payments WHERE installment_id = $id ORDER BY payment_date DESC");
    if ($res) {
        while ($payment = $res->fetch_assoc()) {
            $payments[] = $payment;
        }
    }
}

require_once __DIR__ . '/../../templates/layout/header.php';
require_once __DIR__ . '/../../templates/layout/sidebar.php';
require_once __DIR__ . '/../../templates/layout/topbar.php';
?>
<div class="container-fluid">
  <div class="d-flex justify-content-between align-items-center mb-3">
    <div>
      <h1 class="h3"><?= h2($page_title) ?></h1>
      <p class="text-muted"><?= h2($page_subtitle) ?></p>
    </div>
    <div class="gap-2 d-flex">
      <a href="/modules/installments/installments.php" class="btn btn-outline-secondary btn-sm">Back</a>
      <a href="/modules/installments/edit.php?id=<?= (int)$id ?>" class="btn btn-primary btn-sm">Edit</a>
    </div>
  </div>

  <div class="row g-3">
    <!-- Main Details -->
    <div class="col-lg-6">
      <div class="card">
        <div class="card-header">
          <h5 class="mb-0">Installment Information</h5>
        </div>
        <div class="card-body">
          <div class="row mb-3">
            <div class="col-6">
              <div class="small text-muted">Installment ID</div>
              <div class="fw-bold"><?= h2((string)$installment['id']) ?></div>
            </div>
            <div class="col-6">
              <div class="small text-muted">Status</div>
              <div class="fw-bold">
                <?php
                $statusColor = match($installment['status']) {
                    'active' => 'success',
                    'due_soon' => 'warning',
                    'overdue' => 'danger',
                    'completed' => 'info',
                    'extended' => 'secondary',
                    'discontinued' => 'dark',
                    default => 'secondary'
                };
                ?>
                <span class="badge bg-<?= $statusColor ?>">
                  <?= h2(str_replace('_', ' ', ucfirst($installment['status']))) ?>
                </span>
              </div>
            </div>
          </div>

          <div class="row mb-3">
            <div class="col-6">
              <div class="small text-muted">Contact</div>
              <div class="fw-bold"><?= h2($installment['contact_name'] ?? 'N/A') ?></div>
              <?php if ($installment['email']): ?>
                <div class="small text-secondary"><?= h2($installment['email']) ?></div>
              <?php endif; ?>
              <?php if ($installment['phone']): ?>
                <div class="small text-secondary"><?= h2($installment['phone']) ?></div>
              <?php endif; ?>
            </div>
            <div class="col-6">
              <div class="small text-muted">Period</div>
              <div class="fw-bold"><?= h2(substr($installment['start_date'], 0, 10)) ?></div>
              <div class="small">to <?= h2(substr($installment['end_date'], 0, 10)) ?></div>
            </div>
          </div>

          <hr>

          <div class="row mb-3">
            <div class="col-6">
              <div class="small text-muted">Number of Installments</div>
              <div class="h5"><?= h2((string)$installment['num_installments']) ?></div>
            </div>
            <div class="col-6">
              <div class="small text-muted">Frequency</div>
              <div class="h5"><?= h2($installment['frequency'] ?? 'Monthly') ?></div>
            </div>
          </div>

          <?php if ($installment['notes']): ?>
            <div class="mb-3">
              <div class="small text-muted">Notes</div>
              <div class="p-2 bg-light border rounded small"><?= h2($installment['notes']) ?></div>
            </div>
          <?php endif; ?>
        </div>
      </div>
    </div>

    <!-- Financial Summary -->
    <div class="col-lg-6">
      <div class="card">
        <div class="card-header">
          <h5 class="mb-0">Financial Summary</h5>
        </div>
        <div class="card-body">
          <div class="row mb-3">
            <div class="col-6">
              <div class="small text-muted">Total Amount</div>
              <div class="h5"><?= h2(number_format((float)$installment['total_amount'], 2)) ?></div>
            </div>
            <div class="col-6">
              <div class="small text-muted">Per Installment</div>
              <div class="h5">
                <?php
                  $perInstallment = $installment['num_installments'] > 0 
                    ? (float)$installment['total_amount'] / (int)$installment['num_installments']
                    : 0;
                ?>
                <?= h2(number_format($perInstallment, 2)) ?>
              </div>
            </div>
          </div>

          <div class="row mb-3">
            <div class="col-6">
              <div class="small text-muted">Amount Paid</div>
              <div class="h5 text-success"><?= h2(number_format((float)$installment['paid_amount'], 2)) ?></div>
            </div>
            <div class="col-6">
              <div class="small text-muted">Remaining Balance</div>
              <div class="h5 text-danger"><?= h2(number_format((float)$installment['remaining_balance'], 2)) ?></div>
            </div>
          </div>

          <!-- Progress Bar -->
          <div class="mb-3">
            <div class="small text-muted">Payment Progress</div>
            <?php
              $paidPct = ($installment['total_amount'] > 0) 
                ? (int)((float)$installment['paid_amount'] / (float)$installment['total_amount'] * 100)
                : 0;
            ?>
            <div class="progress" style="height: 25px;">
              <div class="progress-bar bg-success" style="width: <?= $paidPct ?>%; display: flex; align-items: center; justify-content: center; color: white; font-weight: bold;">
                <?= $paidPct ?>%
              </div>
            </div>
          </div>

          <div class="alert alert-info small">
            <strong>Overview:</strong> 
            <?= h2((string)count($payments)) ?> payment(s) made. 
            <?php if ($installment['remaining_balance'] > 0): ?>
              Balance of <?= h2(number_format((float)$installment['remaining_balance'], 2)) ?> outstanding.
            <?php else: ?>
              All payments completed.
            <?php endif; ?>
          </div>
        </div>
      </div>
    </div>
  </div>

  <!-- Payment History -->
  <?php if ($hasPayments): ?>
    <div class="card mt-3">
      <div class="card-header d-flex justify-content-between align-items-center">
        <h5 class="mb-0">Payment History (<?= count($payments) ?>)</h5>
        <?php if (function_exists('user_has_permission') && user_has_permission('installments.create')): ?>
          <a href="/modules/installments/payment.php?installment_id=<?= (int)$id ?>" class="btn btn-sm btn-primary">Record Payment</a>
        <?php endif; ?>
      </div>
      <div class="card-body p-0">
        <?php if ($payments): ?>
          <table class="table mb-0 small">
            <thead>
              <tr>
                <th>Payment Date</th>
                <th>Amount</th>
                <th>Method</th>
                <th>Reference</th>
                <th>Notes</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($payments as $p): ?>
                <tr>
                  <td><?= h2($p['payment_date']) ?></td>
                  <td class="text-end fw-bold"><?= h2(number_format((float)$p['amount'], 2)) ?></td>
                  <td><?= h2($p['method'] ?? '-') ?></td>
                  <td><?= h2($p['reference'] ?? '-') ?></td>
                  <td><?= h2(substr($p['notes'] ?? '', 0, 40)) ?></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        <?php else: ?>
          <div class="p-3 text-muted text-center">
            No payments recorded yet.
          </div>
        <?php endif; ?>
      </div>
    </div>
  <?php endif; ?>

  <!-- Metadata -->
  <div class="card mt-3">
    <div class="card-header">
      <h5 class="mb-0">Metadata</h5>
    </div>
    <div class="card-body small">
      <div class="row">
        <div class="col-md-6">
          <div class="mb-2">
            <div class="text-muted">Created</div>
            <div><?= h2($installment['created_at']) ?></div>
          </div>
        </div>
        <div class="col-md-6">
          <div class="mb-2">
            <div class="text-muted">Last Updated</div>
            <div><?= h2($installment['updated_at'] ?? 'N/A') ?></div>
          </div>
        </div>
      </div>
    </div>
  </div>

</div>

<?php require_once __DIR__ . '/../../templates/layout/footer.php';
