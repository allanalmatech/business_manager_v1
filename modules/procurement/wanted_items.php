<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/bootstrap.php';

$db = $GLOBALS['db'] ?? null;
$base_url = $GLOBALS['BASE_URL'] ?? '/';

if (!($db instanceof mysqli)) {
    die('Database not available');
}

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/rbac.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$user_id = (int)($_SESSION['user']['id'] ?? 0);
if ($user_id <= 0) {
    header('Location: ' . $base_url . 'login.php');
    exit;
}

require_permission('procurement.view');

$message = '';
$message_type = '';
$page = max(1, (int)($_GET['page'] ?? 1));
$per_page = 50;
$offset = ($page - 1) * $per_page;

// Check if table exists
function table_exists(mysqli $db, string $table): bool {
    $result = $db->query("SHOW TABLES LIKE '" . $db->real_escape_string($table) . "'");
    return $result && $result->num_rows > 0;
}

$hasWantedItems = table_exists($db, 'procurement_wanted_items');

// Handle actions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] === 'add_item' && user_has_permission('procurement.create')) {
        $result = handle_add_item();
        $message = $result['message'];
        $message_type = $result['success'] ? 'success' : 'danger';
    } elseif ($_POST['action'] === 'update_item' && user_has_permission('procurement.update')) {
        $result = handle_update_item();
        $message = $result['message'];
        $message_type = $result['success'] ? 'success' : 'danger';
    } elseif ($_POST['action'] === 'update_status' && user_has_permission('procurement.update')) {
        $result = handle_update_status();
        $message = $result['message'];
        $message_type = $result['success'] ? 'success' : 'danger';
    } elseif ($_POST['action'] === 'delete_item' && user_has_permission('procurement.delete')) {
        $result = handle_delete_item();
        $message = $result['message'];
        $message_type = $result['success'] ? 'success' : 'danger';
    } elseif ($_POST['action'] === 'add_to_shopping' && user_has_permission('procurement.create')) {
        $result = handle_add_to_shopping();
        $message = $result['message'];
        $message_type = $result['success'] ? 'success' : 'danger';
    }
}

// Apply filters
$where = ["1 = 1"];
$params = [];
$types = '';

$status = trim((string)($_GET['status'] ?? ''));
if ($status) {
    $where[] = "status = ?";
    $params[] = $status;
    $types .= 's';
}

$priority = trim((string)($_GET['priority'] ?? ''));
if ($priority) {
    $where[] = "priority = ?";
    $params[] = $priority;
    $types .= 's';
}

$search = trim((string)($_GET['search'] ?? ''));
if ($search) {
    $where[] = "(item_name LIKE ? OR description LIKE ?)";
    $search_term = "%$search%";
    $params[] = $search_term;
    $params[] = $search_term;
    $types .= 'ss';
}

$where_clause = implode(' AND ', $where);

// Only run queries if table exists
$items = [];
$total = 0;
$totals = ['total_items' => 0, 'pending_items' => 0, 'approved_items' => 0, 'rejected_items' => 0, 'ordered_items' => 0, 'total_estimated' => 0];

if ($hasWantedItems) {
    // Count total
    $count_query = "SELECT COUNT(*) as cnt FROM procurement_wanted_items WHERE $where_clause";
    $st = $db->prepare($count_query);
    if ($st && $types) {
        $bind_params = array_slice($params, 0);
        $st->bind_param($types, ...$bind_params);
    }
    if ($st) {
        $st->execute();
        $total = (int)$st->get_result()->fetch_assoc()['cnt'];
        $st->close();
    }
    $total_pages = ceil($total / $per_page);

    // Fetch items
    $query = "
        SELECT pwi.id, pwi.item_name, pwi.description, pwi.category, pwi.estimated_cost,
               pwi.status, pwi.priority, pwi.reason, pwi.requested_by, pwi.approved_by,
               pwi.created_at, pwi.updated_at, u1.full_name as requester_name, u2.full_name as approver_name
        FROM procurement_wanted_items pwi
        LEFT JOIN users u1 ON pwi.requested_by = u1.id
        LEFT JOIN users u2 ON pwi.approved_by = u2.id
        WHERE $where_clause
        ORDER BY pwi.priority DESC, pwi.created_at DESC
        LIMIT ? OFFSET ?
    ";

    $st = $db->prepare($query);
    if (!$st) {
        $message = 'Database error';
        $message_type = 'danger';
        $items = [];
    } else {
        $bind_params = array_slice($params, 0);
        $bind_params[] = $per_page;
        $bind_params[] = $offset;
        
        $bind_types = $types . 'ii';
        
        if ($bind_types) {
            $st->bind_param($bind_types, ...$bind_params);
        }
        $st->execute();
        $items = $st->get_result()->fetch_all(MYSQLI_ASSOC);
        $st->close();
    }

    // Fetch totals
    $totals_query = "
        SELECT 
            COUNT(*) as total_items,
            SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending_items,
            SUM(CASE WHEN status = 'approved' THEN 1 ELSE 0 END) as approved_items,
            SUM(CASE WHEN status = 'rejected' THEN 1 ELSE 0 END) as rejected_items,
            SUM(CASE WHEN status = 'ordered' THEN 1 ELSE 0 END) as ordered_items,
            COALESCE(SUM(estimated_cost), 0) as total_estimated
        FROM procurement_wanted_items
        WHERE $where_clause
    ";

    $st = $db->prepare($totals_query);
    if ($st && $types) {
        $bind_params = array_slice($params, 0);
        $st->bind_param($types, ...$bind_params);
    }
    if ($st) {
        $st->execute();
        $totals = $st->get_result()->fetch_assoc();
        $st->close();
    }
} else {
    $total_pages = 0;
}

function h2($text) {
    return htmlspecialchars($text ?? '', ENT_QUOTES, 'UTF-8');
}


function handle_add_item(): array {
    global $db, $user_id;
    
    $item_name = trim((string)($_POST['item_name'] ?? ''));
    $description = trim((string)($_POST['description'] ?? ''));
    $category = trim((string)($_POST['category'] ?? ''));
    $estimated_cost = (float)($_POST['estimated_cost'] ?? 0);
    $priority = trim((string)($_POST['priority'] ?? 'medium'));
    $reason = trim((string)($_POST['reason'] ?? ''));
    
    if (!$item_name) {
        return ['success' => false, 'message' => 'Item name is required'];
    }
    
    try {
        $status = 'pending';
        $st = $db->prepare("INSERT INTO procurement_wanted_items 
            (item_name, description, category, estimated_cost, status, priority, reason, requested_by, created_at) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())");
        
        if (!$st) throw new Exception('Prepare failed');
        $st->bind_param('sssdsss', $item_name, $description, $category, $estimated_cost, $status, $priority, $reason);
        
        // Fix bind_param - need separate variable for user_id
        $st->bind_param('sssdssssi', $item_name, $description, $category, $estimated_cost, $status, $priority, $reason, $user_id);
        $st->execute();
        $item_id = $st->insert_id;
        $st->close();
        
        if (function_exists('audit_log')) {
            audit_log('procurement.wanted_add', 'wanted_items', (string)$item_id, "Added: $item_name (Est: $estimated_cost)");
        }
        
        return ['success' => true, 'message' => 'Wanted item added'];
    } catch (Exception $e) {
        return ['success' => false, 'message' => $e->getMessage()];
    }
}

function handle_update_item(): array {
    global $db, $user_id;
    
    $id = (int)($_POST['item_id'] ?? 0);
    $item_name = trim((string)($_POST['item_name'] ?? ''));
    $description = trim((string)($_POST['description'] ?? ''));
    $category = trim((string)($_POST['category'] ?? ''));
    $estimated_cost = (float)($_POST['estimated_cost'] ?? 0);
    $priority = trim((string)($_POST['priority'] ?? 'medium'));
    $reason = trim((string)($_POST['reason'] ?? ''));
    
    if ($id <= 0 || !$item_name) {
        return ['success' => false, 'message' => 'Invalid parameters'];
    }
    
    try {
        $st = $db->prepare("UPDATE procurement_wanted_items 
            SET item_name = ?, description = ?, category = ?, estimated_cost = ?, 
                priority = ?, reason = ?, updated_at = NOW()
            WHERE id = ? LIMIT 1");
        
        if (!$st) throw new Exception('Prepare failed');
        $st->bind_param('sssdssi', $item_name, $description, $category, $estimated_cost, $priority, $reason, $id);
        $st->execute();
        $st->close();
        
        if (function_exists('audit_log')) {
            audit_log('procurement.wanted_update', 'wanted_items', (string)$id, "Updated: $item_name");
        }
        
        return ['success' => true, 'message' => 'Item updated'];
    } catch (Exception $e) {
        return ['success' => false, 'message' => $e->getMessage()];
    }
}

function handle_update_status(): array {
    global $db, $user_id;
    
    $id = (int)($_POST['item_id'] ?? 0);
    $status = trim((string)($_POST['status'] ?? ''));
    
    if ($id <= 0 || !in_array($status, ['pending', 'approved', 'rejected', 'ordered'])) {
        return ['success' => false, 'message' => 'Invalid parameters'];
    }
    
    try {
        $update_fields = "status = ?, updated_at = NOW()";
        $params = [$status];
        $types = 's';
        
        if ($status === 'approved') {
            $update_fields .= ", approved_by = ?";
            $params[] = $user_id;
            $types .= 'i';
        }
        
        $params[] = $id;
        $types .= 'i';
        
        $st = $db->prepare("UPDATE procurement_wanted_items SET $update_fields WHERE id = ? LIMIT 1");
        if (!$st) throw new Exception('Prepare failed');
        $st->bind_param($types, ...$params);
        $st->execute();
        $st->close();
        
        if (function_exists('audit_log')) {
            audit_log('procurement.wanted_status', 'wanted_items', (string)$id, "Status changed to: $status");
        }
        
        return ['success' => true, 'message' => 'Status updated'];
    } catch (Exception $e) {
        return ['success' => false, 'message' => $e->getMessage()];
    }
}

function handle_delete_item(): array {
    global $db, $user_id;
    
    $id = (int)($_POST['item_id'] ?? 0);
    
    if ($id <= 0) {
        return ['success' => false, 'message' => 'Invalid item'];
    }
    
    try {
        $st = $db->prepare("DELETE FROM procurement_wanted_items WHERE id = ? LIMIT 1");
        if (!$st) throw new Exception('Prepare failed');
        $st->bind_param('i', $id);
        $st->execute();
        $st->close();
        
        if (function_exists('audit_log')) {
            audit_log('procurement.wanted_delete', 'wanted_items', (string)$id, 'Deleted from wanted items');
        }
        
        return ['success' => true, 'message' => 'Item deleted'];
    } catch (Exception $e) {
        return ['success' => false, 'message' => $e->getMessage()];
    }
}

function handle_add_to_shopping(): array {
    global $db, $user_id;
    
    $id = (int)($_POST['item_id'] ?? 0);
    $quantity = (float)($_POST['quantity'] ?? 1);
    $unit = trim((string)($_POST['unit'] ?? 'pieces'));
    
    if ($id <= 0 || $quantity <= 0) {
        return ['success' => false, 'message' => 'Invalid parameters'];
    }
    
    try {
        // Get wanted item details
        $check = $db->prepare("SELECT item_name, estimated_cost FROM procurement_wanted_items WHERE id = ? LIMIT 1");
        if (!$check) throw new Exception('Prepare failed');
        $check->bind_param('i', $id);
        $check->execute();
        $item = $check->get_result()->fetch_assoc();
        $check->close();
        
        if (!$item) {
            throw new Exception('Item not found');
        }
        
        $estimated_cost = $quantity * $item['estimated_cost'];
        $status = 'pending';
        $priority = 'medium';
        
        // Add to shopping list
        $st = $db->prepare("INSERT INTO procurement_shopping_list 
            (product_name, quantity, unit, estimated_cost, status, priority, user_id, created_at) 
            VALUES (?, ?, ?, ?, ?, ?, ?, NOW())");
        
        if (!$st) throw new Exception('Prepare failed');
        $st->bind_param('sdssssi', $item['item_name'], $quantity, $unit, $estimated_cost, $status, $priority, $user_id);
        $st->execute();
        $shopping_id = $st->insert_id;
        $st->close();
        
        // Update wanted item status
        $new_status = 'ordered';
        $st = $db->prepare("UPDATE procurement_wanted_items SET status = ?, updated_at = NOW() WHERE id = ? LIMIT 1");
        if (!$st) throw new Exception('Prepare failed');
        $st->bind_param('si', $new_status, $id);
        $st->execute();
        $st->close();
        
        if (function_exists('audit_log')) {
            audit_log('procurement.wanted_to_shopping', 'shopping_list', (string)$shopping_id, "From wanted items: {$item['item_name']}");
        }
        
        return ['success' => true, 'message' => 'Added to shopping list'];
    } catch (Exception $e) {
        return ['success' => false, 'message' => $e->getMessage()];
    }
}

// Handle CSV export
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=wanted_items_' . date('Y-m-d') . '.csv');
    
    $output = fopen('php://output', 'w');
    fputcsv($output, ['ID', 'Item Name', 'Description', 'Category', 'Est. Cost', 'Status', 'Priority', 'Reason', 'Requested By', 'Approved By', 'Date Requested', 'Last Updated']);
    
    $query = "
        SELECT pwi.id, pwi.item_name, pwi.description, pwi.category, pwi.estimated_cost, 
               pwi.status, pwi.priority, pwi.reason, u1.name as requester_name, 
               u2.name as approver_name, pwi.created_at, pwi.updated_at
        FROM procurement_wanted_items pwi
        LEFT JOIN users u1 ON pwi.requested_by = u1.id
        LEFT JOIN users u2 ON pwi.approved_by = u2.id
        WHERE $where_clause
        ORDER BY pwi.priority DESC, pwi.created_at DESC
    ";
    
    $st = $db->prepare($query);
    if ($st && $types) {
        $bind_params = array_slice($params, 0);
        $st->bind_param($types, ...$bind_params);
    }
    
    if ($st) {
        $st->execute();
        $export_items = $st->get_result()->fetch_all(MYSQLI_ASSOC);
        $st->close();
        
        foreach ($export_items as $item) {
            fputcsv($output, [
                $item['id'],
                $item['item_name'],
                $item['description'],
                $item['category'],
                $item['estimated_cost'],
                $item['status'],
                $item['priority'],
                $item['reason'],
                $item['requester_name'],
                $item['approver_name'],
                date('M d, Y', strtotime($item['created_at'])),
                date('M d, Y', strtotime($item['updated_at']))
            ]);
        }
    }
    
    fclose($output);
    exit;
}

$page_title = 'Wanted Items - Procurement';
include __DIR__ . '/../../templates/layout/header.php';
?>
<div class="app-shell">
  <?php require_once __DIR__ . '/../../templates/layout/sidebar.php'; ?>
  <div class="app-content">
    <?php require_once __DIR__ . '/../../templates/layout/topbar.php'; ?>

    <main class="page-wrap">
      <div class="container-fluid py-4">
        <div class="d-flex justify-content-between align-items-center mb-4">
          <div>
            <h3 class="mb-2 fw-bold">Wanted Items</h3>
            <div class="text-muted">Manage procurement requests and wanted items from team members</div>
          </div>
          <div class="gap-2 d-flex">
            <a href="?export=csv" class="btn btn-outline-success">
              <i class="bi bi-download me-1"></i> Export CSV
            </a>
            <?php if (user_has_permission('procurement.create')): ?>
              <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addItemModal">
                <i class="bi bi-plus-circle me-1"></i> Add Wanted Item
              </button>
            <?php endif; ?>
          </div>
        </div>

        <?php if ($message): ?>
          <div class="alert alert-<?php echo $message_type; ?> alert-dismissible fade show" role="alert">
            <div class="d-flex align-items-center">
              <i class="bi bi-<?php echo $message_type === 'success' ? 'check-circle-fill' : 'exclamation-triangle-fill'; ?> me-2"></i>
              <?php echo h2($message); ?>
            </div>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
          </div>
        <?php endif; ?>

            <?php if (!$hasWantedItems): ?>
                <div class="card shadow-sm">
                    <div class="card-body text-center py-5">
                        <i class="bi bi-exclamation-triangle text-warning" style="font-size: 3rem;"></i>
                        <h5 class="mt-3">Wanted Items Table Not Found</h5>
                        <p class="text-muted">The procurement_wanted_items table does not exist. Please run the database migrations to create the required tables.</p>
                        <a href="<?= $base_url ?>migrations/" class="btn btn-primary">
                            <i class="bi bi-gear"></i> Run Migrations
                        </a>
                    </div>
                </div>
            <?php else: ?>

            <!-- Summary Cards -->
        <div class="row g-3 mb-4">
          <div class="col-lg-2 col-md-4 col-sm-6">
            <div class="card shadow-sm h-100">
              <div class="card-body">
                <div class="d-flex align-items-center">
                  <div class="flex-shrink-0">
                    <div class="bg-primary bg-opacity-10 rounded-circle p-3">
                      <i class="bi bi-list-ul text-primary" style="font-size: 1.2rem;"></i>
                    </div>
                  </div>
                  <div class="flex-grow-1 ms-3">
                    <div class="h5 mb-0 text-primary"><?= $totals['total_items'] ?? 0 ?></div>
                    <div class="small text-muted">Total Items</div>
                  </div>
                </div>
              </div>
            </div>
          </div>
          <div class="col-lg-2 col-md-4 col-sm-6">
            <div class="card shadow-sm h-100">
              <div class="card-body">
                <div class="d-flex align-items-center">
                  <div class="flex-shrink-0">
                    <div class="bg-warning bg-opacity-10 rounded-circle p-3">
                      <i class="bi bi-clock text-warning" style="font-size: 1.2rem;"></i>
                    </div>
                  </div>
                  <div class="flex-grow-1 ms-3">
                    <div class="h5 mb-0 text-warning"><?= $totals['pending_items'] ?? 0 ?></div>
                    <div class="small text-muted">Pending</div>
                  </div>
                </div>
              </div>
            </div>
          </div>
          <div class="col-lg-2 col-md-4 col-sm-6">
            <div class="card shadow-sm h-100">
              <div class="card-body">
                <div class="d-flex align-items-center">
                  <div class="flex-shrink-0">
                    <div class="bg-success bg-opacity-10 rounded-circle p-3">
                      <i class="bi bi-check-circle text-success" style="font-size: 1.2rem;"></i>
                    </div>
                  </div>
                  <div class="flex-grow-1 ms-3">
                    <div class="h5 mb-0 text-success"><?= $totals['approved_items'] ?? 0 ?></div>
                    <div class="small text-muted">Approved</div>
                  </div>
                </div>
              </div>
            </div>
          </div>
          <div class="col-lg-2 col-md-4 col-sm-6">
            <div class="card shadow-sm h-100">
              <div class="card-body">
                <div class="d-flex align-items-center">
                  <div class="flex-shrink-0">
                    <div class="bg-info bg-opacity-10 rounded-circle p-3">
                      <i class="bi bi-truck text-info" style="font-size: 1.2rem;"></i>
                    </div>
                  </div>
                  <div class="flex-grow-1 ms-3">
                    <div class="h5 mb-0 text-info"><?= $totals['ordered_items'] ?? 0 ?></div>
                    <div class="small text-muted">Ordered</div>
                  </div>
                </div>
              </div>
            </div>
          </div>
          <div class="col-lg-2 col-md-4 col-sm-6">
            <div class="card shadow-sm h-100">
              <div class="card-body">
                <div class="d-flex align-items-center">
                  <div class="flex-shrink-0">
                    <div class="bg-danger bg-opacity-10 rounded-circle p-3">
                      <i class="bi bi-x-circle text-danger" style="font-size: 1.2rem;"></i>
                    </div>
                  </div>
                  <div class="flex-grow-1 ms-3">
                    <div class="h5 mb-0 text-danger"><?= $totals['rejected_items'] ?? 0 ?></div>
                    <div class="small text-muted">Rejected</div>
                  </div>
                </div>
              </div>
            </div>
          </div>
          <div class="col-lg-2 col-md-4 col-sm-6">
            <div class="card shadow-sm h-100">
              <div class="card-body">
                <div class="d-flex align-items-center">
                  <div class="flex-shrink-0">
                    <div class="bg-primary bg-opacity-10 rounded-circle p-3">
                      <i class="bi bi-cash-stack text-primary" style="font-size: 1.2rem;"></i>
                    </div>
                  </div>
                  <div class="flex-grow-1 ms-3">
                    <div class="h5 mb-0 text-primary"><?= number_format((float)($totals['total_estimated'] ?? 0), 2) ?></div>
                    <div class="small text-muted">Est. Total</div>
                  </div>
                </div>
              </div>
            </div>
          </div>
        </div>

            <!-- Search and Filter -->
          <div class="card shadow-sm mb-4">
            <div class="card-header bg-light">
              <h6 class="mb-0">
                <i class="bi bi-funnel"></i> Search & Filter Wanted Items
              </h6>
            </div>
            <div class="card-body">
              <form method="GET" class="row g-3">
                <div class="col-lg-5 col-md-12">
                  <label for="search" class="form-label">Search Items</label>
                  <input type="text" id="search" name="search" class="form-control" 
                         placeholder="Search by item name or description..." 
                         value="<?php echo h2($search); ?>">
                </div>
                <div class="col-lg-3 col-md-6">
                  <label for="status" class="form-label">Status</label>
                  <select id="status" name="status" class="form-select">
                    <option value="">All Statuses</option>
                    <option value="pending" <?php echo $status === 'pending' ? 'selected' : ''; ?>>🟡 Pending</option>
                    <option value="approved" <?php echo $status === 'approved' ? 'selected' : ''; ?>>🟢 Approved</option>
                    <option value="ordered" <?php echo $status === 'ordered' ? 'selected' : ''; ?>>🔵 Ordered</option>
                    <option value="rejected" <?php echo $status === 'rejected' ? 'selected' : ''; ?>>🔴 Rejected</option>
                  </select>
                </div>
                <div class="col-lg-3 col-md-6">
                  <label for="priority" class="form-label">Priority</label>
                  <select id="priority" name="priority" class="form-select">
                    <option value="">All Priorities</option>
                    <option value="low" <?php echo $priority === 'low' ? 'selected' : ''; ?>>🔵 Low</option>
                    <option value="medium" <?php echo $priority === 'medium' ? 'selected' : ''; ?>>🟡 Medium</option>
                    <option value="high" <?php echo $priority === 'high' ? 'selected' : ''; ?>>🔴 High</option>
                  </select>
                </div>
                <div class="col-12">
                  <div class="d-flex gap-2 flex-wrap">
                    <button type="submit" class="btn btn-primary">
                      <i class="bi bi-search"></i> Filter
                    </button>
                    <a href="?" class="btn btn-outline-secondary">
                      <i class="bi bi-x-circle"></i> Clear
                    </a>
                  </div>
                </div>
              </form>
            </div>
          </div>

            <!-- Items Table -->
          <div class="card shadow-sm">
            <div class="card-header bg-light">
              <h6 class="mb-0">
                <i class="bi bi-star"></i> Wanted Items
                <span class="badge bg-primary rounded-pill float-end"><?= $total ?></span>
              </h6>
            </div>
            <div class="card-body p-0">
              <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                  <thead class="table-light">
                    <tr>
                      <th style="min-width: 200px;">Item Name</th>
                      <th style="width: 120px;">Category</th>
                      <th style="width: 100px;" class="text-end">Est. Cost</th>
                      <th style="width: 100px;">Status</th>
                      <th style="width: 80px;">Priority</th>
                      <th style="width: 120px;">Requested By</th>
                      <th style="width: 120px;">Approved By</th>
                      <th style="width: 100px;">Date</th>
                      <th style="width: 120px;" class="text-center">Actions</th>
                    </tr>
                  </thead>
                    <tbody>
                        <?php if (empty($items)): ?>
                            <tr>
                                <td colspan="9" class="text-center text-muted py-4">
                                    No wanted items found
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($items as $item): ?>
                                <tr>
                                    <td>
                                        <strong><?php echo h2($item['item_name']); ?></strong>
                                        <?php if ($item['description']): ?>
                                            <br><small class="text-muted"><?php echo h2(substr($item['description'], 0, 60)) . (strlen($item['description']) > 60 ? '...' : ''); ?></small>
                                        <?php endif; ?>
                                    </td>
                                    <td><?php echo h2($item['category'] ?? '-'); ?></td>
                                    <td><?php echo number_format((float)($item['estimated_cost'] ?? 0), 2); ?></td>
                                    <td>
                                        <span class="badge bg-<?php 
                                            echo match($item['status']) {
                                                'pending' => 'warning',
                                                'approved' => 'success',
                                                'ordered' => 'info',
                                                'rejected' => 'danger',
                                                default => 'secondary'
                                            };
                                        ?>">
                                            <?php echo ucfirst($item['status']); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <span class="badge bg-<?php 
                                            echo match($item['priority']) {
                                                'high' => 'danger',
                                                'medium' => 'warning',
                                                'low' => 'info',
                                                default => 'secondary'
                                            };
                                        ?>">
                                            <?php echo ucfirst($item['priority']); ?>
                                        </span>
                                    </td>
                                    <td><?php echo h2($item['requester_name'] ?? '-'); ?></td>
                                    <td><?php echo h2($item['approver_name'] ?? '-'); ?></td>
                                    <td><?php echo date('M d, Y', strtotime($item['created_at'])); ?></td>
                                    <td>
                                        <div class="btn-group btn-group-sm" role="group">
                                            <button class="btn btn-primary" data-bs-toggle="modal" 
                                                    data-bs-target="#editItemModal<?php echo $item['id']; ?>" title="Edit">
                                                <i class="bi bi-pencil"></i>
                                            </button>
                                            <?php if ($item['status'] === 'approved'): ?>
                                                <button class="btn btn-success" data-bs-toggle="modal" 
                                                        data-bs-target="#shoppingModal<?php echo $item['id']; ?>" title="Add to Shopping">
                                                    <i class="bi bi-cart"></i>
                                                </button>
                                            <?php endif; ?>
                                            <button class="btn btn-info" data-bs-toggle="modal" 
                                                    data-bs-target="#statusModal<?php echo $item['id']; ?>" title="Update Status">
                                                <i class="bi bi-gear"></i>
                                            </button>
                                            <?php if (user_has_permission('procurement.delete')): ?>
                                                <button class="btn btn-danger" data-bs-toggle="modal" 
                                                        data-bs-target="#deleteModal<?php echo $item['id']; ?>" title="Delete">
                                                    <i class="bi bi-trash"></i>
                                                </button>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>

                                <!-- Edit Modal -->
                                <div class="modal fade" id="editItemModal<?php echo $item['id']; ?>" tabindex="-1">
                                    <div class="modal-dialog">
                                        <div class="modal-content">
                                            <div class="modal-header bg-primary text-white">
                                                <h5 class="modal-title">Edit Wanted Item</h5>
                                                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                                            </div>
                                            <form method="POST">
                                                <div class="modal-body">
                                                    <input type="hidden" name="action" value="update_item">
                                                    <input type="hidden" name="item_id" value="<?php echo $item['id']; ?>">
                                                    
                                                    <div class="mb-3">
                                                        <label class="form-label" for="iname<?php echo $item['id']; ?>">Item Name *</label>
                                                        <input type="text" class="form-control" id="iname<?php echo $item['id']; ?>" 
                                                               name="item_name" value="<?php echo h2($item['item_name']); ?>" required>
                                                    </div>
                                                    
                                                    <div class="mb-3">
                                                        <label class="form-label" for="desc<?php echo $item['id']; ?>">Description</label>
                                                        <textarea class="form-control" id="desc<?php echo $item['id']; ?>" 
                                                                  name="description"><?php echo h2($item['description']); ?></textarea>
                                                    </div>
                                                    
                                                    <div class="row">
                                                        <div class="col-md-6">
                                                            <label class="form-label" for="cat<?php echo $item['id']; ?>">Category</label>
                                                            <input type="text" class="form-control" id="cat<?php echo $item['id']; ?>" 
                                                                   name="category" value="<?php echo h2($item['category']); ?>">
                                                        </div>
                                                        <div class="col-md-6">
                                                            <label class="form-label" for="cost<?php echo $item['id']; ?>">Est. Cost</label>
                                                            <input type="number" class="form-control" id="cost<?php echo $item['id']; ?>" 
                                                                   name="estimated_cost" step="0.01" value="<?php echo $item['estimated_cost']; ?>">
                                                        </div>
                                                    </div>
                                                    
                                                    <div class="row mt-3">
                                                        <div class="col-md-6">
                                                            <label class="form-label" for="pri<?php echo $item['id']; ?>">Priority</label>
                                                            <select class="form-select" id="pri<?php echo $item['id']; ?>" name="priority">
                                                                <option value="low" <?php echo $item['priority'] === 'low' ? 'selected' : ''; ?>>Low</option>
                                                                <option value="medium" <?php echo $item['priority'] === 'medium' ? 'selected' : ''; ?>>Medium</option>
                                                                <option value="high" <?php echo $item['priority'] === 'high' ? 'selected' : ''; ?>>High</option>
                                                            </select>
                                                        </div>
                                                        <div class="col-md-6">
                                                            <label class="form-label" for="rea<?php echo $item['id']; ?>">Reason</label>
                                                            <input type="text" class="form-control" id="rea<?php echo $item['id']; ?>" 
                                                                   name="reason" value="<?php echo h2($item['reason']); ?>">
                                                        </div>
                                                    </div>
                                                </div>
                                                <div class="modal-footer">
                                                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                                                    <button type="submit" class="btn btn-primary">Update Item</button>
                                                </div>
                                            </form>
                                        </div>
                                    </div>
                                </div>

                                <!-- Status Update Modal -->
                                <div class="modal fade" id="statusModal<?php echo $item['id']; ?>" tabindex="-1">
                                    <div class="modal-dialog">
                                        <div class="modal-content">
                                            <div class="modal-header bg-info text-white">
                                                <h5 class="modal-title">Update Status</h5>
                                                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                                            </div>
                                            <form method="POST">
                                                <div class="modal-body">
                                                    <input type="hidden" name="action" value="update_status">
                                                    <input type="hidden" name="item_id" value="<?php echo $item['id']; ?>">
                                                    
                                                    <div class="mb-3">
                                                        <label class="form-label" for="status<?php echo $item['id']; ?>">Status *</label>
                                                        <select class="form-select" id="status<?php echo $item['id']; ?>" name="status" required>
                                                            <option value="pending" <?php echo $item['status'] === 'pending' ? 'selected' : ''; ?>>Pending</option>
                                                            <option value="approved" <?php echo $item['status'] === 'approved' ? 'selected' : ''; ?>>Approved</option>
                                                            <option value="rejected" <?php echo $item['status'] === 'rejected' ? 'selected' : ''; ?>>Rejected</option>
                                                            <option value="ordered" <?php echo $item['status'] === 'ordered' ? 'selected' : ''; ?>>Ordered</option>
                                                        </select>
                                                    </div>
                                                </div>
                                                <div class="modal-footer">
                                                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                                                    <button type="submit" class="btn btn-info">Update Status</button>
                                                </div>
                                            </form>
                                        </div>
                                    </div>
                                </div>

                                <!-- Add to Shopping Modal -->
                                <div class="modal fade" id="shoppingModal<?php echo $item['id']; ?>" tabindex="-1">
                                    <div class="modal-dialog">
                                        <div class="modal-content">
                                            <div class="modal-header bg-success text-white">
                                                <h5 class="modal-title">Add to Shopping List</h5>
                                                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                                            </div>
                                            <form method="POST">
                                                <div class="modal-body">
                                                    <input type="hidden" name="action" value="add_to_shopping">
                                                    <input type="hidden" name="item_id" value="<?php echo $item['id']; ?>">
                                                    
                                                    <div class="mb-3">
                                                        <label class="form-label">Item</label>
                                                        <input type="text" class="form-control" value="<?php echo h2($item['item_name']); ?>" disabled>
                                                    </div>
                                                    
                                                    <div class="row">
                                                        <div class="col-md-6">
                                                            <label class="form-label" for="qty<?php echo $item['id']; ?>">Quantity *</label>
                                                            <input type="number" class="form-control" id="qty<?php echo $item['id']; ?>" 
                                                                   name="quantity" step="0.01" min="1" value="1" required>
                                                        </div>
                                                        <div class="col-md-6">
                                                            <label class="form-label" for="unit<?php echo $item['id']; ?>">Unit</label>
                                                            <input type="text" class="form-control" id="unit<?php echo $item['id']; ?>" 
                                                                   name="unit" value="pieces">
                                                        </div>
                                                    </div>
                                                </div>
                                                <div class="modal-footer">
                                                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                                                    <button type="submit" class="btn btn-success">Add to Shopping List</button>
                                                </div>
                                            </form>
                                        </div>
                                    </div>
                                </div>

                                <!-- Delete Modal -->
                                <div class="modal fade" id="deleteModal<?php echo $item['id']; ?>" tabindex="-1">
                                    <div class="modal-dialog">
                                        <div class="modal-content">
                                            <div class="modal-header bg-danger text-white">
                                                <h5 class="modal-title">Confirm Delete</h5>
                                                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                                            </div>
                                            <form method="POST">
                                                <div class="modal-body">
                                                    <p>Are you sure you want to delete this wanted item?</p>
                                                    <p class="text-muted"><strong><?php echo h2($item['item_name']); ?></strong></p>
                                                </div>
                                                <div class="modal-footer">
                                                    <input type="hidden" name="action" value="delete_item">
                                                    <input type="hidden" name="item_id" value="<?php echo $item['id']; ?>">
                                                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                                                    <button type="submit" class="btn btn-danger">Delete</button>
                                                </div>
                                            </form>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <!-- Pagination -->
            <?php if ($total_pages > 1): ?>
                <nav aria-label="Page navigation" class="mt-4">
                    <ul class="pagination justify-content-center">
                        <li class="page-item <?php echo $page <= 1 ? 'disabled' : ''; ?>">
                            <a class="page-link" href="?page=1<?php echo $status ? '&status=' . urlencode($status) : ''; ?><?php echo $priority ? '&priority=' . urlencode($priority) : ''; ?><?php echo $search ? '&search=' . urlencode($search) : ''; ?>">First</a>
                        </li>
                        <li class="page-item <?php echo $page <= 1 ? 'disabled' : ''; ?>">
                            <a class="page-link" href="?page=<?php echo $page - 1; ?><?php echo $status ? '&status=' . urlencode($status) : ''; ?><?php echo $priority ? '&priority=' . urlencode($priority) : ''; ?><?php echo $search ? '&search=' . urlencode($search) : ''; ?>">Previous</a>
                        </li>
                        
                        <?php for ($i = max(1, $page - 2); $i <= min($total_pages, $page + 2); $i++): ?>
                            <li class="page-item <?php echo $i === $page ? 'active' : ''; ?>">
                                <a class="page-link" href="?page=<?php echo $i; ?><?php echo $status ? '&status=' . urlencode($status) : ''; ?><?php echo $priority ? '&priority=' . urlencode($priority) : ''; ?><?php echo $search ? '&search=' . urlencode($search) : ''; ?>">
                                    <?php echo $i; ?>
                                </a>
                            </li>
                        <?php endfor; ?>
                        
                        <li class="page-item <?php echo $page >= $total_pages ? 'disabled' : ''; ?>">
                            <a class="page-link" href="?page=<?php echo $page + 1; ?><?php echo $status ? '&status=' . urlencode($status) : ''; ?><?php echo $priority ? '&priority=' . urlencode($priority) : ''; ?><?php echo $search ? '&search=' . urlencode($search) : ''; ?>">Next</a>
                        </li>
                        <li class="page-item <?php echo $page >= $total_pages ? 'disabled' : ''; ?>">
                            <a class="page-link" href="?page=<?php echo $total_pages; ?><?php echo $status ? '&status=' . urlencode($status) : ''; ?><?php echo $priority ? '&priority=' . urlencode($priority) : ''; ?><?php echo $search ? '&search=' . urlencode($search) : ''; ?>">Last</a>
                        </li>
                    </ul>
                </nav>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Add Wanted Item Modal -->
<div class="modal fade" id="addItemModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title">Add Wanted Item</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <div class="modal-body">
                    <input type="hidden" name="action" value="add_item">
                    
                    <div class="mb-3">
                        <label class="form-label" for="newItemName">Item Name *</label>
                        <input type="text" class="form-control" id="newItemName" name="item_name" placeholder="What item?" required>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label" for="newDescription">Description</label>
                        <textarea class="form-control" id="newDescription" name="description" rows="2" placeholder="Details..."></textarea>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6">
                            <label class="form-label" for="newCategory">Category</label>
                            <input type="text" class="form-control" id="newCategory" name="category" placeholder="e.g., Supplies">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label" for="newCost">Est. Cost</label>
                            <input type="number" class="form-control" id="newCost" name="estimated_cost" step="0.01" min="0" placeholder="0.00">
                        </div>
                    </div>
                    
                    <div class="row mt-3">
                        <div class="col-md-6">
                            <label class="form-label" for="newPriority">Priority</label>
                            <select class="form-select" id="newPriority" name="priority">
                                <option value="low">Low</option>
                                <option value="medium" selected>Medium</option>
                                <option value="high">High</option>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label" for="newReason">Reason</label>
                            <input type="text" class="form-control" id="newReason" name="reason" placeholder="Why needed?">
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Add Item</button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>
      </div>
    </main>
  </div>
</div>

<?php include __DIR__ . '/../../templates/layout/footer.php'; ?>
