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

// Handle actions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] === 'add_to_shopping' && user_has_permission('procurement.create')) {
        $result = handle_add_to_shopping();
        $message = $result['message'];
        $message_type = $result['success'] ? 'success' : 'danger';
    }
}

// Apply filters
$where = ["1 = 1"];
$params = [];
$types = '';

$category = trim((string)($_GET['category'] ?? ''));
if ($category) {
    $where[] = "pc.id = ?";
    $params[] = $category;
    $types .= 'i';
}

$type = trim((string)($_GET['type'] ?? ''));
if ($type && in_array($type, ['low_stock', 'popular', 'trending'])) {
    if ($type === 'low_stock') {
        $where[] = "p.qty_on_hand <= p.low_level";
    } elseif ($type === 'popular') {
        $where[] = "p.qty_on_hand <= (p.low_level * 1.5)";
    } elseif ($type === 'trending') {
        // Items sold in last 30 days
        $where[] = "p.id IN (SELECT DISTINCT product_id FROM sale_items 
                           WHERE created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY))";
    }
}

$search = trim((string)($_GET['search'] ?? ''));
if ($search) {
    $where[] = "(p.name LIKE ? OR p.sku LIKE ?)";
    $search_term = "%$search%";
    $params[] = $search_term;
    $params[] = $search_term;
    $types .= 'ss';
}

$where_clause = implode(' AND ', $where);

// Count total
$count_query = "
    SELECT COUNT(*) as cnt 
    FROM products p
    LEFT JOIN product_categories pc ON p.category_id = pc.id
    WHERE $where_clause AND p.qty_on_hand < p.low_level
";

$st = $db->prepare($count_query);
if ($st && $types) {
    $bind_params = array_slice($params, 0, -2);
    $bind_types = substr($types, 0, -2);
    if ($bind_types) {
        $st->bind_param($bind_types, ...$bind_params);
    }
}
if ($st) {
    $st->execute();
    $total = (int)$st->get_result()->fetch_assoc()['cnt'];
    $st->close();
}
$total_pages = ceil($total / $per_page);

// Fetch suggested items
$query = "
    SELECT p.id, p.name, p.sku, p.qty_on_hand, p.low_level, p.cost_price, p.retail_price, pc.name as category,
           (SELECT SUM(si.qty_base) FROM sale_items si 
            WHERE si.product_id = p.id AND si.created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)) as sales_30day,
           p.created_at
    FROM products p
    LEFT JOIN product_categories pc ON p.category_id = pc.id
    WHERE $where_clause AND p.qty_on_hand < p.low_level
    ORDER BY (p.low_level - p.qty_on_hand) DESC
    LIMIT ? OFFSET ?
";

$st = $db->prepare($query);
if (!$st) {
    $message = 'Database error';
    $message_type = 'danger';
    $items = [];
} else {
    $bind_params = array_slice($params, 0, -2);
    $bind_types = substr($types, 0, -2);
    
    $bind_params[] = $per_page;
    $bind_params[] = $offset;
    $bind_types .= 'ii';
    
    $st->bind_param($bind_types, ...$bind_params);
    $st->execute();
    $items = $st->get_result()->fetch_all(MYSQLI_ASSOC);
    $st->close();
}

// Fetch totals
$totals_query = "
    SELECT 
        COUNT(*) as total_items,
        SUM(p.cost_price) as total_cost,
        SUM(p.qty_on_hand) as current_stock,
        SUM(p.low_level) as total_reorder_level
    FROM products p
    WHERE $where_clause AND p.qty_on_hand < p.low_level
";

$st = $db->prepare($totals_query);
if ($st && count($bind_params) > 2) {
    $bind_params_short = array_slice($bind_params, 0, -2);
    $bind_types_short = substr($bind_types, 0, -2);
    if ($bind_types_short) {
        $st->bind_param($bind_types_short, ...$bind_params_short);
    }
}
if ($st) {
    $st->execute();
    $totals = $st->get_result()->fetch_assoc();
    $st->close();
}

// Fetch categories for filter
$categories = [];
$st = $db->prepare("SELECT id, name FROM product_categories ORDER BY name");
if ($st) {
    $st->execute();
    $categories = $st->get_result()->fetch_all(MYSQLI_ASSOC);
    $st->close();
}

function h2($text) {
    return htmlspecialchars($text ?? '', ENT_QUOTES, 'UTF-8');
}


function handle_add_to_shopping(): array {
    global $db, $user_id;
    
    $product_id = (int)($_POST['product_id'] ?? 0);
    $quantity = (float)($_POST['quantity'] ?? 0);
    $supplier_id = isset($_POST['supplier_id']) ? (int)($_POST['supplier_id']) : null;
    
    if ($product_id <= 0 || $quantity <= 0) {
        return ['success' => false, 'message' => 'Invalid parameters'];
    }
    
    try {
        // Get product details
        $check = $db->prepare("SELECT name, cost_price FROM products WHERE id = ? LIMIT 1");
        if (!$check) throw new Exception('Prepare failed');
        $check->bind_param('i', $product_id);
        $check->execute();
        $product = $check->get_result()->fetch_assoc();
        $check->close();
        
        if (!$product) {
            throw new Exception('Product not found');
        }
        
        $estimated_cost = $quantity * $product['cost_price'];
        
        // Add to shopping list
        $st = $db->prepare("INSERT INTO procurement_shopping_list 
            (product_id, product_name, quantity, unit, estimated_cost, status, priority, supplier_id, user_id, created_at) 
            VALUES (?, ?, ?, 'pieces', ?, 'pending', 'medium', ?, ?, NOW())");
        
        if (!$st) throw new Exception('Prepare failed');
        $unit = 'pieces';
        $status = 'pending';
        $priority = 'medium';
        $st->bind_param('isdsii', $product_id, $product['name'], $quantity, $estimated_cost, $supplier_id, $user_id);
        $st->execute();
        $item_id = $st->insert_id;
        $st->close();
        
        if (function_exists('audit_log')) {
            audit_log('procurement.suggest_to_shopping', 'shopping_list', (string)$item_id, "Added from suggestions: {$product['name']} (Qty: $quantity)");
        }
        
        return ['success' => true, 'message' => 'Item added to shopping list'];
    } catch (Exception $e) {
        return ['success' => false, 'message' => $e->getMessage()];
    }
}

// Handle CSV export
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=suggested_list_' . date('Y-m-d') . '.csv');
    
    $output = fopen('php://output', 'w');
    fputcsv($output, ['Product ID', 'Product Name', 'SKU', 'Category', 'Current Stock', 'Reorder Level', 'Unit Cost', 'Total Cost', 'Sales (30d)', 'Priority']);
    
    $query = "
        SELECT p.id, p.name, p.sku, pc.name as category, p.qty_on_hand, p.low_level, 
               p.cost_price,
               (SELECT SUM(si.qty_base) FROM sale_items si 
                WHERE si.product_id = p.id AND si.created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)) as sales_30day
        FROM products p
        LEFT JOIN product_categories pc ON p.category_id = pc.id
        WHERE $where_clause AND p.qty_on_hand < p.low_level
        ORDER BY (p.low_level - p.qty_on_hand) DESC
    ";
    
    $st = $db->prepare($query);
    if ($st) {
        if (count($bind_params) > 2) {
            $bind_params_short = array_slice($bind_params, 0, -2);
            $bind_types_short = substr($bind_types, 0, -2);
            if ($bind_types_short) {
                $st->bind_param($bind_types_short, ...$bind_params_short);
            }
        }
        $st->execute();
        $export_items = $st->get_result()->fetch_all(MYSQLI_ASSOC);
        $st->close();
        
        foreach ($export_items as $item) {
            $total_cost = $item['reorder_quantity'] * $item['cost_price'];
            $priority = $item['quantity'] <= 0 ? 'Critical' : ($item['quantity'] <= $item['reorder_level'] / 2 ? 'High' : 'Medium');
            
            fputcsv($output, [
                $item['id'],
                $item['name'],
                $item['sku'],
                $item['category'],
                $item['quantity'],
                $item['reorder_level'],
                $item['reorder_quantity'],
                $item['cost_price'],
                $total_cost,
                $item['sales_30day'] ?? 0,
                $priority
            ]);
        }
    }
    
    fclose($output);
    exit;
}

$page_title = 'Suggested Procurement List';
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
            <h3 class="mb-2 fw-bold">Suggested Procurement List</h3>
            <div class="text-muted">AI-powered suggestions for restocking based on current inventory and sales trends</div>
          </div>
          <div class="gap-2 d-flex">
            <a href="?export=csv" class="btn btn-outline-success">
              <i class="bi bi-download me-1"></i> Export CSV
            </a>
            <a href="<?php echo $base_url; ?>modules/procurement/shopping_list.php" class="btn btn-outline-primary">
              <i class="bi bi-list me-1"></i> Shopping List
            </a>
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

            <!-- Summary Cards -->
        <div class="row g-3 mb-4">
          <div class="col-lg-3 col-md-6">
            <div class="card shadow-sm h-100">
              <div class="card-body">
                <div class="d-flex align-items-center">
                  <div class="flex-shrink-0">
                    <div class="bg-danger bg-opacity-10 rounded-circle p-3">
                      <i class="bi bi-exclamation-triangle text-danger" style="font-size: 1.2rem;"></i>
                    </div>
                  </div>
                  <div class="flex-grow-1 ms-3">
                    <div class="h5 mb-0 text-danger"><?= $totals['total_items'] ?? 0 ?></div>
                    <div class="small text-muted">Items to Reorder</div>
                  </div>
                </div>
              </div>
            </div>
          </div>
          <div class="col-lg-3 col-md-6">
            <div class="card shadow-sm h-100">
              <div class="card-body">
                <div class="d-flex align-items-center">
                  <div class="flex-shrink-0">
                    <div class="bg-warning bg-opacity-10 rounded-circle p-3">
                      <i class="bi bi-box text-warning" style="font-size: 1.2rem;"></i>
                    </div>
                  </div>
                  <div class="flex-grow-1 ms-3">
                    <div class="h5 mb-0 text-warning"><?= number_format($totals['current_stock'] ?? 0, 0) ?></div>
                    <div class="small text-muted">Current Stock</div>
                  </div>
                </div>
              </div>
            </div>
          </div>
          <div class="col-lg-3 col-md-6">
            <div class="card shadow-sm h-100">
              <div class="card-body">
                <div class="d-flex align-items-center">
                  <div class="flex-shrink-0">
                    <div class="bg-primary bg-opacity-10 rounded-circle p-3">
                      <i class="bi bi-cash-stack text-primary" style="font-size: 1.2rem;"></i>
                    </div>
                  </div>
                  <div class="flex-grow-1 ms-3">
                    <div class="h5 mb-0 text-primary"><?= number_format($totals['total_cost'] ?? 0, 2) ?></div>
                    <div class="small text-muted">Est. Reorder Cost</div>
                  </div>
                </div>
              </div>
            </div>
          </div>
          <div class="col-lg-3 col-md-6">
            <div class="card shadow-sm h-100">
              <div class="card-body">
                <div class="d-flex align-items-center">
                  <div class="flex-shrink-0">
                    <div class="bg-info bg-opacity-10 rounded-circle p-3">
                      <i class="bi bi-graph-up text-info" style="font-size: 1.2rem;"></i>
                    </div>
                  </div>
                  <div class="flex-grow-1 ms-3">
                    <div class="h5 mb-0 text-info"><?= number_format(($totals['total_reorder_level'] ?? 0) - ($totals['current_stock'] ?? 0), 0) ?></div>
                    <div class="small text-muted">Reorder Level Gap</div>
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
                <i class="bi bi-funnel"></i> Search & Filter Suggestions
              </h6>
            </div>
            <div class="card-body">
              <form method="GET" class="row g-3">
                <div class="col-lg-4 col-md-12">
                  <label for="search" class="form-label">Search Products</label>
                  <input type="text" id="search" name="search" class="form-control" 
                         placeholder="Search by product name or SKU..." 
                         value="<?php echo h2($search); ?>">
                </div>
                <div class="col-lg-3 col-md-6">
                  <label for="category" class="form-label">Category</label>
                  <select id="category" name="category" class="form-select">
                    <option value="">All Categories</option>
                    <?php foreach ($categories as $cat): ?>
                      <option value="<?php echo $cat['id']; ?>" <?php echo $category == $cat['id'] ? 'selected' : ''; ?>>
                        <?php echo h2($cat['name']); ?>
                      </option>
                    <?php endforeach; ?>
                  </select>
                </div>
                <div class="col-lg-3 col-md-6">
                  <label for="type" class="form-label">Suggestion Type</label>
                  <select id="type" name="type" class="form-select">
                    <option value="">All Suggestions</option>
                    <option value="low_stock" <?php echo $type === 'low_stock' ? 'selected' : ''; ?>>🔴 Low Stock</option>
                    <option value="popular" <?php echo $type === 'popular' ? 'selected' : ''; ?>>🟡 Popular</option>
                    <option value="trending" <?php echo $type === 'trending' ? 'selected' : ''; ?>>🔵 Trending (30d)</option>
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

            <!-- Suggestions Table -->
          <div class="card shadow-sm">
            <div class="card-header bg-light">
              <h6 class="mb-0">
                <i class="bi bi-lightbulb"></i> Suggested Items
                <span class="badge bg-primary rounded-pill float-end"><?= $total ?></span>
              </h6>
            </div>
            <div class="card-body p-0">
              <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                  <thead class="table-light">
                    <tr>
                      <th style="min-width: 200px;">Product</th>
                      <th style="width: 100px;">SKU</th>
                      <th style="width: 120px;">Category</th>
                      <th style="width: 100px;" class="text-end">Current Stock</th>
                      <th style="width: 100px;" class="text-end">Reorder Level</th>
                      <th style="width: 100px;" class="text-end">Suggest Order</th>
                      <th style="width: 100px;" class="text-end">Unit Cost</th>
                      <th style="width: 100px;" class="text-end">Est. Cost</th>
                      <th style="width: 80px;" class="text-end">Sales (30d)</th>
                      <th style="width: 80px;">Priority</th>
                      <th style="width: 120px;" class="text-center">Actions</th>
                    </tr>
                  </thead>
                    <tbody>
                        <?php if (empty($items)): ?>
                            <tr>
                                <td colspan="11" class="text-center text-muted py-4">
                                    No items need reordering at this time.
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($items as $item): 
                                $reorder_qty = $item['reorder_quantity'] ?? 10;
                                $est_cost = $reorder_qty * $item['cost_price'];
                                $shortage = max(0, $item['reorder_level'] - $item['quantity']);
                                $priority = $item['quantity'] <= 0 ? 'Critical' : ($shortage > $item['reorder_level'] / 2 ? 'High' : 'Medium');
                            ?>
                                <tr>
                                    <td>
                                        <strong><?php echo h2($item['name']); ?></strong>
                                    </td>
                                    <td><small class="text-muted"><?php echo h2($item['sku']); ?></small></td>
                                    <td><?php echo h2($item['category'] ?? '-'); ?></td>
                                    <td>
                                        <span class="badge bg-<?php echo $item['quantity'] <= 0 ? 'danger' : 'warning'; ?>">
                                            <?php echo number_format($item['quantity'], 2); ?>
                                        </span>
                                    </td>
                                    <td><?php echo number_format($item['reorder_level'], 2); ?></td>
                                    <td class="text-primary fw-bold"><?php echo number_format($reorder_qty, 2); ?></td>
                                    <td><?php echo number_format($item['cost_price'], 2); ?></td>
                                    <td><?php echo number_format($est_cost, 2); ?></td>
                                    <td>
                                        <?php if ($item['sales_30day']): ?>
                                            <span class="badge bg-info"><?php echo number_format($item['sales_30day'], 0); ?></span>
                                        <?php else: ?>
                                            <span class="text-muted">-</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <span class="badge bg-<?php 
                                            echo match($priority) {
                                                'Critical' => 'danger',
                                                'High' => 'warning',
                                                default => 'info'
                                            };
                                        ?>">
                                            <?php echo $priority; ?>
                                        </span>
                                    </td>
                                    <td>
                                        <?php if (user_has_permission('procurement.create')): ?>
                                            <button class="btn btn-sm btn-primary" data-bs-toggle="modal" 
                                                    data-bs-target="#addModal<?php echo $item['id']; ?>" title="Add to Shopping List">
                                                <i class="bi bi-plus-circle"></i>
                                            </button>
                                        <?php endif; ?>
                                    </td>
                                </tr>

                                <!-- Add to Shopping List Modal -->
                                <div class="modal fade" id="addModal<?php echo $item['id']; ?>" tabindex="-1">
                                    <div class="modal-dialog">
                                        <div class="modal-content">
                                            <div class="modal-header bg-primary text-white">
                                                <h5 class="modal-title">Add to Shopping List</h5>
                                                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                                            </div>
                                            <form method="POST">
                                                <div class="modal-body">
                                                    <input type="hidden" name="action" value="add_to_shopping">
                                                    <input type="hidden" name="product_id" value="<?php echo $item['id']; ?>">
                                                    
                                                    <div class="mb-3">
                                                        <label class="form-label">Product</label>
                                                        <input type="text" class="form-control" value="<?php echo h2($item['name']); ?>" disabled>
                                                    </div>
                                                    
                                                    <div class="row">
                                                        <div class="col-md-6">
                                                            <label class="form-label">SKU</label>
                                                            <input type="text" class="form-control" value="<?php echo h2($item['sku']); ?>" disabled>
                                                        </div>
                                                        <div class="col-md-6">
                                                            <label class="form-label">Unit Cost</label>
                                                            <input type="text" class="form-control" value="<?php echo number_format($item['cost_price'], 2); ?>" disabled>
                                                        </div>
                                                    </div>
                                                    
                                                    <div class="row mt-3">
                                                        <div class="col-md-6">
                                                            <label class="form-label" for="qty<?php echo $item['id']; ?>">Quantity to Order *</label>
                                                            <input type="number" class="form-control" id="qty<?php echo $item['id']; ?>" 
                                                                   name="quantity" step="0.01" min="1" value="<?php echo $reorder_qty; ?>" required>
                                                        </div>
                                                        <div class="col-md-6">
                                                            <label class="form-label">Est. Total Cost</label>
                                                            <input type="text" class="form-control" value="<?php echo number_format($est_cost, 2); ?>" disabled>
                                                        </div>
                                                    </div>
                                                    
                                                    <div class="mb-3 mt-3">
                                                        <label class="form-label" for="supplier<?php echo $item['id']; ?>">Supplier</label>
                                                        <input type="hidden" name="supplier_id" id="supplier<?php echo $item['id']; ?>" value="">
                                                        <input type="text" class="form-control" placeholder="Optional" data-product-id="<?php echo $item['id']; ?>">
                                                    </div>
                                                </div>
                                                <div class="modal-footer">
                                                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                                                    <button type="submit" class="btn btn-primary">Add to Shopping List</button>
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
            </div>
          </div>

          <!-- Pagination -->
          <?php if ($total_pages > 1): ?>
            <nav aria-label="Page navigation" class="mt-4">
              <ul class="pagination justify-content-center">
                <li class="page-item <?php echo $page <= 1 ? 'disabled' : ''; ?>">
                  <a class="page-link" href="?page=1<?php echo $category ? '&category=' . urlencode($category) : ''; ?><?php echo $type ? '&type=' . urlencode($type) : ''; ?><?php echo $search ? '&search=' . urlencode($search) : ''; ?>">First</a>
                </li>
                <li class="page-item <?php echo $page <= 1 ? 'disabled' : ''; ?>">
                  <a class="page-link" href="?page=<?php echo $page - 1; ?><?php echo $category ? '&category=' . urlencode($category) : ''; ?><?php echo $type ? '&type=' . urlencode($type) : ''; ?><?php echo $search ? '&search=' . urlencode($search) : ''; ?>">Previous</a>
                </li>
                
                <?php for ($i = max(1, $page - 2); $i <= min($total_pages, $page + 2); $i++): ?>
                  <li class="page-item <?php echo $i === $page ? 'active' : ''; ?>">
                    <a class="page-link" href="?page=<?php echo $i; ?><?php echo $category ? '&category=' . urlencode($category) : ''; ?><?php echo $type ? '&type=' . urlencode($type) : ''; ?><?php echo $search ? '&search=' . urlencode($search) : ''; ?>">
                      <?php echo $i; ?>
                    </a>
                  </li>
                <?php endfor; ?>
                
                <li class="page-item <?php echo $page >= $total_pages ? 'disabled' : ''; ?>">
                  <a class="page-link" href="?page=<?php echo $page + 1; ?><?php echo $category ? '&category=' . urlencode($category) : ''; ?><?php echo $type ? '&type=' . urlencode($type) : ''; ?><?php echo $search ? '&search=' . urlencode($search) : ''; ?>">Next</a>
                </li>
                <li class="page-item <?php echo $page >= $total_pages ? 'disabled' : ''; ?>">
                  <a class="page-link" href="?page=<?php echo $total_pages; ?><?php echo $category ? '&category=' . urlencode($category) : ''; ?><?php echo $type ? '&type=' . urlencode($type) : ''; ?><?php echo $search ? '&search=' . urlencode($search) : ''; ?>">Last</a>
                </li>
              </ul>
            </nav>
          <?php endif; ?>
      </div>
    </main>
  </div>
</div>

<?php include __DIR__ . '/../../templates/layout/footer.php'; ?>
