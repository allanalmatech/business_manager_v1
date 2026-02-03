<?php
// modules/pos/sale_history.php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/rbac.php';
require_once __DIR__ . '/../../includes/helpers.php';

require_role('admin', 'super_admin'); // if you want both admin + staff, replace with your normal auth check

// Permissions: prefer pos.view, fallback to pos.create
if (function_exists('user_has_permission')) {
  $canView = user_has_permission('pos.view') || user_has_permission('pos.create');
  if (!$canView) {
    http_response_code(403);
    die("Forbidden");
  }
} else {
  // if RBAC helper missing, allow (dev mode)
}

$db = $GLOBALS['db'] ?? null;
if (!$db instanceof mysqli) {
  http_response_code(500);
  die("DB not available");
}

if (session_status() === PHP_SESSION_NONE) session_start();
if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(32));
$csrf = $_SESSION['csrf'];

function h2(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }

function table_has_column(mysqli $db, string $table, string $col): bool {
  $sql = "SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE()
            AND TABLE_NAME = ?
            AND COLUMN_NAME = ?
          LIMIT 1";
  $st = $db->prepare($sql);
  if (!$st) return false;
  $st->bind_param('ss', $table, $col);
  $st->execute();
  $ok = (bool)$st->get_result()->fetch_row();
  $st->close();
  return $ok;
}

function money($n): string {
  $x = (float)$n;
  return number_format($x, 0, '.', ',');
}

// ---- permissions for void action ----
$canVoid = function_exists('user_has_permission') ? (bool)user_has_permission('pos.void') : true; // Allow by default if RBAC not available

// ---- read filters ----
$q = trim((string)($_GET['q'] ?? ''));
$doc_type = trim((string)($_GET['doc_type'] ?? ''));
$status = trim((string)($_GET['status'] ?? ''));
$payment_status = trim((string)($_GET['payment_status'] ?? ''));
$location_id = (int)($_GET['location_id'] ?? 0);
$from = trim((string)($_GET['from'] ?? ''));
$to = trim((string)($_GET['to'] ?? ''));

$per_page = (int)($_GET['per_page'] ?? 25);
if (!in_array($per_page, [15,25,50,100], true)) $per_page = 25;

$page = (int)($_GET['page'] ?? 1);
if ($page < 1) $page = 1;
$offset = ($page - 1) * $per_page;

// ---- build WHERE ----
$where = "1=1";
$params = [];
$types = "";

$allowed_doc = ['receipt','invoice','delivery_note'];
if ($doc_type !== '' && in_array($doc_type, $allowed_doc, true)) {
  $where .= " AND s.doc_type = ?";
  $params[] = $doc_type;
  $types .= "s";
}

$allowed_status = ['draft','confirmed','voided'];
if ($status !== '' && in_array($status, $allowed_status, true)) {
  $where .= " AND s.status = ?";
  $params[] = $status;
  $types .= "s";
}

$allowed_pay = ['unpaid','partial','paid'];
if ($payment_status !== '' && in_array($payment_status, $allowed_pay, true)) {
  $where .= " AND s.payment_status = ?";
  $params[] = $payment_status;
  $types .= "s";
}

if ($location_id > 0) {
  $where .= " AND s.selling_location_id = ?";
  $params[] = $location_id;
  $types .= "i";
}

if ($from !== '') {
  // expecting YYYY-MM-DD
  $where .= " AND s.created_at >= ?";
  $params[] = $from . " 00:00:00";
  $types .= "s";
}
if ($to !== '') {
  $where .= " AND s.created_at <= ?";
  $params[] = $to . " 23:59:59";
  $types .= "s";
}

// Customer join (if customers table exists)
$hasCustomers = $db->query("SHOW TABLES LIKE 'customers'")?->num_rows ?? 0;
$customerSelect = $hasCustomers ? ", c.name AS customer_name" : ", NULL AS customer_name";
$customerJoin = $hasCustomers ? " LEFT JOIN customers c ON c.id = s.customer_id" : "";

if ($q !== '') {
  // search doc_no and customer name (if available)
  $where .= " AND (s.doc_no LIKE ? ";
  $params[] = '%' . $q . '%';
  $types .= "s";

  if ($hasCustomers) {
    $where .= " OR c.name LIKE ? ";
    $params[] = '%' . $q . '%';
    $types .= "s";
  }
  $where .= ")";
}

// ---- total count ----
$sqlCount = "SELECT COUNT(*) AS cnt
             FROM sales s
             $customerJoin
             WHERE $where";

$stC = $db->prepare($sqlCount);
if (!$stC) die("Count query failed: " . $db->error);

if ($types !== "") $stC->bind_param($types, ...$params);
$stC->execute();
$total = (int)($stC->get_result()->fetch_assoc()['cnt'] ?? 0);
$stC->close();

$total_pages = max(1, (int)ceil($total / $per_page));

// ---- list query ----
$sql = "SELECT
          s.id, s.doc_type, s.doc_no, s.selling_location_id,
          s.status, s.payment_status, s.currency,
          s.subtotal, s.discount_total, s.tax_total, s.grand_total,
          s.amount_paid, s.balance, s.created_at
          $customerSelect
        FROM sales s
        $customerJoin
        WHERE $where
        ORDER BY s.id DESC
        LIMIT ? OFFSET ?";

$st = $db->prepare($sql);
if (!$st) die("List query failed: " . $db->error);

// bind params + limit/offset
$types2 = $types . "ii";
$params2 = array_merge($params, [$per_page, $offset]);

$st->bind_param($types2, ...$params2);
$st->execute();
$rs = $st->get_result();
$rows = [];
while ($r = $rs->fetch_assoc()) $rows[] = $r;
$st->close();

// ---- load locations for filter (optional) ----
$locations = [];
$hasLoc = $db->query("SHOW TABLES LIKE 'selling_locations'")?->num_rows ?? 0;
if ($hasLoc) {
  $lr = $db->query("SELECT id, name FROM selling_locations ORDER BY name ASC");
  if ($lr) while ($x = $lr->fetch_assoc()) $locations[] = $x;
}

// ---- VOID/UNVOID action handler ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['do_void'])) {
  if (!$canVoid) {
    http_response_code(403);
    die("Forbidden");
  }

  $token = (string)($_POST['csrf'] ?? '');
  if (empty($_SESSION['csrf']) || !hash_equals($_SESSION['csrf'], $token)) {
    http_response_code(403);
    die("CSRF failed");
  }

  $void_id = (int)($_POST['sale_id'] ?? 0);
  $action = (string)($_POST['void_action'] ?? 'void');
  
  if ($void_id > 0) {
    if ($action === 'void') {
      $stV = $db->prepare("UPDATE sales SET status='voided' WHERE id=? AND status!='voided' LIMIT 1");
      if ($stV) {
        $stV->bind_param('i', $void_id);
        $stV->execute();
        $stV->close();
      }
    } elseif ($action === 'unvoid') {
      $stV = $db->prepare("UPDATE sales SET status='confirmed' WHERE id=? AND status='voided' LIMIT 1");
      if ($stV) {
        $stV->bind_param('i', $void_id);
        $stV->execute();
        $stV->close();
      }
    }
  }

  // redirect back (preserve query string)
  $qs = $_SERVER['QUERY_STRING'] ?? '';
  $baseUrl = $GLOBALS['BASE_URL'] ?? '';
  header("Location: " . $baseUrl . "/modules/pos/sales_history.php" . ($qs ? ("?$qs") : ""));
  exit;
}

// Build pagination links keeping filters
function build_qs(array $overrides = []): string {
  $base = $_GET;
  foreach ($overrides as $k => $v) {
    if ($v === null || $v === '') unset($base[$k]);
    else $base[$k] = $v;
  }
  return http_build_query($base);
}

$page_title = "Sales History";
$page_subtitle = "Search, filter, and manage your sales records";

require_once __DIR__ . '/../../templates/layout/header.php';
?>

<div class="app-shell">
  <?php require_once __DIR__ . '/../../templates/layout/sidebar.php'; ?>
  <div class="app-content">
    <?php require_once __DIR__ . '/../../templates/layout/topbar.php'; ?>
    <main class="page-wrap">
      <!-- Bootstrap Icons -->
      <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css">
      <style>
        .sales-header {
          background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
          color: white;
          padding: 2rem 0;
          margin-bottom: 2rem;
          border-radius: var(--card-radius);
        }
        .filter-card {
          border: none;
          box-shadow: 0 2px 12px rgba(0,0,0,0.08);
          border-radius: var(--card-radius);
        }
        .table-card {
          border: none;
          box-shadow: 0 4px 20px rgba(0,0,0,0.08);
          border-radius: var(--card-radius);
          overflow: hidden;
        }
        .table th {
          border-bottom: 2px solid #e9ecef;
          font-weight: 600;
          color: #495057;
          background-color: #f8f9fa;
        }
        .status-badge {
          font-size: 0.75rem;
          padding: 0.375rem 0.75rem;
          border-radius: 50px;
          font-weight: 500;
        }
        .action-buttons {
          display: flex;
          gap: 0.5rem;
          justify-content: flex-end;
        }
        .btn-action {
          padding: 0.375rem 0.5rem;
          border-radius: 8px;
          transition: all 0.2s;
        }
        .btn-action:hover {
          transform: translateY(-1px);
          box-shadow: 0 2px 8px rgba(0,0,0,0.15);
        }
        .pagination .page-link {
          border-radius: 8px;
          margin: 0 2px;
          border: none;
          color: #667eea;
        }
        .pagination .page-item.active .page-link {
          background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        }
        .summary-stats {
          display: flex;
          gap: 1rem;
          margin-bottom: 1rem;
        }
        .stat-card {
          background: white;
          padding: 1rem;
          border-radius: 12px;
          box-shadow: 0 2px 8px rgba(0,0,0,0.06);
          flex: 1;
          text-align: center;
        }
        .stat-value {
          font-size: 1.5rem;
          font-weight: 700;
          color: #667eea;
        }
        .stat-label {
          font-size: 0.875rem;
          color: #6c757d;
          margin-top: 0.25rem;
        }
        @media (max-width: 768px) {
          .summary-stats {
            flex-direction: column;
          }
          .filter-card .row {
            gap: 0.5rem;
          }
        }
      </style>

      <!-- Page Header -->
      <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
          <h2 class="mb-1"><?= h2($page_title) ?></h2>
          <p class="text-muted mb-0"><?= h2($page_subtitle) ?></p>
        </div>
        <div>
          <a href="<?= h2((string)($GLOBALS['BASE_URL'] ?? '')) ?>/modules/pos/pos.php" class="btn btn-primary">
            <i class="bi bi-cart-plus me-1"></i> New Sale
          </a>
        </div>
      </div>

      <!-- Summary Stats -->
      <div class="summary-stats">
        <div class="stat-card">
          <div class="stat-value"><?= $total ?></div>
          <div class="stat-label">Total Sales</div>
        </div>
        <div class="stat-card">
          <div class="stat-value"><?= count($rows) ?></div>
          <div class="stat-label">Showing</div>
        </div>
        <div class="stat-card">
          <div class="stat-value"><?= $total_pages ?></div>
          <div class="stat-label">Pages</div>
        </div>
        <div class="stat-card">
          <div class="stat-value"><?= $page ?></div>
          <div class="stat-label">Current Page</div>
        </div>
      </div>

      <!-- Filters -->
      <form class="card filter-card mb-4" method="get" action="">
        <div class="card-header bg-white py-3">
          <h6 class="mb-0"><i class="bi bi-funnel me-2"></i>Filters</h6>
        </div>
        <div class="card-body">
          <div class="row g-2">
            <div class="col-md-3">
              <label class="form-label small">Search (Doc No / Customer)</label>
              <input type="text" name="q" value="<?= h2($q) ?>" class="form-control" placeholder="RC-2026..., customer...">
            </div>

            <div class="col-md-2">
              <label class="form-label small">Doc Type</label>
              <select name="doc_type" class="form-select">
                <option value="">All</option>
                <?php foreach ($allowed_doc as $dt): ?>
                  <option value="<?= h2($dt) ?>" <?= $doc_type===$dt?'selected':'' ?>><?= h2($dt) ?></option>
                <?php endforeach; ?>
              </select>
            </div>

            <div class="col-md-2">
              <label class="form-label small">Status</label>
              <select name="status" class="form-select">
                <option value="">All</option>
                <?php foreach ($allowed_status as $stt): ?>
                  <option value="<?= h2($stt) ?>" <?= $status===$stt?'selected':'' ?>><?= h2($stt) ?></option>
                <?php endforeach; ?>
              </select>
            </div>

            <div class="col-md-2">
              <label class="form-label small">Payment</label>
              <select name="payment_status" class="form-select">
                <option value="">All</option>
                <?php foreach ($allowed_pay as $ps): ?>
                  <option value="<?= h2($ps) ?>" <?= $payment_status===$ps?'selected':'' ?>><?= h2($ps) ?></option>
                <?php endforeach; ?>
              </select>
            </div>

            <div class="col-md-3">
              <label class="form-label small">Location</label>
              <select name="location_id" class="form-select">
                <option value="0">All</option>
                <?php foreach ($locations as $loc): ?>
                  <option value="<?= (int)$loc['id'] ?>" <?= $location_id===(int)$loc['id']?'selected':'' ?>>
                    <?= h2((string)$loc['name']) ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </div>

            <div class="col-md-2">
              <label class="form-label small">From</label>
              <input type="date" name="from" value="<?= h2($from) ?>" class="form-control">
            </div>

            <div class="col-md-2">
              <label class="form-label small">To</label>
              <input type="date" name="to" value="<?= h2($to) ?>" class="form-control">
            </div>

            <div class="col-md-2">
              <label class="form-label small">Per Page</label>
              <select name="per_page" class="form-select">
                <?php foreach ([15,25,50,100] as $pp): ?>
                  <option value="<?= $pp ?>" <?= $per_page===$pp?'selected':'' ?>><?= $pp ?></option>
                <?php endforeach; ?>
              </select>
            </div>

            <div class="col-md-4 d-flex align-items-end gap-2">
              <button class="btn btn-dark" type="submit">
                <i class="bi bi-funnel me-1"></i> Apply
              </button>
              <a class="btn btn-outline-secondary" href="<?= h2((string)($GLOBALS['BASE_URL'] ?? '')) ?>/modules/pos/sales_history.php">
                Reset
              </a>
            </div>
          </div>
        </div>
      </form>

      <!-- Table -->
      <div class="card table-card">
        <div class="card-header bg-white py-3">
          <h6 class="mb-0"><i class="bi bi-table me-2"></i>Sales Records</h6>
        </div>
        <div class="table-responsive">
          <table class="table table-hover table-sm align-middle mb-0">
            <thead class="table-light">
              <tr>
                <th>ID</th>
                <th>Doc</th>
                <th>Customer</th>
                <th>Location</th>
                <th>Status</th>
                <th>Payment</th>
                <th class="text-end">Total</th>
                <th class="text-end">Paid</th>
                <th class="text-end">Balance</th>
                <th>Date</th>
                <th class="text-end">Actions</th>
              </tr>
            </thead>
            <tbody>
            <?php if (!$rows): ?>
              <tr><td colspan="11" class="text-muted p-3">No sales found.</td></tr>
            <?php else: foreach ($rows as $r): ?>
              <?php
                $statusBadge = match ((string)$r['status']) {
                  'confirmed' => 'success',
                  'voided' => 'danger',
                  'draft' => 'secondary',
                  default => 'secondary'
                };
                $payBadge = match ((string)$r['payment_status']) {
                  'paid' => 'success',
                  'partial' => 'warning',
                  'unpaid' => 'secondary',
                  default => 'secondary'
                };
              ?>
              <tr>
                <td class="text-muted"><?= (int)$r['id'] ?></td>
                <td>
                  <div class="fw-semibold"><?= h2((string)$r['doc_no']) ?></div>
                  <div class="text-muted small"><?= h2((string)$r['doc_type']) ?></div>
                </td>
                <td><?= h2((string)($r['customer_name'] ?? 'Walk-in Customer')) ?></td>
                <td class="text-muted"><?= (int)$r['selling_location_id'] ?></td>
                <td><span class="badge status-badge bg-<?= $statusBadge ?>"><?= h2((string)$r['status']) ?></span></td>
                <td><span class="badge status-badge bg-<?= $payBadge ?>"><?= h2((string)$r['payment_status']) ?></span></td>
                <td class="text-end fw-semibold"><?= money($r['grand_total'] ?? 0) ?></td>
                <td class="text-end"><?= money($r['amount_paid'] ?? 0) ?></td>
                <td class="text-end fw-bold"><?= money($r['balance'] ?? 0) ?></td>
                <td class="text-muted small"><?= h2((string)$r['created_at']) ?></td>
                <td class="text-end">
                  <div class="action-buttons">
                    <a class="btn btn-sm btn-outline-primary btn-action"
                       target="_blank"
                       href="<?= h2((string)($GLOBALS['BASE_URL'] ?? '')) ?>/modules/pos/pos_preview.php?id=<?= (int)$r['id'] ?>"
                       title="View Receipt">
                      <i class="bi bi-receipt"></i>
                    </a>

                    <?php if ($canVoid): ?>
                      <?php if ((string)$r['status'] !== 'voided'): ?>
                        <form method="post" action="?<?= h2($_SERVER['QUERY_STRING'] ?? '') ?>" class="d-inline"
                              onsubmit="return confirm('Void this sale? This will mark it as voided.');">
                          <input type="hidden" name="csrf" value="<?= h2($csrf) ?>">
                          <input type="hidden" name="sale_id" value="<?= (int)$r['id'] ?>">
                          <input type="hidden" name="void_action" value="void">
                          <button type="submit" name="do_void" value="1" class="btn btn-sm btn-outline-danger btn-action" 
                                  title="Void Sale">
                            <i class="bi bi-x-circle"></i>
                          </button>
                        </form>
                      <?php else: ?>
                        <form method="post" action="?<?= h2($_SERVER['QUERY_STRING'] ?? '') ?>" class="d-inline"
                              onsubmit="return confirm('Restore this sale? This will mark it as confirmed.');">
                          <input type="hidden" name="csrf" value="<?= h2($csrf) ?>">
                          <input type="hidden" name="sale_id" value="<?= (int)$r['id'] ?>">
                          <input type="hidden" name="void_action" value="unvoid">
                          <button type="submit" name="do_void" value="1" class="btn btn-sm btn-outline-success btn-action" 
                                  title="Restore Sale">
                            <i class="bi bi-arrow-counterclockwise"></i>
                          </button>
                        </form>
                      <?php endif; ?>
                    <?php endif; ?>
                  </div>
                </td>
              </tr>
            <?php endforeach; endif; ?>
            </tbody>
          </table>
        </div>

        <!-- Pagination -->
        <div class="card-body d-flex justify-content-between align-items-center">
          <div class="text-muted small">
            Page <?= $page ?> of <?= $total_pages ?>
          </div>
          <nav>
            <ul class="pagination pagination-sm mb-0">
              <li class="page-item <?= $page<=1?'disabled':'' ?>">
                <a class="page-link" href="?<?= h2(build_qs(['page' => max(1, $page-1)])) ?>">Prev</a>
              </li>

              <?php
                $start = max(1, $page - 2);
                $end = min($total_pages, $page + 2);
                for ($p = $start; $p <= $end; $p++):
              ?>
                <li class="page-item <?= $p===$page?'active':'' ?>">
                  <a class="page-link" href="?<?= h2(build_qs(['page'=>$p])) ?>"><?= $p ?></a>
                </li>
              <?php endfor; ?>

              <li class="page-item <?= $page>=$total_pages?'disabled':'' ?>">
                <a class="page-link" href="?<?= h2(build_qs(['page' => min($total_pages, $page+1)])) ?>">Next</a>
              </li>
            </ul>
          </nav>
        </div>
      </div>
    </main>
  </div>
</div>

<?php require_once __DIR__ . '/../../templates/layout/footer.php'; ?>