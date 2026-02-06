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

require_permission('procurement.view');

// Check if tables exist
function table_exists(mysqli $db, string $table): bool {
    $result = $db->query("SHOW TABLES LIKE '" . $db->real_escape_string($table) . "'");
    return $result && $result->num_rows > 0;
}

$hasShoppingList = table_exists($db, 'procurement_shopping_list');

$message = '';
$message_type = '';
$page = max(1, (int)($_GET['page'] ?? 1));
$per_page = 50;
$offset = ($page - 1) * $per_page;

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
    } elseif ($_POST['action'] === 'delete_item' && user_has_permission('procurement.delete')) {
        $result = handle_delete_item();
        $message = $result['message'];
        $message_type = $result['success'] ? 'success' : 'danger';
    } elseif ($_POST['action'] === 'update_status' && user_has_permission('procurement.update')) {
        $result = handle_update_status();
        $message = $result['message'];
        $message_type = $result['success'] ? 'success' : 'danger';
    }
}

// Filter parameters
$search = trim((string)($_GET['search'] ?? ''));
$status = trim((string)($_GET['status'] ?? ''));
$where = [];
$params = [];
$types = '';

if ($search) {
    $where[] = "(product_name LIKE ? OR description LIKE ?)";
    $search_term = "%$search%";
    $params[] = $search_term;
    $params[] = $search_term;
    $types .= 'ss';
}

if ($status) {
    $where[] = "status = ?";
    $params[] = $status;
    $types .= 's';
}

$where_clause = empty($where) ? '1=1' : implode(' AND ', $where);

// Initialize variables
$total = 0;
$items = [];
$totals = ['total_estimated' => 0, 'total_actual' => 0, 'pending_count' => 0, 'ordered_count' => 0];

// Only execute queries if table exists
if ($hasShoppingList) {
    // Count total
    $count_query = "SELECT COUNT(*) as cnt FROM procurement_shopping_list WHERE $where_clause";
    $st = $db->prepare($count_query);
    if ($st && $types) {
        $st->bind_param($types, ...$params);
    }
    if ($st) {
        $st->execute();
        $total = (int)$st->get_result()->fetch_assoc()['cnt'];
        $st->close();
    }
    $total_pages = ceil($total / $per_page);

    // Fetch items
    $query = "
        SELECT id, product_id, product_name, description, quantity, unit, estimated_cost, 
               actual_cost, status, priority, supplier_id, ordered_date, received_date, 
               user_id, created_at, updated_at
        FROM procurement_shopping_list
    WHERE $where_clause
    ORDER BY priority DESC, created_at DESC
    LIMIT ? OFFSET ?
";

    $st = $db->prepare($query);
    if (!$st) {
        $message = 'Database error';
        $message_type = 'danger';
        $items = [];
    } else {
        $params[] = $per_page;
        $params[] = $offset;
        $types .= 'ii';
        
        $st->bind_param($types, ...$params);
        $st->execute();
        $items = $st->get_result()->fetch_all(MYSQLI_ASSOC);
        $st->close();
    }

    // Fetch totals
    $totals_query = "
        SELECT 
            COUNT(*) as total_items,
            SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending_items,
            SUM(CASE WHEN status = 'ordered' THEN 1 ELSE 0 END) as ordered_items,
            SUM(CASE WHEN status = 'received' THEN 1 ELSE 0 END) as received_items,
            COALESCE(SUM(estimated_cost), 0) as total_estimated,
            COALESCE(SUM(actual_cost), 0) as total_actual
        FROM procurement_shopping_list
        WHERE $where_clause
    ";

    $st = $db->prepare($totals_query);
    if ($st && count($params) > 2) {
        $bind_params = array_slice($params, 0, -2);
        $bind_types = substr($types, 0, -2);
        if ($bind_types) {
            $st->bind_param($bind_types, ...$bind_params);
        }
    }
    if ($st) {
        $st->execute();
        $totals = $st->get_result()->fetch_assoc();
        $st->close();
    }

} // End of table existence check

function h2($text) {
    return htmlspecialchars($text ?? '', ENT_QUOTES, 'UTF-8');
}

function handle_add_item(): array {
    global $db, $user_id;
    
    // Check if table exists
    if (!table_exists($db, 'procurement_shopping_list')) {
        return ['success' => false, 'message' => 'Shopping list table not found'];
    }
    
    $product_name = trim((string)($_POST['product_name'] ?? ''));
    $description = trim((string)($_POST['description'] ?? ''));
    $quantity = (float)($_POST['quantity'] ?? 0);
    $unit = trim((string)($_POST['unit'] ?? ''));
    $estimated_cost = (float)($_POST['estimated_cost'] ?? 0);
    $priority = trim((string)($_POST['priority'] ?? 'medium'));
    
    if (!$product_name || $quantity <= 0 || !$unit) {
        return ['success' => false, 'message' => 'Missing required fields'];
    }
    
    try {
        $status = 'pending';
        $st = $db->prepare("INSERT INTO procurement_shopping_list 
            (product_name, description, quantity, unit, estimated_cost, status, priority, user_id, created_at) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())");
        
        if (!$st) throw new Exception('Prepare failed');
        $st->bind_param('ssddssssi', $product_name, $description, $quantity, $unit, $estimated_cost, $status, $priority, $user_id);
        $st->execute();
        $item_id = $st->insert_id;
        $st->close();
        
        if (function_exists('audit_log')) {
            audit_log('procurement.shopping_add', 'shopping_list', (string)$item_id, "Added: $product_name (Qty: $quantity $unit)");
        }
        
        return ['success' => true, 'message' => 'Shopping list item added'];
    } catch (Exception $e) {
        return ['success' => false, 'message' => $e->getMessage()];
    }
}

function handle_update_item(): array {
    global $db, $user_id;
    
    // Check if table exists
    if (!table_exists($db, 'procurement_shopping_list')) {
        return ['success' => false, 'message' => 'Shopping list table not found'];
    }
    
    $id = (int)($_POST['item_id'] ?? 0);
    $product_name = trim((string)($_POST['product_name'] ?? ''));
    $description = trim((string)($_POST['description'] ?? ''));
    $quantity = (float)($_POST['quantity'] ?? 0);
    $unit = trim((string)($_POST['unit'] ?? ''));
    $estimated_cost = (float)($_POST['estimated_cost'] ?? 0);
    $priority = trim((string)($_POST['priority'] ?? 'medium'));
    
    if ($id <= 0 || !$product_name || $quantity <= 0) {
        return ['success' => false, 'message' => 'Invalid parameters'];
    }
    
    try {
        $st = $db->prepare("UPDATE procurement_shopping_list 
            SET product_name = ?, description = ?, quantity = ?, unit = ?, 
                estimated_cost = ?, priority = ?, updated_at = NOW()
            WHERE id = ? LIMIT 1");
        
        if (!$st) throw new Exception('Prepare failed');
        $st->bind_param('sddsdssi', $product_name, $description, $quantity, $unit, $estimated_cost, $priority, $id);
        $st->execute();
        $st->close();
        
        if (function_exists('audit_log')) {
            audit_log('procurement.shopping_update', 'shopping_list', (string)$id, "Updated: $product_name");
        }
        
        return ['success' => true, 'message' => 'Item updated'];
    } catch (Exception $e) {
        return ['success' => false, 'message' => $e->getMessage()];
    }
}

function handle_delete_item(): array {
    global $db, $user_id;
    
    // Check if table exists
    if (!table_exists($db, 'procurement_shopping_list')) {
        return ['success' => false, 'message' => 'Shopping list table not found'];
    }
    
    $id = (int)($_POST['item_id'] ?? 0);
    
    if ($id <= 0) {
        return ['success' => false, 'message' => 'Invalid item'];
    }
    
    try {
        $st = $db->prepare("DELETE FROM procurement_shopping_list WHERE id = ? LIMIT 1");
        if (!$st) throw new Exception('Prepare failed');
        $st->bind_param('i', $id);
        $st->execute();
        $st->close();
        
        if (function_exists('audit_log')) {
            audit_log('procurement.shopping_delete', 'shopping_list', (string)$id, "Item removed");
        }
        
        return ['success' => true, 'message' => 'Item removed from shopping list'];
    } catch (Exception $e) {
        return ['success' => false, 'message' => $e->getMessage()];
    }
}

function handle_update_status(): array {
    global $db, $user_id;
    
    // Check if table exists
    if (!table_exists($db, 'procurement_shopping_list')) {
        return ['success' => false, 'message' => 'Shopping list table not found'];
    }
    
    $id = (int)($_POST['item_id'] ?? 0);
    $status = trim((string)($_POST['status'] ?? ''));
    $actual_cost = isset($_POST['actual_cost']) ? (float)($_POST['actual_cost']) : null;
    
    if ($id <= 0 || !$status || !in_array($status, ['pending', 'ordered', 'received', 'cancelled'])) {
        return ['success' => false, 'message' => 'Invalid parameters'];
    }
    
    try {
        $update_fields = "status = ?, updated_at = NOW()";
        $params = [$status];
        $types = 's';
        
        if ($actual_cost !== null) {
            $update_fields .= ", actual_cost = ?";
            $params[] = $actual_cost;
            $types .= 'd';
        }
        
        $params[] = $id;
        $types .= 'i';
        
        $st = $db->prepare("UPDATE procurement_shopping_list SET $update_fields WHERE id = ? LIMIT 1");
        if (!$st) throw new Exception('Prepare failed');
        $st->bind_param($types, ...$params);
        $st->execute();
        $st->close();
        
        if (function_exists('audit_log')) {
            audit_log('procurement.shopping_status', 'shopping_list', (string)$id, "Status changed to: $status");
        }
        
        return ['success' => true, 'message' => 'Status updated'];
    } catch (Exception $e) {
        return ['success' => false, 'message' => $e->getMessage()];
    }
}

// Handle CSV export
if (isset($_GET['export']) && $_GET['export'] === 'csv' && $hasShoppingList) {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=shopping_list_' . date('Y-m-d') . '.csv');
    
    $output = fopen('php://output', 'w');
    fputcsv($output, ['ID', 'Product', 'Description', 'Quantity', 'Unit', 'Est. Cost', 'Actual Cost', 'Status', 'Priority', 'Ordered', 'Received']);
    
    $st = $db->prepare("
        SELECT id, product_name, description, quantity, unit, estimated_cost, actual_cost, 
               status, priority, ordered_date, received_date
        FROM procurement_shopping_list
        WHERE $where_clause
        ORDER BY priority DESC, created_at DESC
    ");
    
    if ($st && count($params) > 0) {
        $bind_params = array_slice($params, 0, -2);
        $bind_types = substr($types, 0, -2);
        if ($bind_types) {
            $st->bind_param($bind_types, ...$bind_params);
        }
    }
    if ($st) {
        $st->execute();
        $export_items = $st->get_result()->fetch_all(MYSQLI_ASSOC);
        $st->close();
        
        foreach ($export_items as $item) {
            fputcsv($output, [
                $item['id'],
                $item['product_name'],
                $item['description'],
                $item['quantity'],
                $item['unit'],
                $item['estimated_cost'],
                $item['actual_cost'],
                $item['status'],
                $item['priority'],
                $item['ordered_date'] ? date('M d, Y', strtotime($item['ordered_date'])) : '',
                $item['received_date'] ? date('M d, Y', strtotime($item['received_date'])) : ''
            ]);
        }
    }
    
    fclose($output);
    exit;
}

$page_title = 'Shopping List - Procurement';
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
            <h3 class="mb-2 fw-bold">Shopping List</h3>
            <div class="text-muted">Manage procurement shopping items and orders</div>
          </div>
          <div class="gap-2 d-flex">
            <?php if ($hasShoppingList): ?>
              <a href="?export=csv" class="btn btn-outline-success">
                <i class="bi bi-download me-1"></i> Export CSV
              </a>
            <?php endif; ?>
            <?php if (user_has_permission('procurement.create') && $hasShoppingList): ?>
              <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addItemModal">
                <i class="bi bi-plus-circle me-1"></i> Add Item
              </button>
            <?php endif; ?>
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

        <?php if (!$hasShoppingList): ?>
          <div class="card shadow-sm">
            <div class="card-body text-center py-5">
              <i class="bi bi-exclamation-triangle text-warning" style="font-size: 3rem;"></i>
              <h5 class="mt-3">Shopping List Table Not Found</h5>
              <p class="text-muted">The procurement_shopping_list table does not exist. Please run the database migrations to create the required tables.</p>
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
                      <div class="bg-success bg-opacity-10 rounded-circle p-3">
                        <i class="bi bi-check-circle text-success" style="font-size: 1.2rem;"></i>
                      </div>
                    </div>
                    <div class="flex-grow-1 ms-3">
                      <div class="h5 mb-0 text-success"><?= $totals['received_items'] ?? 0 ?></div>
                      <div class="small text-muted">Received</div>
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
                      <div class="small text-muted">Est. Cost</div>
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
                        <i class="bi bi-cash text-success" style="font-size: 1.2rem;"></i>
                      </div>
                    </div>
                    <div class="flex-grow-1 ms-3">
                      <div class="h5 mb-0 text-success"><?= number_format((float)($totals['total_actual'] ?? 0), 2) ?></div>
                      <div class="small text-muted">Actual Cost</div>
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
                <i class="bi bi-funnel"></i> Search & Filter Shopping Items
              </h6>
            </div>
            <div class="card-body">
              <form method="GET" class="row g-3">
                <div class="col-lg-6 col-md-12">
                  <label for="search" class="form-label">Search Items</label>
                  <input type="text" id="search" name="search" class="form-control" 
                         value="<?= h2($search) ?>" placeholder="Search by product name or description">
                </div>
                <div class="col-lg-3 col-md-6">
                  <label for="status" class="form-label">Status</label>
                  <select id="status" name="status" class="form-select">
                    <option value="">All Statuses</option>
                    <option value="pending" <?= $status === 'pending' ? 'selected' : '' ?>>🟡 Pending</option>
                    <option value="ordered" <?= $status === 'ordered' ? 'selected' : '' ?>>🔵 Ordered</option>
                    <option value="received" <?= $status === 'received' ? 'selected' : '' ?>>🟢 Received</option>
                    <option value="cancelled" <?= $status === 'cancelled' ? 'selected' : '' ?>>⚫ Cancelled</option>
                  </select>
                </div>
                <div class="col-lg-3 col-md-6">
                  <label for="priority" class="form-label">Priority</label>
                  <select id="priority" name="priority" class="form-select">
                    <option value="">All Priorities</option>
                    <option value="high" <?= $priority === 'high' ? 'selected' : '' ?>>🔴 High</option>
                    <option value="medium" <?= $priority === 'medium' ? 'selected' : '' ?>>🟡 Medium</option>
                    <option value="low" <?= $priority === 'low' ? 'selected' : '' ?>>🔵 Low</option>
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

          <!-- Shopping List Table -->
          <div class="card shadow-sm">
            <div class="card-header bg-light">
              <h6 class="mb-0">
                <i class="bi bi-list-check"></i> Shopping List Items
                <span class="badge bg-primary rounded-pill float-end"><?= $total ?></span>
              </h6>
            </div>
            <div class="card-body p-0">
              <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                  <thead class="table-light">
                    <tr>
                      <th style="min-width: 200px;">Product</th>
                      <th style="width: 120px;" class="text-end">Quantity</th>
                      <th style="width: 120px;" class="text-end">Est. Cost</th>
                      <th style="width: 120px;" class="text-end">Actual Cost</th>
                      <th style="width: 100px;">Status</th>
                      <th style="width: 80px;">Priority</th>
                      <th style="width: 120px;" class="text-center">Actions</th>
                    </tr>
                  </thead>
                  <tbody>
                    <?php if (empty($items)): ?>
                      <tr>
                        <td colspan="7" class="text-center text-muted p-4">
                          <i class="bi bi-inbox" style="font-size: 2rem;"></i>
                          <div class="fw-semibold">No shopping items found</div>
                          <div class="small">Try adjusting your search criteria or add new items</div>
                        </td>
                      </tr>
                    <?php else: foreach ($items as $item): 
                      $statusColor = match($item['status']) {
                        'pending' => 'warning',
                        'ordered' => 'info',
                        'received' => 'success',
                        'cancelled' => 'secondary',
                        default => 'secondary'
                      };
                      
                      $statusIcon = match($item['status']) {
                        'pending' => '🟡',
                        'ordered' => '🔵',
                        'received' => '🟢',
                        'cancelled' => '⚫',
                        default => '📄'
                      };
                      
                      $priorityColor = match($item['priority']) {
                        'high' => 'danger',
                        'medium' => 'warning',
                        'low' => 'info',
                        default => 'secondary'
                      };
                    ?>
                      <tr>
                        <td>
                          <div class="fw-semibold"><?= h2($item['product_name']) ?></div>
                          <?php if ($item['description']): ?>
                            <div class="small text-muted"><?= h2($item['description']) ?></div>
                          <?php endif; ?>
                        </td>
                        <td class="text-end">
                          <div class="fw-semibold"><?= number_format((float)$item['quantity'], 2) ?> <?= h2($item['unit']) ?></div>
                        </td>
                        <td class="text-end">
                          <div class="fw-semibold"><?= number_format((float)$item['estimated_cost'], 2) ?></div>
                        </td>
                        <td class="text-end">
                          <?php if ($item['actual_cost']): ?>
                            <div class="fw-semibold text-success"><?= number_format((float)$item['actual_cost'], 2) ?></div>
                          <?php else: ?>
                            <div class="text-muted">-</div>
                          <?php endif; ?>
                        </td>
                        <td>
                          <span class="badge bg-<?= $statusColor ?>">
                            <?= $statusIcon ?> <?= h2(str_replace('_', ' ', ucfirst($item['status']))) ?>
                          </span>
                        </td>
                        <td>
                          <span class="badge bg-<?= $priorityColor ?>">
                            <?= h2(ucfirst($item['priority'])) ?>
                          </span>
                        </td>
                        <td class="text-center">
                          <div class="btn-group btn-group-sm" role="group">
                            <button type="button" class="btn btn-outline-primary" 
                                    data-bs-toggle="modal" data-bs-target="#editItemModal<?= $item['id'] ?>"
                                    title="Edit">
                              <i class="bi bi-pencil"></i>
                            </button>
                            <button type="button" class="btn btn-outline-danger" 
                                    data-bs-toggle="modal" data-bs-target="#deleteItemModal<?= $item['id'] ?>"
                                    title="Delete">
                              <i class="bi bi-trash"></i>
                            </button>
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
          <?php if ($total > $per_page): ?>
            <div class="mt-4">
              <?php $lastPage = max(1, (int)ceil($total / $per_page)); ?>
              <nav aria-label="pagination">
                <ul class="pagination pagination-sm justify-content-center">
                  <?php for ($p = 1; $p <= $lastPage; $p++): ?>
                    <li class="page-item <?= $p === $page ? 'active' : '' ?>">
                      <a class="page-link" href="?<?= h2(http_build_query(array_merge($_GET, ['page'=>$p]))) ?>"><?= $p ?></a>
                    </li>
                  <?php endfor; ?>
                </ul>
              </nav>
            </div>
          <?php endif; ?>

          <!-- Add Item Modal -->
          <div class="modal fade" id="addItemModal" tabindex="-1">
            <div class="modal-dialog">
              <div class="modal-content">
                <div class="modal-header bg-primary text-white">
                  <h5 class="modal-title">
                    <i class="bi bi-plus-circle"></i> Add Shopping List Item
                  </h5>
                  <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST">
                  <div class="modal-body">
                    <input type="hidden" name="action" value="add_item">
                    
                    <div class="mb-3">
                      <label for="newProductName" class="form-label">Product Name *</label>
                      <input type="text" class="form-control" id="newProductName" name="product_name" 
                             placeholder="e.g., Office Supplies" required>
                    </div>
                    
                    <div class="mb-3">
                      <label for="newDescription" class="form-label">Description</label>
                      <textarea class="form-control" id="newDescription" name="description" rows="2" 
                                placeholder="Additional details..."></textarea>
                    </div>
                    
                    <div class="row g-3">
                      <div class="col-md-6">
                        <label for="newQuantity" class="form-label">Quantity *</label>
                        <input type="number" class="form-control" id="newQuantity" name="quantity" 
                               step="0.01" min="0.01" placeholder="0.00" required>
                      </div>
                      <div class="col-md-6">
                        <label for="newUnit" class="form-label">Unit *</label>
                        <input type="text" class="form-control" id="newUnit" name="unit" 
                               placeholder="e.g., pieces, kg, box" required>
                      </div>
                    </div>
                    
                    <div class="row g-3">
                      <div class="col-md-6">
                        <label for="newEstCost" class="form-label">Estimated Cost</label>
                        <input type="number" class="form-control" id="newEstCost" name="estimated_cost" 
                               step="0.01" min="0" placeholder="0.00">
                      </div>
                      <div class="col-md-6">
                        <label for="newPriority" class="form-label">Priority</label>
                        <select class="form-select" id="newPriority" name="priority">
                          <option value="low">Low</option>
                          <option value="medium" selected>Medium</option>
                          <option value="high">High</option>
                        </select>
                      </div>
                    </div>
                    
                    <div class="modal-footer">
                      <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                      <button type="submit" class="btn btn-primary">
                        <i class="bi bi-check-circle"></i> Add Item
                      </button>
                    </div>
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
