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

require_permission('contacts.view');

$message = '';
$message_type = '';
$page = max(1, (int)($_GET['page'] ?? 1));
$per_page = 50;
$offset = ($page - 1) * $per_page;

// Handle actions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] === 'add_supplier' && user_has_permission('contacts.create')) {
        $result = handle_add_supplier();
        $message = $result['message'];
        $message_type = $result['success'] ? 'success' : 'danger';
    } elseif ($_POST['action'] === 'update_supplier' && user_has_permission('contacts.update')) {
        $result = handle_update_supplier();
        $message = $result['message'];
        $message_type = $result['success'] ? 'success' : 'danger';
    } elseif ($_POST['action'] === 'delete_supplier' && user_has_permission('contacts.delete')) {
        $result = handle_delete_supplier();
        $message = $result['message'];
        $message_type = $result['success'] ? 'success' : 'danger';
    }
}

// Apply filters
$where = ["1 = 1"];
$params = [];
$types = '';

$status = trim((string)($_GET['status'] ?? ''));
if ($status && in_array($status, ['active', 'inactive', 'suspended'])) {
    $where[] = "status = ?";
    $params[] = $status;
    $types .= 's';
}

$category = trim((string)($_GET['category'] ?? ''));
if ($category) {
    $where[] = "category = ?";
    $params[] = $category;
    $types .= 's';
}

$search = trim((string)($_GET['search'] ?? ''));
if ($search) {
    $where[] = "(name LIKE ? OR email LIKE ? OR phone LIKE ? OR company_name LIKE ? OR contact_person LIKE ?)";
    $search_term = "%$search%";
    $params[] = $search_term;
    $params[] = $search_term;
    $params[] = $search_term;
    $params[] = $search_term;
    $params[] = $search_term;
    $types .= 'sssss';
}

$where_clause = implode(' AND ', $where);

// Count total
$count_query = "SELECT COUNT(*) as cnt FROM suppliers WHERE $where_clause";
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

// Fetch suppliers
$query = "
    SELECT s.*, 
           COUNT(po.id) as total_orders,
           COALESCE(SUM(po.estimated_cost), 0) as total_spent,
           AVG(s.rating) as avg_rating
    FROM suppliers s
    LEFT JOIN procurement_shopping_list po ON s.id = po.supplier_id
    WHERE $where_clause
    GROUP BY s.id
    ORDER BY s.created_at DESC
    LIMIT ? OFFSET ?
";

$st = $db->prepare($query);
if (!$st) {
    $message = 'Database error';
    $message_type = 'danger';
    $suppliers = [];
} else {
    $bind_params = array_slice($params, 0);
    $bind_params[] = $per_page;
    $bind_params[] = $offset;
    
    $bind_types = $types . 'ii';
    
    if ($bind_types) {
        $st->bind_param($bind_types, ...$bind_params);
    }
    $st->execute();
    $suppliers = $st->get_result()->fetch_all(MYSQLI_ASSOC);
    $st->close();
}

// Fetch totals
$totals_query = "
    SELECT 
        COUNT(*) as total_suppliers,
        SUM(CASE WHEN status = 'active' THEN 1 ELSE 0 END) as active_suppliers,
        SUM(CASE WHEN status = 'inactive' THEN 1 ELSE 0 END) as inactive_suppliers,
        COUNT(DISTINCT category) as total_categories,
        AVG(rating) as average_rating,
        COUNT(DISTINCT CASE WHEN preferred = 1 THEN id END) as preferred_count
    FROM suppliers
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

// Get categories for filter
$categories_query = "SELECT DISTINCT category FROM suppliers WHERE category IS NOT NULL ORDER BY category";
$categories_result = $db->query($categories_query);
$categories = [];
if ($categories_result) {
    $categories = $categories_result->fetch_all(MYSQLI_ASSOC);
}

function h2($text) {
    return htmlspecialchars($text ?? '', ENT_QUOTES, 'UTF-8');
}


function get_rating_stars($rating) {
    $stars = '';
    $rating = (float)($rating ?? 0);
    for ($i = 0; $i < 5; $i++) {
        if ($i < floor($rating)) {
            $stars .= '<i class="bi bi-star-fill text-warning"></i>';
        } elseif ($i < $rating) {
            $stars .= '<i class="bi bi-star-half text-warning"></i>';
        } else {
            $stars .= '<i class="bi bi-star text-muted"></i>';
        }
    }
    return $stars . ' (' . number_format($rating, 1) . ')';
}

function handle_add_supplier(): array {
    global $db, $user_id;
    
    $name = trim((string)($_POST['name'] ?? ''));
    $email = trim((string)($_POST['email'] ?? ''));
    $phone = trim((string)($_POST['phone'] ?? ''));
    $company_name = trim((string)($_POST['company_name'] ?? ''));
    $contact_person = trim((string)($_POST['contact_person'] ?? ''));
    $city = trim((string)($_POST['city'] ?? ''));
    $state = trim((string)($_POST['state'] ?? ''));
    $country = trim((string)($_POST['country'] ?? ''));
    $category = trim((string)($_POST['category'] ?? ''));
    $payment_terms = trim((string)($_POST['payment_terms'] ?? ''));
    $preferred = isset($_POST['preferred']) ? 1 : 0;
    
    if (!$name || !$email) {
        return ['success' => false, 'message' => 'Name and email are required'];
    }
    
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return ['success' => false, 'message' => 'Invalid email address'];
    }
    
    try {
        $status = 'active';
        $rating = 0;
        
        $st = $db->prepare("INSERT INTO suppliers 
            (name, email, phone, company_name, contact_person, city, state, country, 
             category, payment_terms, status, preferred, rating, created_at) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())");
        
        if (!$st) throw new Exception('Prepare failed');
        $st->bind_param('ssssssssssid', $name, $email, $phone, $company_name, $contact_person, 
                        $city, $state, $country, $category, $payment_terms, $status, $preferred, $rating);
        $st->execute();
        $supplier_id = $st->insert_id;
        $st->close();
        
        if (function_exists('audit_log')) {
            audit_log('contacts.supplier_add', 'suppliers', (string)$supplier_id, "Added: $name ($email)");
        }
        
        return ['success' => true, 'message' => 'Supplier added successfully'];
    } catch (Exception $e) {
        return ['success' => false, 'message' => $e->getMessage()];
    }
}

function handle_update_supplier(): array {
    global $db, $user_id;
    
    $id = (int)($_POST['supplier_id'] ?? 0);
    $name = trim((string)($_POST['name'] ?? ''));
    $email = trim((string)($_POST['email'] ?? ''));
    $phone = trim((string)($_POST['phone'] ?? ''));
    $company_name = trim((string)($_POST['company_name'] ?? ''));
    $contact_person = trim((string)($_POST['contact_person'] ?? ''));
    $city = trim((string)($_POST['city'] ?? ''));
    $state = trim((string)($_POST['state'] ?? ''));
    $country = trim((string)($_POST['country'] ?? ''));
    $category = trim((string)($_POST['category'] ?? ''));
    $payment_terms = trim((string)($_POST['payment_terms'] ?? ''));
    $status = trim((string)($_POST['status'] ?? 'active'));
    $rating = (float)($_POST['rating'] ?? 0);
    $preferred = isset($_POST['preferred']) ? 1 : 0;
    
    if ($id <= 0 || !$name || !$email) {
        return ['success' => false, 'message' => 'Invalid parameters'];
    }
    
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return ['success' => false, 'message' => 'Invalid email address'];
    }
    
    if (!in_array($status, ['active', 'inactive', 'suspended'])) {
        return ['success' => false, 'message' => 'Invalid status'];
    }
    
    $rating = max(0, min(5, $rating));
    
    try {
        $st = $db->prepare("UPDATE suppliers 
            SET name = ?, email = ?, phone = ?, company_name = ?, contact_person = ?, 
                city = ?, state = ?, country = ?, category = ?, payment_terms = ?, 
                status = ?, preferred = ?, rating = ?
            WHERE id = ? LIMIT 1");
        
        if (!$st) throw new Exception('Prepare failed');
        $st->bind_param('ssssssssssssi', $name, $email, $phone, $company_name, $contact_person, 
                        $city, $state, $country, $category, $payment_terms, $status, $preferred, $rating, $id);
        $st->execute();
        $st->close();
        
        if (function_exists('audit_log')) {
            audit_log('contacts.supplier_update', 'suppliers', (string)$id, "Updated: $name");
        }
        
        return ['success' => true, 'message' => 'Supplier updated successfully'];
    } catch (Exception $e) {
        return ['success' => false, 'message' => $e->getMessage()];
    }
}

function handle_delete_supplier(): array {
    global $db, $user_id;
    
    $id = (int)($_POST['supplier_id'] ?? 0);
    
    if ($id <= 0) {
        return ['success' => false, 'message' => 'Invalid supplier'];
    }
    
    try {
        // Check if supplier has orders
        $check = $db->prepare("SELECT COUNT(*) as cnt FROM procurement_shopping_list WHERE supplier_id = ? LIMIT 1");
        if (!$check) throw new Exception('Prepare failed');
        $check->bind_param('i', $id);
        $check->execute();
        $has_orders = (int)$check->get_result()->fetch_assoc()['cnt'];
        $check->close();
        
        if ($has_orders > 0) {
            throw new Exception('Cannot delete supplier with existing orders');
        }
        
        $st = $db->prepare("DELETE FROM suppliers WHERE id = ? LIMIT 1");
        if (!$st) throw new Exception('Prepare failed');
        $st->bind_param('i', $id);
        $st->execute();
        $st->close();
        
        if (function_exists('audit_log')) {
            audit_log('contacts.supplier_delete', 'suppliers', (string)$id, 'Supplier deleted');
        }
        
        return ['success' => true, 'message' => 'Supplier deleted successfully'];
    } catch (Exception $e) {
        return ['success' => false, 'message' => $e->getMessage()];
    }
}

// Handle CSV export
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=suppliers_' . date('Y-m-d') . '.csv');
    
    $output = fopen('php://output', 'w');
    fputcsv($output, ['ID', 'Name', 'Email', 'Phone', 'Company', 'Contact Person', 'City', 'State', 
                      'Country', 'Category', 'Payment Terms', 'Status', 'Preferred', 'Rating', 
                      'Total Orders', 'Total Spent', 'Date Added']);
    
    $query = "
        SELECT s.*, 
               COUNT(po.id) as total_orders,
               COALESCE(SUM(po.estimated_cost), 0) as total_spent
        FROM suppliers s
        LEFT JOIN procurement_shopping_list po ON s.id = po.supplier_id
        WHERE $where_clause
        GROUP BY s.id
        ORDER BY s.created_at DESC
    ";
    
    $st = $db->prepare($query);
    if ($st && $types) {
        $bind_params = array_slice($params, 0);
        $st->bind_param($types, ...$bind_params);
    }
    
    if ($st) {
        $st->execute();
        $export_suppliers = $st->get_result()->fetch_all(MYSQLI_ASSOC);
        $st->close();
        
        foreach ($export_suppliers as $supp) {
            fputcsv($output, [
                $supp['id'],
                $supp['name'],
                $supp['email'],
                $supp['phone'],
                $supp['company_name'],
                $supp['contact_person'],
                $supp['city'],
                $supp['state'],
                $supp['country'],
                $supp['category'],
                $supp['payment_terms'],
                $supp['status'],
                $supp['preferred'] ? 'Yes' : 'No',
                $supp['rating'],
                $supp['total_orders'],
                $supp['total_spent'],
                date('M d, Y', strtotime($supp['created_at']))
            ]);
        }
    }
    
    fclose($output);
    exit;
}

$page_title = 'Suppliers';
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
            <h3 class="mb-2 fw-bold">Suppliers</h3>
            <div class="text-muted">Manage supplier information and track procurement relationships</div>
          </div>
          <div class="gap-2 d-flex">
            <a href="?export=csv" class="btn btn-outline-success">
              <i class="bi bi-download me-1"></i> Export CSV
            </a>
            <?php if (user_has_permission('contacts.create')): ?>
              <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addSupplierModal">
                <i class="bi bi-plus-circle me-1"></i> Add Supplier
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

            <!-- Summary Cards -->
        <div class="row g-3 mb-4">
          <div class="col-lg-2 col-md-4 col-sm-6">
            <div class="card shadow-sm h-100">
              <div class="card-body">
                <div class="d-flex align-items-center">
                  <div class="flex-shrink-0">
                    <div class="bg-primary bg-opacity-10 rounded-circle p-3">
                      <i class="bi bi-building text-primary" style="font-size: 1.2rem;"></i>
                    </div>
                  </div>
                  <div class="flex-grow-1 ms-3">
                    <div class="h5 mb-0 text-primary"><?= $totals['total_suppliers'] ?? 0 ?></div>
                    <div class="small text-muted">Total Suppliers</div>
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
                    <div class="h5 mb-0 text-success"><?= $totals['active_suppliers'] ?? 0 ?></div>
                    <div class="small text-muted">Active</div>
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
                      <i class="bi bi-star text-info" style="font-size: 1.2rem;"></i>
                    </div>
                  </div>
                  <div class="flex-grow-1 ms-3">
                    <div class="h5 mb-0 text-info"><?= $totals['preferred_count'] ?? 0 ?></div>
                    <div class="small text-muted">Preferred</div>
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
                      <i class="bi bi-star-fill text-warning" style="font-size: 1.2rem;"></i>
                    </div>
                  </div>
                  <div class="flex-grow-1 ms-3">
                    <div class="h5 mb-0 text-warning"><?= number_format($totals['average_rating'] ?? 0, 1) ?>/5</div>
                    <div class="small text-muted">Avg Rating</div>
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
                    <div class="bg-secondary bg-opacity-10 rounded-circle p-3">
                      <i class="bi bi-tags text-secondary" style="font-size: 1.2rem;"></i>
                    </div>
                  </div>
                  <div class="flex-grow-1 ms-3">
                    <div class="h5 mb-0 text-secondary"><?= $totals['total_categories'] ?? 0 ?></div>
                    <div class="small text-muted">Categories</div>
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
                    <div class="h5 mb-0 text-danger"><?= $totals['inactive_suppliers'] ?? 0 ?></div>
                    <div class="small text-muted">Inactive</div>
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
                <i class="bi bi-funnel"></i> Search & Filter Suppliers
              </h6>
            </div>
            <div class="card-body">
              <form method="GET" class="row g-3">
                <div class="col-lg-4 col-md-12">
                  <label for="search" class="form-label">Search Suppliers</label>
                  <input type="text" id="search" name="search" class="form-control" 
                         placeholder="Search by name, email, or phone..." 
                         value="<?php echo h2($search); ?>">
                </div>
                <div class="col-lg-3 col-md-6">
                  <label for="status" class="form-label">Status</label>
                  <select id="status" name="status" class="form-select">
                    <option value="">All Statuses</option>
                    <option value="active" <?php echo $status === 'active' ? 'selected' : ''; ?>> Active</option>
                    <option value="inactive" <?php echo $status === 'inactive' ? 'selected' : ''; ?>> Inactive</option>
                    <option value="suspended" <?php echo $status === 'suspended' ? 'selected' : ''; ?>> Suspended</option>
                  </select>
                </div>
                <div class="col-lg-3 col-md-6">
                  <label for="category" class="form-label">Category</label>
                  <select id="category" name="category" class="form-select">
                    <option value="">All Categories</option>
                    <?php foreach ($categories as $cat): ?>
                      <option value="<?php echo $cat['category']; ?>" <?php echo $category === $cat['category'] ? 'selected' : ''; ?>>
                        <?php echo h2($cat['category']); ?>
                      </option>
                    <?php endforeach; ?>
                  </select>
                </div>
                <div class="col-lg-2 col-md-6">
                  <label class="form-label">&nbsp;</label>
                  <div>
                    <button type="submit" class="btn btn-primary w-100">
                      <i class="bi bi-search"></i> Filter
                    </button>
                  </div>
                </div>
              </form>
            </div>
          </div>

            <!-- Suppliers Table -->
          <div class="card shadow-sm">
            <div class="card-header bg-light">
              <h6 class="mb-0">
                <i class="bi bi-building"></i> Suppliers
                <span class="badge bg-primary rounded-pill float-end"><?= $total ?></span>
              </h6>
            </div>
            <div class="card-body p-0">
              <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                  <thead class="table-light">
                    <tr>
                      <th style="min-width: 200px;">Name</th>
                      <th style="width: 200px;">Email</th>
                      <th style="width: 120px;">Phone</th>
                      <th style="width: 120px;">Category</th>
                      <th style="width: 80px;">Status</th>
                      <th style="width: 80px;">Rating</th>
                      <th style="width: 80px;" class="text-end">Orders</th>
                      <th style="width: 100px;" class="text-end">Spent</th>
                      <th style="width: 120px;">Payment Terms</th>
                      <th style="width: 120px;" class="text-center">Actions</th>
                    </tr>
                  </thead>
                    <tbody>
                        <?php if (empty($suppliers)): ?>
                            <tr>
                                <td colspan="10" class="text-center text-muted py-4">
                                    No suppliers found
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($suppliers as $supp): ?>
                                <tr>
                                    <td>
                                        <strong><?php echo h2($supp['name']); ?></strong>
                                        <?php if ($supp['preferred']): ?>
                                            <span class="badge bg-warning ms-2"><i class="bi bi-star"></i> Preferred</span>
                                        <?php endif; ?>
                                    </td>
                                    <td><a href="mailto:<?php echo h2($supp['email']); ?>"><?php echo h2($supp['email']); ?></a></td>
                                    <td>
                                        <?php if ($supp['phone']): ?>
                                            <a href="tel:<?php echo h2($supp['phone']); ?>"><?php echo h2($supp['phone']); ?></a>
                                        <?php else: ?>
                                            <span class="text-muted">-</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($supp['category']): ?>
                                            <span class="badge bg-info"><?php echo h2($supp['category']); ?></span>
                                        <?php else: ?>
                                            <span class="text-muted">-</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <span class="badge bg-<?php 
                                            echo match($supp['status']) {
                                                'active' => 'success',
                                                'inactive' => 'warning',
                                                'suspended' => 'danger',
                                                default => 'secondary'
                                            };
                                        ?>">
                                            <?php echo ucfirst($supp['status']); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <small><?php echo get_rating_stars($supp['rating'] ?? 0); ?></small>
                                    </td>
                                    <td><?php echo (int)($supp['total_orders'] ?? 0); ?></td>
                                    <td><?php echo number_format($supp['total_spent'] ?? 0, 2); ?></td>
                                    <td><?php echo h2($supp['payment_terms'] ?? '-'); ?></td>
                                    <td>
                                        <div class="btn-group btn-group-sm" role="group">
                                            <?php if (user_has_permission('contacts.update')): ?>
                                                <button class="btn btn-primary" data-bs-toggle="modal" 
                                                        data-bs-target="#editSupplierModal<?php echo $supp['id']; ?>" title="Edit">
                                                    <i class="bi bi-pencil"></i>
                                                </button>
                                            <?php endif; ?>
                                            <?php if (user_has_permission('contacts.delete') && ((int)($supp['total_orders'] ?? 0)) == 0): ?>
                                                <button class="btn btn-danger" data-bs-toggle="modal" 
                                                        data-bs-target="#deleteModal<?php echo $supp['id']; ?>" title="Delete">
                                                    <i class="bi bi-trash"></i>
                                                </button>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>

                                <!-- Edit Modal -->
                                <div class="modal fade" id="editSupplierModal<?php echo $supp['id']; ?>" tabindex="-1">
                                    <div class="modal-dialog modal-lg">
                                        <div class="modal-content">
                                            <div class="modal-header bg-primary text-white">
                                                <h5 class="modal-title">Edit Supplier</h5>
                                                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                                            </div>
                                            <form method="POST">
                                                <div class="modal-body">
                                                    <input type="hidden" name="action" value="update_supplier">
                                                    <input type="hidden" name="supplier_id" value="<?php echo $supp['id']; ?>">
                                                    
                                                    <div class="row">
                                                        <div class="col-md-6">
                                                            <label class="form-label" for="name<?php echo $supp['id']; ?>">Name *</label>
                                                            <input type="text" class="form-control" id="name<?php echo $supp['id']; ?>" 
                                                                   name="name" value="<?php echo h2($supp['name']); ?>" required>
                                                        </div>
                                                        <div class="col-md-6">
                                                            <label class="form-label" for="email<?php echo $supp['id']; ?>">Email *</label>
                                                            <input type="email" class="form-control" id="email<?php echo $supp['id']; ?>" 
                                                                   name="email" value="<?php echo h2($supp['email']); ?>" required>
                                                        </div>
                                                    </div>
                                                    
                                                    <div class="row mt-3">
                                                        <div class="col-md-6">
                                                            <label class="form-label" for="phone<?php echo $supp['id']; ?>">Phone</label>
                                                            <input type="tel" class="form-control" id="phone<?php echo $supp['id']; ?>" 
                                                                   name="phone" value="<?php echo h2($supp['phone']); ?>">
                                                        </div>
                                                        <div class="col-md-6">
                                                            <label class="form-label" for="company<?php echo $supp['id']; ?>">Company Name</label>
                                                            <input type="text" class="form-control" id="company<?php echo $supp['id']; ?>" 
                                                                   name="company_name" value="<?php echo h2($supp['company_name']); ?>">
                                                        </div>
                                                    </div>
                                                    
                                                    <div class="row mt-3">
                                                        <div class="col-md-6">
                                                            <label class="form-label" for="contact<?php echo $supp['id']; ?>">Contact Person</label>
                                                            <input type="text" class="form-control" id="contact<?php echo $supp['id']; ?>" 
                                                                   name="contact_person" value="<?php echo h2($supp['contact_person']); ?>">
                                                        </div>
                                                        <div class="col-md-6">
                                                            <label class="form-label" for="category<?php echo $supp['id']; ?>">Category</label>
                                                            <input type="text" class="form-control" id="category<?php echo $supp['id']; ?>" 
                                                                   name="category" value="<?php echo h2($supp['category']); ?>">
                                                        </div>
                                                    </div>
                                                    
                                                    <div class="row mt-3">
                                                        <div class="col-md-4">
                                                            <label class="form-label" for="city<?php echo $supp['id']; ?>">City</label>
                                                            <input type="text" class="form-control" id="city<?php echo $supp['id']; ?>" 
                                                                   name="city" value="<?php echo h2($supp['city']); ?>">
                                                        </div>
                                                        <div class="col-md-4">
                                                            <label class="form-label" for="state<?php echo $supp['id']; ?>">State</label>
                                                            <input type="text" class="form-control" id="state<?php echo $supp['id']; ?>" 
                                                                   name="state" value="<?php echo h2($supp['state']); ?>">
                                                        </div>
                                                        <div class="col-md-4">
                                                            <label class="form-label" for="country<?php echo $supp['id']; ?>">Country</label>
                                                            <input type="text" class="form-control" id="country<?php echo $supp['id']; ?>" 
                                                                   name="country" value="<?php echo h2($supp['country']); ?>">
                                                        </div>
                                                    </div>
                                                    
                                                    <div class="row mt-3">
                                                        <div class="col-md-6">
                                                            <label class="form-label" for="payment<?php echo $supp['id']; ?>">Payment Terms</label>
                                                            <input type="text" class="form-control" id="payment<?php echo $supp['id']; ?>" 
                                                                   name="payment_terms" placeholder="e.g., Net 30, 2/10 Net 30" value="<?php echo h2($supp['payment_terms']); ?>">
                                                        </div>
                                                        <div class="col-md-3">
                                                            <label class="form-label" for="rating<?php echo $supp['id']; ?>">Rating (0-5)</label>
                                                            <input type="number" class="form-control" id="rating<?php echo $supp['id']; ?>" 
                                                                   name="rating" step="0.5" min="0" max="5" value="<?php echo $supp['rating']; ?>">
                                                        </div>
                                                        <div class="col-md-3">
                                                            <label class="form-label" for="status<?php echo $supp['id']; ?>">Status</label>
                                                            <select class="form-select" id="status<?php echo $supp['id']; ?>" name="status">
                                                                <option value="active" <?php echo $supp['status'] === 'active' ? 'selected' : ''; ?>>Active</option>
                                                                <option value="inactive" <?php echo $supp['status'] === 'inactive' ? 'selected' : ''; ?>>Inactive</option>
                                                                <option value="suspended" <?php echo $supp['status'] === 'suspended' ? 'selected' : ''; ?>>Suspended</option>
                                                            </select>
                                                        </div>
                                                    </div>
                                                    
                                                    <div class="row mt-3">
                                                        <div class="col-md-12">
                                                            <div class="form-check">
                                                                <input class="form-check-input" type="checkbox" id="preferred<?php echo $supp['id']; ?>" 
                                                                       name="preferred" <?php echo $supp['preferred'] ? 'checked' : ''; ?>>
                                                                <label class="form-check-label" for="preferred<?php echo $supp['id']; ?>">
                                                                    Mark as Preferred Supplier
                                                                </label>
                                                            </div>
                                                        </div>
                                                    </div>
                                                </div>
                                                <div class="modal-footer">
                                                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                                                    <button type="submit" class="btn btn-primary">Update Supplier</button>
                                                </div>
                                            </form>
                                        </div>
                                    </div>
                                </div>

                                <!-- Delete Modal -->
                                <div class="modal fade" id="deleteModal<?php echo $supp['id']; ?>" tabindex="-1">
                                    <div class="modal-dialog">
                                        <div class="modal-content">
                                            <div class="modal-header bg-danger text-white">
                                                <h5 class="modal-title">Confirm Delete</h5>
                                                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                                            </div>
                                            <form method="POST">
                                                <div class="modal-body">
                                                    <p>Are you sure you want to delete this supplier?</p>
                                                    <p class="text-muted"><strong><?php echo h2($supp['name']); ?></strong></p>
                                                </div>
                                                <div class="modal-footer">
                                                    <input type="hidden" name="action" value="delete_supplier">
                                                    <input type="hidden" name="supplier_id" value="<?php echo $supp['id']; ?>">
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
                            <a class="page-link" href="?page=1<?php echo $status ? '&status=' . urlencode($status) : ''; ?><?php echo $category ? '&category=' . urlencode($category) : ''; ?><?php echo $search ? '&search=' . urlencode($search) : ''; ?>">First</a>
                        </li>
                        <li class="page-item <?php echo $page <= 1 ? 'disabled' : ''; ?>">
                            <a class="page-link" href="?page=<?php echo $page - 1; ?><?php echo $status ? '&status=' . urlencode($status) : ''; ?><?php echo $category ? '&category=' . urlencode($category) : ''; ?><?php echo $search ? '&search=' . urlencode($search) : ''; ?>">Previous</a>
                        </li>
                        
                        <?php for ($i = max(1, $page - 2); $i <= min($total_pages, $page + 2); $i++): ?>
                            <li class="page-item <?php echo $i === $page ? 'active' : ''; ?>">
                                <a class="page-link" href="?page=<?php echo $i; ?><?php echo $status ? '&status=' . urlencode($status) : ''; ?><?php echo $category ? '&category=' . urlencode($category) : ''; ?><?php echo $search ? '&search=' . urlencode($search) : ''; ?>">
                                    <?php echo $i; ?>
                                </a>
                            </li>
                        <?php endfor; ?>
                        
                        <li class="page-item <?php echo $page >= $total_pages ? 'disabled' : ''; ?>">
                            <a class="page-link" href="?page=<?php echo $page + 1; ?><?php echo $status ? '&status=' . urlencode($status) : ''; ?><?php echo $category ? '&category=' . urlencode($category) : ''; ?><?php echo $search ? '&search=' . urlencode($search) : ''; ?>">Next</a>
                        </li>
                        <li class="page-item <?php echo $page >= $total_pages ? 'disabled' : ''; ?>">
                            <a class="page-link" href="?page=<?php echo $total_pages; ?><?php echo $status ? '&status=' . urlencode($status) : ''; ?><?php echo $category ? '&category=' . urlencode($category) : ''; ?><?php echo $search ? '&search=' . urlencode($search) : ''; ?>">Last</a>
                        </li>
                    </ul>
                </nav>
          <?php endif; ?>
      </div>
    </main>
  </div>
</div>

<!-- Add Supplier Modal -->
<div class="modal fade" id="addSupplierModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title">Add New Supplier</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <div class="modal-body">
                    <input type="hidden" name="action" value="add_supplier">
                    
                    <div class="row">
                        <div class="col-md-6">
                            <label class="form-label" for="newName">Name *</label>
                            <input type="text" class="form-control" id="newName" name="name" placeholder="Supplier name" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label" for="newEmail">Email *</label>
                            <input type="email" class="form-control" id="newEmail" name="email" placeholder="email@example.com" required>
                        </div>
                    </div>
                    
                    <div class="row mt-3">
                        <div class="col-md-6">
                            <label class="form-label" for="newPhone">Phone</label>
                            <input type="tel" class="form-control" id="newPhone" name="phone" placeholder="+1 (555) 123-4567">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label" for="newCompany">Company Name</label>
                            <input type="text" class="form-control" id="newCompany" name="company_name" placeholder="Company name">
                        </div>
                    </div>
                    
                    <div class="row mt-3">
                        <div class="col-md-6">
                            <label class="form-label" for="newContact">Contact Person</label>
                            <input type="text" class="form-control" id="newContact" name="contact_person" placeholder="Contact person name">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label" for="newCategory">Category</label>
                            <input type="text" class="form-control" id="newCategory" name="category" placeholder="e.g., Electronics, Raw Materials">
                        </div>
                    </div>
                    
                    <div class="row mt-3">
                        <div class="col-md-4">
                            <label class="form-label" for="newCity">City</label>
                            <input type="text" class="form-control" id="newCity" name="city" placeholder="City">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label" for="newState">State</label>
                            <input type="text" class="form-control" id="newState" name="state" placeholder="State">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label" for="newCountry">Country</label>
                            <input type="text" class="form-control" id="newCountry" name="country" placeholder="Country">
                        </div>
                    </div>
                    
                    <div class="row mt-3">
                        <div class="col-md-12">
                            <label class="form-label" for="newPayment">Payment Terms</label>
                            <input type="text" class="form-control" id="newPayment" name="payment_terms" placeholder="e.g., Net 30, 2/10 Net 30">
                        </div>
                    </div>
                    
                    <div class="row mt-3">
                        <div class="col-md-12">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" id="newPreferred" name="preferred">
                                <label class="form-check-label" for="newPreferred">
                                    Mark as Preferred Supplier
                                </label>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Add Supplier</button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php include __DIR__ . '/../../templates/layout/footer.php'; ?>
