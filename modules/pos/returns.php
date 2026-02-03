<?php
// modules/pos/returns.php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/rbac.php';
require_once __DIR__ . '/../../includes/helpers.php';

// -------------------------
// Session + auth (no require_admin_login dependency)
// -------------------------
if (session_status() === PHP_SESSION_NONE) {
  $sid = $_COOKIE['BMSESSID'] ?? $_COOKIE['PHPSESSID'] ?? null;
  if ($sid) session_id($sid);
  session_start();
}

if (empty($_SESSION['user']['id'])) {
  http_response_code(401);
  die("Authentication required");
}

// Permission: prefer pos.view, fallback to pos.create
if (function_exists('user_has_permission')) {
  $canView = user_has_permission('pos.view') || user_has_permission('pos.create');
  if (!$canView) {
    http_response_code(403);
    die("Forbidden");
  }
}

$db = $GLOBALS['db'] ?? null;
if (!$db instanceof mysqli) {
  http_response_code(500);
  die("DB not available");
}

function h2(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }
function money($n): string { return number_format((float)$n, 0, '.', ','); }

function table_exists(mysqli $db, string $table): bool {
  $result = $db->query("SHOW TABLES LIKE '" . $db->real_escape_string($table) . "'");
  if (!$result) return false;
  return $result->num_rows > 0;
}

function column_exists(mysqli $db, string $table, string $col): bool {
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

function build_qs(array $overrides = []): string {
  $base = $_GET;
  foreach ($overrides as $k => $v) {
    if ($v === null || $v === '') unset($base[$k]);
    else $base[$k] = $v;
  }
  return http_build_query($base);
}

$BASE = rtrim((string)($GLOBALS['BASE_URL'] ?? ''), '/');
$page_title = "Returns";
$page_subtitle = "Track returned items and manage refunds";

// -------------------------
// Filters
// -------------------------
$q = trim((string)($_GET['q'] ?? ''));
$location_id = (int)($_GET['location_id'] ?? 0);
$from = trim((string)($_GET['from'] ?? ''));
$to = trim((string)($_GET['to'] ?? ''));

$per_page = (int)($_GET['per_page'] ?? 25);
if (!in_array($per_page, [15,25,50,100], true)) $per_page = 25;

$page = (int)($_GET['page'] ?? 1);
if ($page < 1) $page = 1;
$offset = ($page - 1) * $per_page;

// -------------------------
// Locations (optional)
// -------------------------
$locations = [];
$hasLoc = table_exists($db, 'selling_locations');
if ($hasLoc) {
  $lr = $db->query("SELECT id, name FROM selling_locations ORDER BY name ASC");
  if ($lr) while ($x = $lr->fetch_assoc()) $locations[] = $x;
}

// -------------------------
// Prefer: sale_returns table
// -------------------------
$rows = [];
$total = 0;
$total_pages = 1;

$hasReturnsTable = table_exists($db, 'sale_returns');

if ($hasReturnsTable) {
  // Detect common columns
  $hasReturnNo   = column_exists($db, 'sale_returns', 'return_no');
  $hasReason     = column_exists($db, 'sale_returns', 'reason');
  $hasRefund     = column_exists($db, 'sale_returns', 'refund_amount');
  $hasStatus     = column_exists($db, 'sale_returns', 'status');
  $hasLocId      = column_exists($db, 'sale_returns', 'selling_location_id');

  // Base WHERE
  $where = "1=1";
  $params = [];
  $types = "";

  if ($location_id > 0 && $hasLocId) {
    $where .= " AND r.selling_location_id = ?";
    $params[] = $location_id;
    $types .= "i";
  }

  if ($from !== '') {
    $where .= " AND r.created_at >= ?";
    $params[] = $from . " 00:00:00";
    $types .= "s";
  }
  if ($to !== '') {
    $where .= " AND r.created_at <= ?";
    $params[] = $to . " 23:59:59";
    $types .= "s";
  }

  if ($q !== '') {
    $where .= " AND (CAST(r.id AS CHAR) LIKE ? ";
    $params[] = '%' . $q . '%';
    $types .= "s";

    if ($hasReturnNo) {
      $where .= " OR r.return_no LIKE ? ";
      $params[] = '%' . $q . '%';
      $types .= "s";
    }

    // search original sale doc number if joined
    $where .= " OR s.doc_no LIKE ? ";
    $params[] = '%' . $q . '%';
    $types .= "s";

    $where .= ")";
  }

  // Check for locations table
  $hasLocationsTable = table_exists($db, 'locations');
  $hasSellingLocationsTable = table_exists($db, 'selling_locations');

  // Count query with location joins
  $locationJoin = "";
  if ($hasLocationsTable) {
    $locationJoin = " LEFT JOIN locations l ON l.id = r.selling_location_id ";
  } elseif ($hasSellingLocationsTable) {
    $locationJoin = " LEFT JOIN selling_locations l ON l.id = r.selling_location_id ";
  }

  $sqlCount = "SELECT COUNT(*) AS cnt
               FROM sale_returns r
               LEFT JOIN sales s ON s.id = r.sale_id
               $locationJoin
               WHERE $where";
  $stC = $db->prepare($sqlCount);
  if (!$stC) die("Count query failed: " . $db->error);
  if ($types !== "") $stC->bind_param($types, ...$params);
  $stC->execute();
  $total = (int)($stC->get_result()->fetch_assoc()['cnt'] ?? 0);
  $stC->close();

  $total_pages = max(1, (int)ceil($total / $per_page));

  // Select
  $selectReturnNo = $hasReturnNo ? "r.return_no" : "NULL AS return_no";
  $selectReason   = $hasReason ? "r.reason" : "NULL AS reason";
  $selectRefund   = $hasRefund ? "r.refund_amount" : "0 AS refund_amount";
  $selectStatus   = $hasStatus ? "r.status" : "NULL AS status";
  $selectLoc      = ($hasLocId) ? "r.selling_location_id" : "s.selling_location_id";
  
  // Location name selection
  $selectLocName = "NULL AS location_name";
  if ($hasLocationsTable) {
    $selectLocName = "l.name AS location_name";
  } elseif ($hasSellingLocationsTable) {
    $selectLocName = "l.name AS location_name";
  }

  $sql = "SELECT
            r.id,
            r.sale_id,
            $selectReturnNo,
            $selectStatus,
            $selectLoc AS selling_location_id,
            $selectLocName,
            $selectRefund,
            $selectReason,
            r.created_at,
            s.doc_no AS sale_doc_no,
            s.doc_type AS sale_doc_type,
            s.grand_total AS sale_total
          FROM sale_returns r
          LEFT JOIN sales s ON s.id = r.sale_id
          $locationJoin
          WHERE $where
          ORDER BY r.id DESC
          LIMIT ? OFFSET ?";

  $st = $db->prepare($sql);
  if (!$st) die("List query failed: " . $db->error);

  $types2 = $types . "ii";
  $params2 = array_merge($params, [$per_page, $offset]);
  $st->bind_param($types2, ...$params2);
  $st->execute();
  $rs = $st->get_result();
  while ($r = $rs->fetch_assoc()) $rows[] = $r;
  $st->close();

} else {
  // -------------------------
  // Fallback: returns encoded in sales table (best-effort)
  // Looks for doc_type='return' OR status='returned' if those exist.
  // -------------------------
  $hasDocTypeReturn = column_exists($db, 'sales', 'doc_type');
  $hasStatusCol = column_exists($db, 'sales', 'status');

  $where = "1=1";
  $params = [];
  $types = "";

  $fallbackClauses = [];

  if ($hasDocTypeReturn) $fallbackClauses[] = "s.doc_type = 'return'";
  if ($hasStatusCol) $fallbackClauses[] = "s.status = 'returned'";

  // If neither exists, no returns possible
  if (!$fallbackClauses) {
    $rows = [];
    $total = 0;
    $total_pages = 1;
  } else {
    $where .= " AND (" . implode(" OR ", $fallbackClauses) . ")";

    if ($location_id > 0) {
      $where .= " AND s.selling_location_id = ?";
      $params[] = $location_id;
      $types .= "i";
    }

    if ($from !== '') {
      $where .= " AND s.created_at >= ?";
      $params[] = $from . " 00:00:00";
      $types .= "s";
    }
    if ($to !== '') {
      $where .= " AND s.created_at <= ?";
      $params[] = $to . " 23:59:59";
      $types .= "s";
    }

    $hasCustomers = $db->query("SHOW TABLES LIKE 'customers'")?->num_rows ?? 0;
    $customerSelect = $hasCustomers ? ", c.name AS customer_name" : ", NULL AS customer_name";
    $customerJoin = $hasCustomers ? " LEFT JOIN customers c ON c.id = s.customer_id" : "";

    if ($q !== '') {
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

    // Count
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

    // Select
    $sql = "SELECT
              s.id,
              s.doc_no,
              s.doc_type,
              s.status,
              s.selling_location_id,
              s.grand_total,
              s.created_at
              $customerSelect
            FROM sales s
            $customerJoin
            WHERE $where
            ORDER BY s.id DESC
            LIMIT ? OFFSET ?";

    $st = $db->prepare($sql);
    if (!$st) die("List query failed: " . $db->error);

    $types2 = $types . "ii";
    $params2 = array_merge($params, [$per_page, $offset]);
    $st->bind_param($types2, ...$params2);
    $st->execute();
    $rs = $st->get_result();
    while ($r = $rs->fetch_assoc()) $rows[] = $r;
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
      <!-- Bootstrap Icons -->
      <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css">
      <style>
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
          color: #dc3545;
        }
        .stat-label {
          font-size: 0.875rem;
          color: #6c757d;
          margin-top: 0.25rem;
        }
        .returns-header {
          background: linear-gradient(135deg, #ff6b6b 0%, #dc3545 100%);
          color: white;
          padding: 2rem 0;
          margin-bottom: 2rem;
          border-radius: var(--card-radius);
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

      <!-- Add Return Modal -->
      <div class="modal fade" id="addReturnModal" tabindex="-1" aria-labelledby="addReturnModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-lg">
          <div class="modal-content">
            <div class="modal-header">
              <h5 class="modal-title" id="addReturnModalLabel">
                <i class="bi bi-arrow-counterclockwise me-2"></i>Create New Return
              </h5>
              <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
              <form id="returnForm">
                <div class="row g-3">
                  <div class="col-md-6 position-relative">
  <label for="receiptNo" class="form-label">Receipt Number</label>
  <input type="text" class="form-control" id="receiptNo" name="receipt_no" required
         placeholder="Type receipt (e.g. RC-2026...)">
  <div id="receiptSuggestions" class="list-group position-absolute w-100 d-none" style="z-index:1056;"></div>
  <div class="form-text">Start typing to search receipts.</div>
</div>
                  
                  
                  
                  <div class="col-md-12">
                    <label for="reason" class="form-label">Return Reason</label>
                    <textarea class="form-control" id="reason" name="reason" rows="3" required
                              placeholder="Enter the reason for this return..."></textarea>
                  </div>
                  
                  <div class="col-md-6">
                    <label class="form-label">Refund</label>
                    <div class="form-check mb-2">
                      <input class="form-check-input" type="checkbox" id="refundedCheck">
                      <label class="form-check-label" for="refundedCheck">
                        Refunded
                      </label>
                    </div>

                    <input type="hidden" name="refunded" id="refunded" value="0">

                    <div class="input-group">
                      <span class="input-group-text">$</span>
                      <input type="number" class="form-control" id="refundAmount" name="refund_amount"
                             placeholder="0.00" step="0.01" min="0" disabled>
                    </div>
                    <div class="form-text">Enable “Refunded” to enter amount.</div>
                  </div>
                  
                  <div class="col-md-6">
                    <label for="status" class="form-label">Return Status <span class="text-danger">*</span></label>
                    <select class="form-select" id="status" name="status" required>
                      <option value="">Select Status</option>
                      <option value="pending">Pending</option>
                      <option value="approved">Approved</option>
                      <option value="completed">Completed</option>
                      <option value="rejected">Rejected</option>
                    </select>
                  </div>
                  
                  <div class="col-md-6">
                    <label for="locationId" class="form-label">Location <span class="text-danger">*</span></label>
                    <select class="form-select" id="locationId" name="selling_location_id" required>
                      <option value="">Select Location</option>
                    </select>
                  </div>
                  
                  <div class="col-md-6">
                    <label for="returnDate" class="form-label">Return Date</label>
                    <input type="date" class="form-control" id="returnDate" name="return_date"
                           value="<?= date('Y-m-d') ?>" max="<?= date('Y-m-d') ?>">
                  </div>
                </div>
                
                <!-- Sale Details Preview -->
                <div id="saleDetails" class="alert alert-info d-none">
                  <h6>Original Sale Details</h6>
                  <div id="saleInfo"></div>
                </div>
                
                <!-- Items to Return -->
                <div id="itemsSection" class="d-none">
                  <h6 class="mb-3">Items to Return</h6>
                  <div id="itemsList"></div>
                </div>
              </form>
            </div>
            <div class="modal-footer">
              <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
              <button type="button" class="btn btn-primary" id="saveReturnBtn">
                <i class="bi bi-save me-1"></i> Save Return
              </button>
            </div>
          </div>
        </div>
      </div>

      <!-- Edit Return Modal -->
      <div class="modal fade" id="editReturnModal" tabindex="-1" aria-labelledby="editReturnModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-lg">
          <div class="modal-content">
            <div class="modal-header">
              <h5 class="modal-title" id="editReturnModalLabel">
                <i class="bi bi-pencil me-2"></i>Edit Return
              </h5>
              <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
              <form id="editReturnForm">
                <input type="hidden" id="editReturnId" name="return_id">
                <div class="row g-3">
                  <div class="col-md-12">
                    <label for="editReason" class="form-label">Return Reason</label>
                    <textarea class="form-control" id="editReason" name="reason" rows="3" required
                              placeholder="Enter the reason for this return..."></textarea>
                  </div>

                  <div class="col-md-6">
                    <label class="form-label">Refund</label>
                    <div class="form-check mb-2">
                      <input class="form-check-input" type="checkbox" id="editRefundedCheck">
                      <label class="form-check-label" for="editRefundedCheck">
                        Refunded
                      </label>
                    </div>
                    <input type="hidden" name="refunded" id="editRefunded" value="0">
                    <div class="input-group">
                      <span class="input-group-text">$</span>
                      <input type="number" class="form-control" id="editRefundAmount" name="refund_amount"
                             placeholder="0.00" step="0.01" min="0" disabled>
                    </div>
                    <div class="form-text">Enable "Refunded" to enter amount.</div>
                  </div>

                  <div class="col-md-6">
                    <label for="editStatus" class="form-label">Return Status <span class="text-danger">*</span></label>
                    <select class="form-select" id="editStatus" name="status" required>
                      <option value="">Select Status</option>
                      <option value="pending">Pending</option>
                      <option value="approved">Approved</option>
                      <option value="completed">Completed</option>
                      <option value="rejected">Rejected</option>
                    </select>
                  </div>

                  <div class="col-md-6">
                    <label for="editLocationId" class="form-label">Location <span class="text-danger">*</span></label>
                    <select class="form-select" id="editLocationId" name="selling_location_id" required>
                      <option value="">Select Location</option>
                    </select>
                  </div>

                  <div class="col-md-6">
                    <label for="editReturnDate" class="form-label">Return Date</label>
                    <input type="date" class="form-control" id="editReturnDate" name="return_date"
                           value="<?= date('Y-m-d') ?>">
                  </div>
                </div>

                <!-- Current Items -->
                <div id="editItemsSection" class="mt-3">
                  <h6 class="mb-3">Current Return Items</h6>
                  <div id="editItemsList"></div>
                </div>
              </form>
            </div>
            <div class="modal-footer">
              <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
              <button type="button" class="btn btn-warning" id="updateReturnBtn">
                <i class="bi bi-save me-1"></i> Update Return
              </button>
            </div>
          </div>
        </div>
      </div>

      <!-- Delete Return Modal -->
      <div class="modal fade" id="deleteReturnModal" tabindex="-1" aria-labelledby="deleteReturnModalLabel" aria-hidden="true">
        <div class="modal-dialog">
          <div class="modal-content">
            <div class="modal-header">
              <h5 class="modal-title" id="deleteReturnModalLabel">
                <i class="bi bi-trash me-2"></i>Delete Return
              </h5>
              <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
              <input type="hidden" id="deleteReturnId">
              <div class="alert alert-warning">
                <i class="bi bi-exclamation-triangle me-2"></i>
                <strong>Warning:</strong> This action cannot be undone.
              </div>
              <p>Are you sure you want to delete this return? This will:</p>
              <ul>
                <li>Remove the return record permanently</li>
                <li>Reverse any stock updates if applicable</li>
                <li>Cannot be recovered once deleted</li>
              </ul>
              <div id="deleteReturnInfo"></div>
            </div>
            <div class="modal-footer">
              <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
              <button type="button" class="btn btn-danger" id="confirmDeleteBtn">
                <i class="bi bi-trash me-1"></i> Delete Return
              </button>
            </div>
          </div>
        </div>
      </div>

      <!-- Add Return Button -->
      <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
          <h2 class="mb-1">
            <i class="bi bi-arrow-counterclockwise me-2"></i><?= h2($page_title) ?>
          </h2>
          <p class="text-muted mb-0"><?= h2($page_subtitle) ?></p>
        </div>
        <div class="d-flex gap-2">
          <button class="btn btn-success" data-bs-toggle="modal" data-bs-target="#addReturnModal">
            <i class="bi bi-plus-circle me-1"></i> New Return
          </button>
          <a href="<?= h2((string)($GLOBALS['BASE_URL'] ?? '')) ?>/modules/pos/pos.php" class="btn btn-primary">
            <i class="bi bi-cart-plus me-1"></i> New Sale
          </a>
          <a href="<?= h2((string)($GLOBALS['BASE_URL'] ?? '')) ?>/modules/pos/sales_history.php" class="btn btn-outline-secondary">
            <i class="bi bi-clock-history me-1"></i> History
          </a>
        </div>
      </div>

      <!-- Summary Stats -->
      <div class="summary-stats">
        <div class="stat-card">
          <div class="stat-value"><?= $total ?></div>
          <div class="stat-label">Total Returns</div>
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
              <label class="form-label small">Search (Return No / Sale Doc / Customer)</label>
              <input type="text" name="q" value="<?= h2($q) ?>" class="form-control" placeholder="RET-..., RC-..., customer...">
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
              <a class="btn btn-outline-secondary" href="<?= h2((string)($GLOBALS['BASE_URL'] ?? '')) ?>/modules/pos/returns.php">
                Reset
              </a>
            </div>
          </div>
        </div>
      </form>

      <!-- Summary -->
      <div class="d-flex justify-content-between align-items-center mb-2">
        <div class="text-muted small">
          Showing <strong><?= count($rows) ?></strong> of <strong><?= $total ?></strong> returns
          (Page <?= (int)$page ?> / <?= (int)$total_pages ?>)
        </div>
        <div class="text-muted small">
          <?php if ($hasReturnsTable): ?>
            <span class="badge bg-success">sale_returns table exists</span>
          <?php else: ?>
            <span class="badge bg-warning">sale_returns table missing</span>
          <?php endif; ?>
        </div>
      </div>

      <!-- Table -->
      <div class="card table-card">
        <div class="card-header bg-white py-3">
          <h6 class="mb-0"><i class="bi bi-arrow-counterclockwise me-2"></i>Returns Records</h6>
        </div>
        <div class="table-responsive">
          <table class="table table-hover table-sm align-middle mb-0">
            <thead class="table-light">
              <?php if ($hasReturnsTable): ?>
              <tr>
                <th>Return ID</th>
                <th>Return No</th>
                <th>Original Sale</th>
                <th>Location</th>
                <th>Status</th>
                <th class="text-end">Refund</th>
                <th class="text-muted">Reason</th>
                <th>Date</th>
                <th class="text-end">Open</th>
              </tr>
              <?php else: ?>
              <tr>
                <th>Sale ID</th>
                <th>Doc</th>
                <th>Customer</th>
                <th>Location</th>
                <th>Status</th>
                <th class="text-end">Total</th>
                <th>Date</th>
                <th class="text-end">Open</th>
              </tr>
              <?php endif; ?>
            </thead>
            <tbody>
            <?php if (!$rows): ?>
              <tr>
                <td colspan="<?= $hasReturnsTable ? 9 : 8 ?>" class="text-muted p-3">
                  No returns found.
                  <?php if (!$hasReturnsTable): ?>
                    <div class="small mt-1">
                      Tip: create a <code>sale_returns</code> table for clean returns tracking.
                    </div>
                  <?php endif; ?>
                </td>
              </tr>
            <?php else: foreach ($rows as $r): ?>
              <?php if ($hasReturnsTable): ?>
                <?php
                  $st = (string)($r['status'] ?? '');
                  // Debug: show the actual status value
                  $badge = 'secondary';
                  if ($st === 'approved' || $st === 'completed') $badge = 'success';
                  if ($st === 'pending' || $st === '' || $st === 'n/a') $badge = 'warning'; // Treat empty/n/a as pending
                  if ($st === 'rejected' || $st === 'cancelled') $badge = 'danger';
                ?>
                <tr>
                  <td class="text-muted"><?= (int)$r['id'] ?></td>
                  <td><?= h2((string)($r['return_no'] ?? '')) ?></td>
                  <td>
                    <div class="fw-semibold"><?= h2((string)($r['sale_doc_no'] ?? ('Sale #' . (int)$r['sale_id']))) ?></div>
                    <div class="text-muted small"><?= h2((string)($r['sale_doc_type'] ?? '')) ?></div>
                  </td>
                  <td class="text-muted"><?= h2((string)($r['location_name'] ?? ('Location #' . (int)($r['selling_location_id'] ?? 0)))) ?></td>
                  <td>
                    <span class="badge bg-<?= $badge ?>"><?= h2($st ?: 'pending') ?></span>
                  </td>
                  <td class="text-end fw-semibold"><?= money($r['refund_amount'] ?? 0) ?></td>
                  <td class="text-muted small"><?= h2((string)($r['reason'] ?? '')) ?></td>
                  <td class="text-muted small"><?= h2((string)($r['created_at'] ?? '')) ?></td>
                  <td>
                    <?php
                      $saleId = (int)($r['sale_id'] ?? 0);
                      $returnId = (int)($r['id'] ?? 0);
                      // Updated logic: treat empty/n/a as pending
                      $canEdit = ($st === 'pending' || $st === 'approved' || $st === '' || $st === 'n/a');
                      $canDelete = ($st === 'pending' || $st === '' || $st === 'n/a');
                    ?>
                    <div class="action-buttons">
                      <a class="btn btn-sm btn-outline-primary btn-action" target="_blank"
                         href="<?= h2((string)($GLOBALS['BASE_URL'] ?? '')) ?>/modules/pos/pos_preview.php?id=<?= $saleId ?>"
                         title="View Receipt">
                        <i class="bi bi-receipt"></i>
                      </a>
                      <?php if ($canEdit): ?>
                        <button class="btn btn-sm btn-outline-warning btn-action" 
                                onclick="editReturn(<?= $returnId ?>)"
                                title="Edit Return">
                          <i class="bi bi-pencil"></i>
                        </button>
                      <?php endif; ?>
                      <?php if ($canDelete): ?>
                        <button class="btn btn-sm btn-outline-danger btn-action" 
                                onclick="deleteReturn(<?= $returnId ?>)"
                                title="Delete Return">
                          <i class="bi bi-trash"></i>
                        </button>
                      <?php endif; ?>
                    </div>
                  </td>
                </tr>
              <?php else: ?>
                <?php
                  $status = (string)($r['status'] ?? '');
                  $badge = match ($status) {
                    'confirmed' => 'success',
                    'voided' => 'danger',
                    'draft' => 'secondary',
                    'returned' => 'warning',
                    default => 'secondary'
                  };
                ?>
                <tr>
                  <td class="text-muted"><?= (int)$r['id'] ?></td>
                  <td>
                    <div class="fw-semibold"><?= h2((string)($r['doc_no'] ?? '')) ?></div>
                    <div class="text-muted small"><?= h2((string)($r['doc_type'] ?? '')) ?></div>
                  </td>
                  <td><?= h2((string)($r['customer_name'] ?? 'Walk-in Customer')) ?></td>
                  <td class="text-muted"><?= (int)($r['selling_location_id'] ?? 0) ?></td>
                  <td><span class="badge bg-<?= $badge ?>"><?= h2($status ?: 'n/a') ?></span></td>
                  <td class="text-end fw-semibold"><?= money($r['grand_total'] ?? 0) ?></td>
                  <td class="text-muted small"><?= h2((string)($r['created_at'] ?? '')) ?></td>
                  <td class="text-end">
                    <div class="action-buttons">
                      <a class="btn btn-sm btn-outline-primary btn-action" target="_blank"
                         href="<?= h2((string)($GLOBALS['BASE_URL'] ?? '')) ?>/modules/pos/pos_preview.php?id=<?= (int)$r['id'] ?>"
                         title="View Receipt">
                        <i class="bi bi-receipt"></i>
                      </a>
                    </div>
                  </td>
                </tr>
              <?php endif; ?>
            <?php endforeach; endif; ?>
            </tbody>
          </table>
        </div>

        <!-- Pagination -->
        <div class="card-body d-flex justify-content-between align-items-center">
          <div class="text-muted small">Page <?= (int)$page ?> of <?= (int)$total_pages ?></div>
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
                  <a class="page-link" href="?<?= h2(build_qs(['page'=>$p])) ?>"><?= (int)$p ?></a>
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

<script>
document.addEventListener('DOMContentLoaded', () => {
  const BASE = '<?= h2((string)($GLOBALS['BASE_URL'] ?? '')) ?>';

  // Modal + elements
  const modalEl = document.getElementById('addReturnModal');
  const modal = new bootstrap.Modal(modalEl);

  const receiptNoInput = document.getElementById('receiptNo');
  const saleDetails = document.getElementById('saleDetails');
  const saleInfo = document.getElementById('saleInfo');
  const itemsSection = document.getElementById('itemsSection');
  const itemsList = document.getElementById('itemsList');
  const saveReturnBtn = document.getElementById('saveReturnBtn');
  const returnForm = document.getElementById('returnForm');
  const locationSelect = document.getElementById('locationId');
  const refundAmountInput = document.getElementById('refundAmount');

  // -----------------------------
  // helpers
  // -----------------------------
  const money = (n) => `$${Number(n || 0).toFixed(2)}`;

  function clearModalAlert() {
    document.querySelector('.modal-alert')?.remove();
  }

  function showAlert(type, message) {
    clearModalAlert();
    const modalBody = modalEl.querySelector('.modal-body');
    const el = document.createElement('div');
    el.className = `alert alert-${type} alert-dismissible fade show modal-alert`;
    el.setAttribute('role', 'alert');
    el.innerHTML = `
      ${message}
      <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    `;
    modalBody.insertAdjacentElement('afterbegin', el);
  }

  async function fetchJson(url, opts = {}) {
    const res = await fetch(url, opts);
    const ct = res.headers.get('content-type') || '';

    if (!res.ok) {
      const text = await res.text().catch(() => '');
      throw new Error(`HTTP ${res.status}${text ? `: ${text.slice(0, 120)}` : ''}`);
    }

    if (!ct.includes('application/json')) {
      const text = await res.text().catch(() => '');
      throw new Error(`Non-JSON response: ${text.slice(0, 200)}`);
    }

    return res.json();
  }

  function resetSaleUI() {
    saleDetails.classList.add('d-none');
    itemsSection.classList.add('d-none');
    saleInfo.innerHTML = '';
    itemsList.innerHTML = '';
  }

  function setSaving(on) {
    if (on) {
      saveReturnBtn.disabled = true;
      saveReturnBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Saving...';
    } else {
      saveReturnBtn.disabled = false;
      saveReturnBtn.innerHTML = '<i class="bi bi-save me-1"></i> Save Return';
    }
  }

  // -----------------------------
  // locations
  // -----------------------------
  async function loadLocations() {
    if (!locationSelect) return;
    locationSelect.innerHTML = '<option value="">Loading locations...</option>';

    try {
      const data = await fetchJson(`${BASE}/api/locations/get_locations.php`);
      if (!data.success) throw new Error(data.message || 'Failed to load locations');

      locationSelect.innerHTML = '<option value="">Select Location</option>';
      (data.locations || []).forEach((loc) => {
        const opt = document.createElement('option');
        opt.value = loc.id;
        opt.textContent = loc.name;
        locationSelect.appendChild(opt);
      });
    } catch (e) {
      console.error(e);
      locationSelect.innerHTML = '<option value="">Error loading locations</option>';
    }
  }

  modalEl.addEventListener('shown.bs.modal', loadLocations);

  // -----------------------------
  // receipt search/suggestions
  // -----------------------------
  const receiptSuggestions = document.getElementById('receiptSuggestions');
  let searchTimeout = null;

  receiptNoInput.addEventListener('input', () => {
    const query = receiptNoInput.value.trim();
    
    if (searchTimeout) clearTimeout(searchTimeout);
    
    if (query.length < 2) {
      receiptSuggestions.classList.add('d-none');
      return;
    }

    searchTimeout = setTimeout(async () => {
      try {
        const data = await fetchJson(`${BASE}/api/sales/search_receipts.php?q=${encodeURIComponent(query)}`);
        
        if (!data.success || !data.results.length) {
          receiptSuggestions.classList.add('d-none');
          return;
        }

        receiptSuggestions.innerHTML = '';
        receiptSuggestions.classList.remove('d-none');

        data.results.forEach((receipt) => {
          const item = document.createElement('a');
          item.className = 'list-group-item list-group-item-action';
          item.href = '#';
          item.innerHTML = `
            <div class="d-flex justify-content-between">
              <strong>${receipt.doc_no}</strong>
              <span class="text-muted">${money(receipt.grand_total)}</span>
            </div>
            <small class="text-muted">
              ${receipt.customer_name || 'Walk-in'} • ${new Date(receipt.created_at).toLocaleDateString()}
            </small>
          `;
          
          item.addEventListener('click', (e) => {
            e.preventDefault();
            receiptNoInput.value = receipt.doc_no;
            receiptSuggestions.classList.add('d-none');
            loadSaleDetails(receipt.doc_no);
          });
          
          receiptSuggestions.appendChild(item);
        });

      } catch (e) {
        console.error('Receipt search error:', e);
        receiptSuggestions.classList.add('d-none');
      }
    }, 300);
  });

  // Hide suggestions when clicking outside
  document.addEventListener('click', (e) => {
    if (!receiptNoInput.contains(e.target) && !receiptSuggestions.contains(e.target)) {
      receiptSuggestions.classList.add('d-none');
    }
  });

  // -----------------------------
  // refund checkbox handling
  // -----------------------------
  const refundedCheck = document.getElementById('refundedCheck');
  const refundedHidden = document.getElementById('refunded');

  if (refundedCheck && refundedHidden) {
    refundedCheck.addEventListener('change', () => {
      const isRefunded = refundedCheck.checked;
      refundedHidden.value = isRefunded ? '1' : '0';
      
      if (refundAmountInput) {
        refundAmountInput.disabled = !isRefunded;
        if (!isRefunded) {
          refundAmountInput.value = '';
        }
      }
    });
  }

  // -----------------------------
  // sale loading
  // -----------------------------
  receiptNoInput.addEventListener('blur', () => {
    const receiptNo = receiptNoInput.value.trim();
    if (!receiptNo) {
      resetSaleUI();
      return;
    }
    loadSaleDetails(receiptNo);
  });

  async function loadSaleDetails(receiptNo) {
    clearModalAlert();

    saleInfo.innerHTML = `
      <div class="text-center">
        <div class="spinner-border spinner-border-sm" role="status"></div>
        <span class="ms-2">Loading sale details...</span>
      </div>
    `;
    saleDetails.classList.remove('d-none');
    itemsSection.classList.add('d-none');

    try {
      const url = `${BASE}/api/sales/get_sale_details.php?receipt_no=${encodeURIComponent(receiptNo)}`;
      const data = await fetchJson(url);

      if (!data.success) {
        resetSaleUI();
        showAlert('danger', `Receipt not found: ${data.message || 'Unknown error'}`);
        return;
      }

      displaySaleDetails(data.sale);
      displaySaleItems(data.items || []);
    } catch (e) {
      console.error(e);
      resetSaleUI();
      showAlert('danger', `Error loading sale details: ${e.message}`);
    }
  }

  function displaySaleDetails(sale) {
    const created = sale?.created_at ? new Date(sale.created_at) : null;

    saleInfo.innerHTML = `
      <div class="row">
        <div class="col-md-6">
          <strong>Sale ID:</strong> ${sale.id}<br>
          <strong>Document:</strong> ${sale.doc_no}<br>
          <strong>Date:</strong> ${created ? created.toLocaleDateString() : '-'}<br>
          <strong>Total:</strong> ${money(sale.grand_total)}
        </div>
        <div class="col-md-6">
          <strong>Customer:</strong> ${sale.customer_name || 'Walk-in'}<br>
          <strong>Status:</strong> <span class="badge bg-primary">${sale.status || 'n/a'}</span><br>
          <strong>Payment:</strong> <span class="badge bg-info">${sale.payment_status || 'n/a'}</span>
        </div>
      </div>
    `;
    saleDetails.classList.remove('d-none');
  }

  function displaySaleItems(items) {
    if (!items.length) {
      itemsSection.classList.add('d-none');
      return;
    }

    let html = `
      <div class="table-responsive">
        <table class="table table-sm">
          <thead>
            <tr>
              <th>Item</th>
              <th>Qty Sold</th>
              <th>Qty Returned</th>
              <th style="min-width:150px;">Qty to Return</th>
              <th>Price</th>
              <th>Total</th>
            </tr>
          </thead>
          <tbody>
    `;

    items.forEach((item) => {
      const sold = Number(item.quantity || 0);
      const returned = Number(item.returned_quantity || 0);
      const maxReturnQty = Math.max(0, sold - returned);

      html += `
        <tr>
          <td>${(item.product_name || item.name || '').toString()}</td>
          <td>${sold}</td>
          <td>${returned}</td>
          <td>
            <input type="number"
              class="form-control form-control-sm"
              name="return_qty[${item.product_id}]"
              min="0"
              max="${maxReturnQty}"
              value="0"
              data-item-id="${item.product_id}"
              data-unit-price="${Number(item.unit_price || 0)}"
              data-max="${maxReturnQty}">
          </td>
          <td>${money(item.unit_price)}</td>
          <td class="item-total">${money(0)}</td>
        </tr>
      `;
    });

    html += `</tbody></table></div>`;

    itemsList.innerHTML = html;
    itemsSection.classList.remove('d-none');

    // listeners
    itemsList.querySelectorAll('input[name^="return_qty"]').forEach((input) => {
      input.addEventListener('input', () => {
        // clamp to max
        const max = Number(input.dataset.max || 0);
        let v = parseInt(input.value || '0', 10);
        if (Number.isNaN(v) || v < 0) v = 0;
        if (v > max) v = max;
        input.value = String(v);

        updateItemTotals();
      });
    });

    updateItemTotals();
  }

  function updateItemTotals() {
    let grand = 0;

    itemsList.querySelectorAll('input[name^="return_qty"]').forEach((input) => {
      const qty = parseInt(input.value || '0', 10) || 0;
      const unit = Number(input.dataset.unitPrice || 0);
      const total = qty * unit;

      const row = input.closest('tr');
      row.querySelector('.item-total').textContent = money(total);

      grand += total;
    });

    // Only auto-fill refund if empty/zero
    const current = Number(refundAmountInput?.value || 0);
    if (refundAmountInput && (!refundAmountInput.value || current === 0)) {
      refundAmountInput.value = grand.toFixed(2);
    }
  }

  // -----------------------------
  // save return
  // -----------------------------
  saveReturnBtn.addEventListener('click', async () => {
    clearModalAlert();

    if (!returnForm.checkValidity()) {
      returnForm.reportValidity();
      return;
    }

    const returnItems = {};
    itemsList.querySelectorAll('input[name^="return_qty"]').forEach((input) => {
      const qty = parseInt(input.value || '0', 10) || 0;
      if (qty > 0) returnItems[input.dataset.itemId] = qty;
    });

    if (!Object.keys(returnItems).length) {
      showAlert('warning', 'Please select at least one item to return');
      return;
    }

    const formData = new FormData(returnForm);
    formData.append('return_items', JSON.stringify(returnItems));

    try {
      setSaving(true);

      const data = await fetchJson(`${BASE}/api/returns/create_return.php`, {
        method: 'POST',
        body: formData
      });

      if (!data.success) {
        showAlert('danger', `Error creating return: ${data.message || 'Unknown error'}`);
        return;
      }

      showAlert('success', 'Return created successfully!');
      setTimeout(() => {
        modal.hide();
        window.location.reload();
      }, 900);

    } catch (e) {
      console.error(e);
      showAlert('danger', `Error creating return: ${e.message}`);
    } finally {
      setSaving(false);
    }
  });

  // Optional: reset UI when modal closes
  modalEl.addEventListener('hidden.bs.modal', () => {
    clearModalAlert();
    returnForm.reset();
    resetSaleUI();
    if (refundAmountInput) refundAmountInput.value = '';
  });

  // -----------------------------
  // Edit Return Functions
  // -----------------------------
  const editModalEl = document.getElementById('editReturnModal');
  const editModal = new bootstrap.Modal(editModalEl);
  const editReturnForm = document.getElementById('editReturnForm');
  const editLocationSelect = document.getElementById('editLocationId');
  const editRefundAmountInput = document.getElementById('editRefundAmount');
  const editRefundedCheck = document.getElementById('editRefundedCheck');
  const editRefundedHidden = document.getElementById('editRefunded');

  // Load locations for edit modal
  editModalEl.addEventListener('shown.bs.modal', async () => {
    if (!editLocationSelect) return;
    editLocationSelect.innerHTML = '<option value="">Loading locations...</option>';

    try {
      const data = await fetchJson(`${BASE}/api/locations/get_locations.php`);
      if (!data.success) throw new Error(data.message || 'Failed to load locations');

      editLocationSelect.innerHTML = '<option value="">Select Location</option>';
      (data.locations || []).forEach((loc) => {
        const opt = document.createElement('option');
        opt.value = loc.id;
        opt.textContent = loc.name;
        editLocationSelect.appendChild(opt);
      });
      
      // Set the current location after options are loaded
      const currentLocationId = document.getElementById('editReturnId')?.value;
      if (currentLocationId) {
        // Re-fetch the return data to get the current location
        fetchJson(`${BASE}/api/returns/get_return.php?id=${currentLocationId}`)
          .then(returnData => {
            if (returnData.success && returnData.return.selling_location_id) {
              editLocationSelect.value = returnData.return.selling_location_id;
            }
          })
          .catch(e => console.error('Error setting current location:', e));
      }
    } catch (e) {
      console.error(e);
      editLocationSelect.innerHTML = '<option value="">Error loading locations</option>';
    }
  });

  // Refund checkbox handler for edit modal
  editRefundedCheck?.addEventListener('change', () => {
    editRefundedHidden.value = editRefundedCheck.checked ? '1' : '0';
    if (editRefundAmountInput) {
      editRefundAmountInput.disabled = !editRefundedCheck.checked;
      if (!editRefundedCheck.checked) editRefundAmountInput.value = '';
    }
  });

  // Global edit function
  window.editReturn = async (returnId) => {
    try {
      const data = await fetchJson(`${BASE}/api/returns/get_return.php?id=${returnId}`);
      if (!data.success) throw new Error(data.message || 'Failed to load return');

      const returnData = data.return;
      document.getElementById('editReturnId').value = returnData.id;
      document.getElementById('editReason').value = returnData.reason || '';
      document.getElementById('editStatus').value = returnData.status || 'pending';
      
      // Set return date - use today's date if created_at is not available or invalid
      const returnDateField = document.getElementById('editReturnDate');
      if (returnData.created_at) {
        const datePart = returnData.created_at.split(' ')[0];
        // Validate the date format before setting
        const dateRegex = /^\d{4}-\d{2}-\d{2}$/;
        if (dateRegex.test(datePart)) {
          returnDateField.value = datePart;
        } else {
          returnDateField.value = new Date().toISOString().split('T')[0]; // Today's date
        }
      } else {
        returnDateField.value = new Date().toISOString().split('T')[0]; // Today's date
      }
      
      // Set refund fields
      const isRefunded = returnData.refunded == 1;
      editRefundedCheck.checked = isRefunded;
      editRefundedHidden.value = isRefunded ? '1' : '0';
      editRefundAmountInput.value = returnData.refund_amount || '';
      editRefundAmountInput.disabled = !isRefunded;
      
      // Set location
      if (editLocationSelect && returnData.selling_location_id) {
        editLocationSelect.value = returnData.selling_location_id;
      }

      // Display return items
      if (returnData.items && returnData.items.length > 0) {
        const itemsHtml = returnData.items.map(item => `
          <div class="d-flex justify-content-between align-items-center p-2 border-bottom">
            <div>
              <strong>${item.product_name || 'Product #' + item.product_id}</strong>
              <div class="text-muted small">Qty: ${item.quantity} × $${item.unit_price}</div>
            </div>
            <div class="text-end">
              <strong>$${(item.quantity * item.unit_price).toFixed(2)}</strong>
            </div>
          </div>
        `).join('');
        document.getElementById('editItemsList').innerHTML = itemsHtml;
      } else {
        document.getElementById('editItemsList').innerHTML = '<p class="text-muted">No items found</p>';
      }

      editModal.show();
    } catch (e) {
      console.error(e);
      alert('Error loading return: ' + e.message);
    }
  };

  // Update return handler
  document.getElementById('updateReturnBtn')?.addEventListener('click', async () => {
    if (!editReturnForm.checkValidity()) {
      editReturnForm.reportValidity();
      return;
    }

    const formData = new FormData(editReturnForm);
    
    try {
      const btn = document.getElementById('updateReturnBtn');
      btn.disabled = true;
      btn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Updating...';

      const data = await fetchJson(`${BASE}/api/returns/update_return.php`, {
        method: 'POST',
        body: formData
      });

      if (!data.success) throw new Error(data.message || 'Failed to update return');

      alert('Return updated successfully!');
      editModal.hide();
      window.location.reload();
    } catch (e) {
      console.error(e);
      alert('Error updating return: ' + e.message);
    } finally {
      const btn = document.getElementById('updateReturnBtn');
      btn.disabled = false;
      btn.innerHTML = '<i class="bi bi-save me-1"></i> Update Return';
    }
  });

  // -----------------------------
  // Delete Return Functions
  // -----------------------------
  const deleteModalEl = document.getElementById('deleteReturnModal');
  const deleteModal = new bootstrap.Modal(deleteModalEl);

  // Global delete function
  window.deleteReturn = async (returnId) => {
    try {
      const data = await fetchJson(`${BASE}/api/returns/get_return.php?id=${returnId}`);
      if (!data.success) throw new Error(data.message || 'Failed to load return');

      const returnData = data.return;
      document.getElementById('deleteReturnId').value = returnData.id;
      
      const infoHtml = `
        <div class="alert alert-info">
          <strong>Return #${returnData.return_no || returnData.id}</strong><br>
          <strong>Reason:</strong> ${returnData.reason || 'N/A'}<br>
          <strong>Status:</strong> ${returnData.status || 'N/A'}<br>
          <strong>Refund Amount:</strong> $${(returnData.refund_amount || 0).toFixed(2)}
        </div>
      `;
      document.getElementById('deleteReturnInfo').innerHTML = infoHtml;

      deleteModal.show();
    } catch (e) {
      console.error(e);
      alert('Error loading return: ' + e.message);
    }
  };

  // Confirm delete handler
  document.getElementById('confirmDeleteBtn')?.addEventListener('click', async () => {
    const returnId = document.getElementById('deleteReturnId').value;
    
    if (!returnId) {
      alert('Invalid return ID');
      return;
    }

    if (!confirm('Are you absolutely sure you want to delete this return? This action cannot be undone.')) {
      return;
    }

    try {
      const btn = document.getElementById('confirmDeleteBtn');
      btn.disabled = true;
      btn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Deleting...';

      const data = await fetchJson(`${BASE}/api/returns/delete_return.php`, {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
        },
        body: JSON.stringify({ return_id: returnId })
      });

      if (!data.success) throw new Error(data.message || 'Failed to delete return');

      alert('Return deleted successfully!');
      deleteModal.hide();
      window.location.reload();
    } catch (e) {
      console.error(e);
      alert('Error deleting return: ' + e.message);
    } finally {
      const btn = document.getElementById('confirmDeleteBtn');
      btn.disabled = false;
      btn.innerHTML = '<i class="bi bi-trash me-1"></i> Delete Return';
    }
  });
});
</script>