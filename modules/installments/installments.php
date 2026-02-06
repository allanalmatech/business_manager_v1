<?php
declare(strict_types=1);
require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/rbac.php';
require_once __DIR__ . '/../../includes/helpers.php';

require_permission('installments.view');

$db = $GLOBALS['db'];

$page_title = 'Installments';
$page_subtitle = 'View and manage installment schedules and payments';

$q = trim((string)($_GET['q'] ?? ''));
$contact = trim((string)($_GET['contact'] ?? ''));
$status = trim((string)($_GET['status'] ?? ''));
$from = trim((string)($_GET['from'] ?? ''));
$to = trim((string)($_GET['to'] ?? ''));
$overdue = isset($_GET['overdue']) && $_GET['overdue'] === '1';
$export = isset($_GET['export']) && $_GET['export'] === 'csv';

$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 50;
$offset = ($page - 1) * $perPage;

// Check if installments table exists
$hasInstallments = false;
$res = $db->query("SHOW TABLES LIKE 'installments'");
if ($res && $res->num_rows > 0) {
    $hasInstallments = true;
}

// Build WHERE clause
$where = [];
$types = '';
$params = [];

if ($q !== '') {
    $where[] = "(i.id LIKE CONCAT('%',?,'%') OR c.name LIKE CONCAT('%',?,'%'))";
    $types .= 'ss';
    $params[] = $q;
    $params[] = $q;
}

if ($contact !== '') {
    $where[] = 'i.contact_id = ?';
    $types .= 'i';
    $params[] = (int)$contact;
}

if ($status !== '') {
    $where[] = 'i.status = ?';
    $types .= 's';
    $params[] = $status;
}

if ($from !== '') {
    $where[] = 'i.due_date >= ?';
    $types .= 's';
    $params[] = $from . ' 00:00:00';
}

if ($to !== '') {
    $where[] = 'i.due_date <= ?';
    $types .= 's';
    $params[] = $to . ' 23:59:59';
}

if ($overdue) {
    $where[] = "i.status IN ('active','due_soon','overdue') AND i.due_date < NOW()";
}

// Check if contacts table exists for joins
$hasContacts = false;
$res = $db->query("SHOW TABLES LIKE 'contacts'");
if ($res && $res->num_rows > 0) {
    $hasContacts = true;
}

$whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

// Pagination count
if ($hasInstallments) {
    $countSql = "SELECT COUNT(*) AS cnt FROM installments i ";
    if ($hasContacts) {
        $countSql .= "LEFT JOIN contacts c ON c.id = i.contact_id ";
    }
    $countSql .= $whereSql;
    
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
if ($hasInstallments) {
    $contactSelect = $hasContacts ? ", c.name AS contact_name, c.phone AS contact_phone" : ", NULL AS contact_name, NULL AS contact_phone";
    $contactJoin = $hasContacts ? "LEFT JOIN contacts c ON c.id = i.contact_id" : "";
    
    $selectSql = "SELECT i.id, i.contact_id, i.amount_due, i.amount_paid, (i.amount_due - i.amount_paid) AS remaining_balance, 
        i.status, i.due_date, i.reference, i.created_at $contactSelect
        FROM installments i
        $contactJoin
        $whereSql
        ORDER BY i.due_date DESC
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
$totals = ['count' => 0, 'total_amount' => 0, 'paid_amount' => 0, 'remaining' => 0];
if ($hasInstallments) {
    $totalsSql = "SELECT COUNT(*) AS cnt, 
        IFNULL(SUM(i.amount_due),0) AS total_amount,
        IFNULL(SUM(i.amount_paid),0) AS paid_amount,
        IFNULL(SUM(i.amount_due - i.amount_paid),0) AS remaining_balance
        FROM installments i ";
    if ($hasContacts) {
        $totalsSql .= "LEFT JOIN contacts c ON c.id = i.contact_id ";
    }
    $totalsSql .= $whereSql;
    
    $st = $db->prepare($totalsSql);
    if ($types !== '') {
        $st->bind_param($types, ...$params);
    }
    $st->execute();
    $res = $st->get_result()->fetch_assoc();
    $st->close();
    
    $totals = [
        'count' => (int)($res['cnt'] ?? 0),
        'total_amount' => (float)($res['total_amount'] ?? 0),
        'paid_amount' => (float)($res['paid_amount'] ?? 0),
        'remaining' => (float)($res['remaining_balance'] ?? 0),
    ];
}

// Get unique contacts for filter
$contacts = [];
if ($hasContacts && $hasInstallments) {
    $res = $db->query("SELECT DISTINCT i.contact_id, c.name FROM installments i LEFT JOIN contacts c ON c.id = i.contact_id ORDER BY c.name");
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            if ($row['contact_id']) {
                $contacts[] = $row;
            }
        }
    }
}

if ($export && $hasInstallments) {
    // Stream CSV
    $contactSelect = $hasContacts ? ", c.name AS contact_name" : ", NULL AS contact_name";
    $contactJoin = $hasContacts ? "LEFT JOIN contacts c ON c.id = i.contact_id" : "";
    
    $csvSql = "SELECT i.id, i.contact_id, i.amount_due, i.amount_paid, (i.amount_due - i.amount_paid) AS remaining_balance, 
        i.status, i.due_date, i.reference $contactSelect
        FROM installments i
        $contactJoin
        $whereSql
        ORDER BY i.due_date DESC";
    
    $st = $db->prepare($csvSql);
    if ($types !== '') $st->bind_param($types, ...$params);
    $st->execute();
    $res = $st->get_result();

    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="installments_report.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Installment ID', 'Contact', 'Amount Due', 'Amount Paid', 'Remaining', 'Status', 'Due Date', 'Reference']);
    while ($r = $res->fetch_assoc()) {
        fputcsv($out, [
            $r['id'],
            $r['contact_name'] ?? 'N/A',
            $r['amount_due'],
            $r['amount_paid'],
            $r['remaining_balance'],
            $r['status'],
            $r['due_date'],
            $r['reference'],
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
            <a href="<?= $GLOBALS['BASE_URL'] ?>/modules/installments/create.php" class="btn btn-primary">
              <i class="bi bi-plus-circle"></i> New Installment
            </a>
            <?php if ($hasInstallments): ?>
              <a class="btn btn-outline-secondary" href="?<?= h(http_build_query(array_merge($_GET, ['export'=>'csv']))) ?>">
                <i class="bi bi-download"></i> Export CSV
              </a>
            <?php endif; ?>
          </div>
        </div>

        <?php if (!$hasInstallments): ?>
          <div class="alert alert-warning">
            <div class="d-flex align-items-center">
              <i class="bi bi-exclamation-triangle-fill me-3" style="font-size: 1.2rem;"></i>
              <div>
                <strong>Installments table not found.</strong> Please create it using the schema in the reports module.
              </div>
            </div>
          </div>
        <?php else: ?>

          <!-- Search and Filter -->
          <div class="card shadow-sm mb-4">
            <div class="card-header bg-light">
              <h6 class="mb-0"><i class="bi bi-funnel"></i> Search & Filter Installments</h6>
            </div>
            <div class="card-body">
              <form method="get" class="row g-3">
                <div class="col-md-3">
                  <label for="q" class="form-label">Search</label>
                  <input type="text" id="q" name="q" value="<?= h($q) ?>" class="form-control" placeholder="Search installment ID or contact">
                </div>
                <?php if (count($contacts) > 0): ?>
                  <div class="col-md-2">
                    <label for="contact" class="form-label">Contact</label>
                    <select id="contact" name="contact" class="form-select">
                      <option value="">All contacts</option>
                      <?php foreach ($contacts as $c): ?>
                        <option value="<?= (int)$c['contact_id'] ?>" <?= $contact === (string)$c['contact_id'] ? 'selected' : '' ?>>
                          <?= h($c['name']) ?>
                        </option>
                      <?php endforeach; ?>
                    </select>
                  </div>
                <?php endif; ?>
                <div class="col-md-2">
                  <label for="status" class="form-label">Status</label>
                  <select id="status" name="status" class="form-select">
                    <option value="">All statuses</option>
                    <option value="active" <?= $status === 'active' ? 'selected' : '' ?>>🟢 Active</option>
                    <option value="due_soon" <?= $status === 'due_soon' ? 'selected' : '' ?>>🟡 Due Soon</option>
                    <option value="overdue" <?= $status === 'overdue' ? 'selected' : '' ?>>🔴 Overdue</option>
                    <option value="completed" <?= $status === 'completed' ? 'selected' : '' ?>>✅ Completed</option>
                    <option value="extended" <?= $status === 'extended' ? 'selected' : '' ?>>🔄 Extended</option>
                    <option value="discontinued" <?= $status === 'discontinued' ? 'selected' : '' ?>>⚫ Discontinued</option>
                  </select>
                </div>
                <div class="col-md-2">
                  <label for="from" class="form-label">From Date</label>
                  <input type="date" id="from" name="from" value="<?= h($from) ?>" class="form-control">
                </div>
                <div class="col-md-2">
                  <label for="to" class="form-label">To Date</label>
                  <input type="date" id="to" name="to" value="<?= h($to) ?>" class="form-control">
                </div>
                <div class="col-md-1">
                  <label class="form-label">&nbsp;</label>
                  <div class="form-check">
                    <input type="checkbox" name="overdue" value="1" class="form-check-input" <?= $overdue ? 'checked' : '' ?>>
                    <label class="form-check-label">Overdue only</label>
                  </div>
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
                        <i class="bi bi-calendar-check text-primary" style="font-size: 1.5rem;"></i>
                      </div>
                    </div>
                    <div class="flex-grow-1 ms-3">
                      <div class="small text-muted">Total Installments</div>
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
                      <div class="bg-info bg-opacity-10 rounded-circle p-3">
                        <i class="bi bi-cash-stack text-info" style="font-size: 1.5rem;"></i>
                      </div>
                    </div>
                    <div class="flex-grow-1 ms-3">
                      <div class="small text-muted">Total Amount</div>
                      <div class="h5 mb-0 text-info"><?= h(format_currency((float)$totals['total_amount'], $db)) ?></div>
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
                        <i class="bi bi-check-circle text-success" style="font-size: 1.5rem;"></i>
                      </div>
                    </div>
                    <div class="flex-grow-1 ms-3">
                      <div class="small text-muted">Paid</div>
                      <div class="h5 mb-0 text-success"><?= h(format_currency((float)$totals['paid_amount'], $db)) ?></div>
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
                      <div class="bg-danger bg-opacity-10 rounded-circle p-3">
                        <i class="bi bi-exclamation-triangle text-danger" style="font-size: 1.5rem;"></i>
                      </div>
                    </div>
                    <div class="flex-grow-1 ms-3">
                      <div class="small text-muted">Remaining</div>
                      <div class="h5 mb-0 text-danger"><?= h(format_currency((float)$totals['remaining'], $db)) ?></div>
                    </div>
                  </div>
                </div>
              </div>
            </div>
          </div>

          <!-- Installments Table -->
          <div class="card shadow-sm">
            <div class="card-header bg-light">
              <h6 class="mb-0"><i class="bi bi-list-ul"></i> Installment Schedules</h6>
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
                      <th>Status</th>
                      <th width="120">Actions</th>
                    </tr>
                  </thead>
                  <tbody>
                    <?php if (!$rows): ?>
                      <tr>
                        <td colspan="9" class="text-center text-muted p-4">
                          <i class="bi bi-inbox" style="font-size: 2rem;"></i>
                          <div class="fw-semibold">No installments found</div>
                        </td>
                      </tr>
                    <?php else: foreach ($rows as $r):
                      $statusColor = match($r['status']) {
                        'active' => 'success',
                        'due_soon' => 'warning',
                        'overdue' => 'danger',
                        'completed' => 'info',
                        'extended' => 'secondary',
                        'discontinued' => 'dark',
                        default => 'secondary'
                      };
                      
                      $statusIcon = match($r['status']) {
                        'active' => '🟢',
                        'due_soon' => '🟡',
                        'overdue' => '🔴',
                        'completed' => '✅',
                        'extended' => '🔄',
                        'discontinued' => '⚫',
                        default => '📄'
                      };
                      
                      $paidPct = ($r['amount_due'] > 0) ? (int)((float)$r['amount_paid'] / (float)$r['amount_due'] * 100) : 0;
                    ?>
                      <tr>
                        <td><strong><?= h((string)$r['id']) ?></strong></td>
                        <td>
                          <div class="fw-semibold"><?= h($r['contact_name'] ?? 'N/A') ?></div>
                        </td>
                        <td class="text-end fw-semibold"><?= h(format_currency((float)$r['amount_due'], $db)) ?></td>
                        <td class="text-end">
                          <div class="d-flex align-items-center justify-content-end">
                            <small class="text-success me-2"><?= h(format_currency((float)$r['amount_paid'], $db)) ?></small>
                            <div class="progress" style="height: 6px; width: 60px;">
                              <div class="progress-bar bg-success" style="width: <?= $paidPct ?>%"></div>
                            </div>
                          </div>
                        </td>
                        <td class="text-end text-danger fw-semibold"><?= h(format_currency((float)$r['remaining_balance'], $db)) ?></td>
                        <td><?= h(substr($r['due_date'], 0, 10)) ?></td>
                        <td><small><?= h($r['reference'] ?? '') ?></small></td>
                        <td>
                          <span class="badge bg-<?= $statusColor ?>">
                            <?= $statusIcon ?> <?= h(str_replace('_', ' ', ucfirst($r['status']))) ?>
                          </span>
                        </td>
                        <td>
                          <div class="btn-group btn-group-sm" role="group">
                            <a href="<?= $GLOBALS['BASE_URL'] ?>/modules/installments/view.php?id=<?= (int)$r['id'] ?>" 
                               class="btn btn-outline-primary" title="View">
                              <i class="bi bi-eye"></i>
                            </a>
                            <a href="<?= $GLOBALS['BASE_URL'] ?>/modules/installments/edit.php?id=<?= (int)$r['id'] ?>" 
                               class="btn btn-outline-secondary" title="Edit">
                              <i class="bi bi-pencil"></i>
                            </a>
                          </div>
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
                <i class="bi bi-info-circle"></i> About Installment Management
              </h6>
            </div>
            <div class="card-body">
              <div class="row">
                <div class="col-md-6">
                  <h6 class="text-primary">Installment Status Guide:</h6>
                  <ul class="small">
                    <li><strong>🟢 Active:</strong> Installment is currently being paid as scheduled</li>
                    <li><strong>🟡 Due Soon:</strong> Payment date is approaching (within warning period)</li>
                    <li><strong>🔴 Overdue:</strong> Payment is past due and requires immediate attention</li>
                    <li><strong>✅ Completed:</strong> All payments have been successfully made</li>
                    <li><strong>🔄 Extended:</strong> Payment schedule has been extended</li>
                    <li><strong>⚫ Discontinued:</strong> Installment has been cancelled or discontinued</li>
                  </ul>
                </div>
                <div class="col-md-6">
                  <h6 class="text-success">Best Practices:</h6>
                  <ul class="small">
                    <li><strong>Regular Monitoring:</strong> Check installment status daily for overdue payments</li>
                    <li><strong>Customer Communication:</strong> Notify customers before payments become due</li>
                    <li><strong>Payment Tracking:</strong> Record all payments promptly and accurately</li>
                    <li><strong>Follow-up Process:</strong> Establish clear procedures for overdue payments</li>
                    <li><strong>Documentation:</strong> Maintain proper records of all installment agreements</li>
                    <li><strong>Reporting:</strong> Generate regular reports for cash flow planning</li>
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

<?php require_once __DIR__ . '/../../templates/layout/footer.php';
