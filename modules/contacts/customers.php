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
    if ($_POST['action'] === 'add_customer' && user_has_permission('contacts.create')) {
        $result = handle_add_customer();
        $message = $result['message'];
        $message_type = $result['success'] ? 'success' : 'danger';
    } elseif ($_POST['action'] === 'update_customer' && user_has_permission('contacts.update')) {
        $result = handle_update_customer();
        $message = $result['message'];
        $message_type = $result['success'] ? 'success' : 'danger';
    } elseif ($_POST['action'] === 'delete_customer' && user_has_permission('contacts.delete')) {
        $result = handle_delete_customer();
        $message = $result['message'];
        $message_type = $result['success'] ? 'success' : 'danger';
    }
}

// Apply filters
$where = ["1 = 1"];
$params = [];
$types = '';

$status = trim((string)($_GET['status'] ?? ''));
if ($status && in_array($status, ['active', 'inactive'])) {
    $where[] = "is_active = ?";
    $params[] = $status === 'active' ? 1 : 0;
    $types .= 'i';
}

$search = trim((string)($_GET['search'] ?? ''));
if ($search) {
    $where[] = "(name LIKE ? OR email LIKE ? OR phone LIKE ?)";
    $search_term = "%$search%";
    $params[] = $search_term;
    $params[] = $search_term;
    $params[] = $search_term;
    $types .= 'sss';
}

$where_clause = implode(' AND ', $where);

// Count total
$count_query = "SELECT COUNT(*) as cnt FROM customers WHERE $where_clause";
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

// Fetch customers
$query = "
    SELECT c.id, c.name, c.email, c.phone, c.address, c.is_active, c.created_at,
           COUNT(s.id) as total_sales, COALESCE(SUM(s.grand_total), 0) as total_spent
    FROM customers c
    LEFT JOIN sales s ON c.id = s.customer_id
    WHERE $where_clause
    GROUP BY c.id
    ORDER BY c.created_at DESC
    LIMIT ? OFFSET ?
";

$st = $db->prepare($query);
if (!$st) {
    $message = 'Database error';
    $message_type = 'danger';
    $customers = [];
} else {
    $bind_params = array_slice($params, 0);
    $bind_params[] = $per_page;
    $bind_params[] = $offset;
    
    $bind_types = $types . 'ii';
    
    if ($bind_types) {
        $st->bind_param($bind_types, ...$bind_params);
    }
    $st->execute();
    $customers = $st->get_result()->fetch_all(MYSQLI_ASSOC);
    $st->close();
}

// Fetch totals
$totals_query = "
    SELECT 
        COUNT(*) as total_customers,
        SUM(CASE WHEN is_active = 1 THEN 1 ELSE 0 END) as active_customers,
        SUM(CASE WHEN is_active = 0 THEN 1 ELSE 0 END) as inactive_customers
    FROM customers
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

function h2($text) {
    return htmlspecialchars($text ?? '', ENT_QUOTES, 'UTF-8');
}


function handle_add_customer(): array {
    global $db, $user_id;
    
    $name = trim((string)($_POST['name'] ?? ''));
    $email = trim((string)($_POST['email'] ?? ''));
    $phone = trim((string)($_POST['phone'] ?? ''));
    $address = trim((string)($_POST['address'] ?? ''));
    
    if (!$name || !$email) {
        return ['success' => false, 'message' => 'Name and email are required'];
    }
    
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return ['success' => false, 'message' => 'Invalid email address'];
    }
    
    try {
        $st = $db->prepare("INSERT INTO customers 
            (name, email, phone, address, created_at) 
            VALUES (?, ?, ?, ?, NOW())");
        
        if (!$st) throw new Exception('Prepare failed');
        $st->bind_param('ssss', $name, $email, $phone, $address);
        $st->execute();
        $customer_id = $st->insert_id;
        $st->close();
        
        if (function_exists('audit_log')) {
            audit_log('customers.add', 'customers', (string)$customer_id, "Added: $name");
        }
        
        return ['success' => true, 'message' => 'Customer added'];
    } catch (Exception $e) {
        return ['success' => false, 'message' => $e->getMessage()];
    }
}

function handle_update_customer(): array {
    global $db, $user_id;
    
    $id = (int)($_POST['customer_id'] ?? 0);
    $name = trim((string)($_POST['name'] ?? ''));
    $email = trim((string)($_POST['email'] ?? ''));
    $phone = trim((string)($_POST['phone'] ?? ''));
    $address = trim((string)($_POST['address'] ?? ''));
    $is_active = isset($_POST['is_active']) ? 1 : 0;
    
    if ($id <= 0 || !$name || !$email) {
        return ['success' => false, 'message' => 'Invalid parameters'];
    }
    
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return ['success' => false, 'message' => 'Invalid email address'];
    }
    
    try {
        $st = $db->prepare("UPDATE customers 
            SET name = ?, email = ?, phone = ?, address = ?, is_active = ?, updated_at = NOW()
            WHERE id = ? LIMIT 1");
        
        if (!$st) throw new Exception('Prepare failed');
        $st->bind_param('sssssi', $name, $email, $phone, $address, $is_active, $id);
        $st->execute();
        $st->close();
        
        if (function_exists('audit_log')) {
            audit_log('contacts.customer_update', 'customers', (string)$id, "Updated: $name");
        }
        
        return ['success' => true, 'message' => 'Customer updated successfully'];
    } catch (Exception $e) {
        return ['success' => false, 'message' => $e->getMessage()];
    }
}

function handle_delete_customer(): array {
    global $db, $user_id;
    
    $id = (int)($_POST['customer_id'] ?? 0);
    
    if ($id <= 0) {
        return ['success' => false, 'message' => 'Invalid customer'];
    }
    
    try {
        // Check if customer has sales
        $check = $db->prepare("SELECT COUNT(*) as cnt FROM sales WHERE customer_id = ? LIMIT 1");
        if (!$check) throw new Exception('Prepare failed');
        $check->bind_param('i', $id);
        $check->execute();
        $has_sales = (int)$check->get_result()->fetch_assoc()['cnt'];
        $check->close();
        
        if ($has_sales > 0) {
            throw new Exception('Cannot delete customer with existing sales records');
        }
        
        $st = $db->prepare("DELETE FROM customers WHERE id = ? LIMIT 1");
        if (!$st) throw new Exception('Prepare failed');
        $st->bind_param('i', $id);
        $st->execute();
        $st->close();
        
        if (function_exists('audit_log')) {
            audit_log('contacts.customer_delete', 'customers', (string)$id, 'Customer deleted');
        }
        
        return ['success' => true, 'message' => 'Customer deleted successfully'];
    } catch (Exception $e) {
        return ['success' => false, 'message' => $e->getMessage()];
    }
}

// Handle CSV export
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=customers_' . date('Y-m-d') . '.csv');
    
    $output = fopen('php://output', 'w');
    fputcsv($output, ['ID', 'Name', 'Email', 'Phone', 'Address', 'Active', 'Total Sales', 'Total Spent', 'Date Added']);
    
    $query = "
        SELECT c.id, c.name, c.email, c.phone, c.address, c.is_active, c.created_at,
               COUNT(s.id) as total_sales, COALESCE(SUM(s.grand_total), 0) as total_spent
        FROM customers c
        LEFT JOIN sales s ON c.id = s.customer_id
        WHERE $where_clause
        GROUP BY c.id
        ORDER BY c.created_at DESC
    ";
    
    $st = $db->prepare($query);
    if ($st && $types) {
        $bind_params = array_slice($params, 0);
        $st->bind_param($types, ...$bind_params);
    }
    
    if ($st) {
        $st->execute();
        $export_customers = $st->get_result()->fetch_all(MYSQLI_ASSOC);
        $st->close();
        
        foreach ($export_customers as $cust) {
            fputcsv($output, [
                $cust['id'],
                $cust['name'],
                $cust['email'],
                $cust['phone'],
                $cust['address'],
                $cust['is_active'] ? 'Active' : 'Inactive',
                $cust['total_sales'],
                $cust['total_spent'],
                date('M d, Y', strtotime($cust['created_at']))
            ]);
        }
    }
    
    fclose($output);
    exit;
}

$page_title = 'Customers';
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
            <h3 class="mb-2 fw-bold">Customers</h3>
            <div class="text-muted">Manage customer information and track sales history</div>
          </div>
          <div class="gap-2 d-flex">
            <a href="?export=csv" class="btn btn-outline-success">
              <i class="bi bi-download me-1"></i> Export CSV
            </a>
            <?php if (user_has_permission('contacts.create')): ?>
              <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addCustomerModal">
                <i class="bi bi-plus-circle me-1"></i> Add Customer
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
          <div class="col-lg-4 col-md-6">
            <div class="card shadow-sm h-100">
              <div class="card-body">
                <div class="d-flex align-items-center">
                  <div class="flex-shrink-0">
                    <div class="bg-primary bg-opacity-10 rounded-circle p-3">
                      <i class="bi bi-people text-primary" style="font-size: 1.2rem;"></i>
                    </div>
                  </div>
                  <div class="flex-grow-1 ms-3">
                    <div class="h5 mb-0 text-primary"><?= $totals['total_customers'] ?? 0 ?></div>
                    <div class="small text-muted">Total Customers</div>
                  </div>
                </div>
              </div>
            </div>
          </div>
          <div class="col-lg-4 col-md-6">
            <div class="card shadow-sm h-100">
              <div class="card-body">
                <div class="d-flex align-items-center">
                  <div class="flex-shrink-0">
                    <div class="bg-success bg-opacity-10 rounded-circle p-3">
                      <i class="bi bi-person-check text-success" style="font-size: 1.2rem;"></i>
                    </div>
                  </div>
                  <div class="flex-grow-1 ms-3">
                    <div class="h5 mb-0 text-success"><?= $totals['active_customers'] ?? 0 ?></div>
                    <div class="small text-muted">Active Customers</div>
                  </div>
                </div>
              </div>
            </div>
          </div>
          <div class="col-lg-4 col-md-6">
            <div class="card shadow-sm h-100">
              <div class="card-body">
                <div class="d-flex align-items-center">
                  <div class="flex-shrink-0">
                    <div class="bg-warning bg-opacity-10 rounded-circle p-3">
                      <i class="bi bi-person-x text-warning" style="font-size: 1.2rem;"></i>
                    </div>
                  </div>
                  <div class="flex-grow-1 ms-3">
                    <div class="h5 mb-0 text-warning"><?= $totals['inactive_customers'] ?? 0 ?></div>
                    <div class="small text-muted">Inactive Customers</div>
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
                <i class="bi bi-funnel"></i> Search & Filter Customers
              </h6>
            </div>
            <div class="card-body">
              <form method="GET" class="row g-3">
                <div class="col-lg-6 col-md-12">
                  <label for="search" class="form-label">Search Customers</label>
                  <input type="text" id="search" name="search" class="form-control" 
                         placeholder="Search by name, email, or phone..." 
                         value="<?php echo h2($search); ?>">
                </div>
                <div class="col-lg-3 col-md-6">
                  <label for="status" class="form-label">Status</label>
                  <select id="status" name="status" class="form-select">
                    <option value="">All Statuses</option>
                    <option value="active" <?php echo $status === 'active' ? 'selected' : ''; ?>>🟢 Active</option>
                    <option value="inactive" <?php echo $status === 'inactive' ? 'selected' : ''; ?>>🟡 Inactive</option>
                  </select>
                </div>
                <div class="col-lg-3 col-md-6">
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

            <!-- Customers Table -->
          <div class="card shadow-sm">
            <div class="card-header bg-light">
              <h6 class="mb-0">
                <i class="bi bi-people"></i> Customers
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
                      <th style="width: 150px;">Address</th>
                      <th style="width: 80px;">Status</th>
                      <th style="width: 80px;" class="text-end">Sales</th>
                      <th style="width: 100px;" class="text-end">Total Spent</th>
                      <th style="width: 120px;" class="text-center">Actions</th>
                    </tr>
                  </thead>
                    <tbody>
                        <?php if (empty($customers)): ?>
                            <tr>
                                <td colspan="8" class="text-center text-muted py-4">
                                    <i class="bi bi-inbox" style="font-size: 2rem;"></i>
                                    <div class="fw-semibold">No customers found</div>
                                    <div class="small">Try adjusting your search criteria</div>
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($customers as $cust): ?>
                            <tr>
                                <td>
                                    <strong><?php echo h2($cust['name']); ?></strong>
                                </td>
                                <td><a href="mailto:<?php echo h2($cust['email']); ?>"><?php echo h2($cust['email']); ?></a></td>
                                <td>
                                    <?php if ($cust['phone']): ?>
                                        <a href="tel:<?php echo h2($cust['phone']); ?>"><?php echo h2($cust['phone']); ?></a>
                                    <?php else: ?>
                                        <span class="text-muted">-</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($cust['address']): ?>
                                        <?php echo h2(substr($cust['address'], 0, 50)) . (strlen($cust['address']) > 50 ? '...' : ''); ?>
                                    <?php else: ?>
                                        <span class="text-muted">-</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <span class="badge bg-<?php echo $cust['is_active'] ? 'success' : 'warning'; ?>">
                                        <?= $cust['is_active'] ? 'Active' : 'Inactive' ?>
                                    </span>
                                </td>
                                <td class="text-end">
                                    <div class="fw-semibold"><?= $cust['total_sales'] ?? 0 ?></div>
                                </td>
                                <td class="text-end">
                                    <div class="fw-semibold text-success"><?= number_format((float)($cust['total_spent'] ?? 0), 2) ?></div>
                                </td>
                                <td class="text-center">
                                    <div class="btn-group btn-group-sm" role="group">
                                        <button class="btn btn-outline-primary" data-bs-toggle="modal" 
                                                data-bs-target="#editCustomerModal<?= $cust['id']; ?>" title="Edit">
                                            <i class="bi bi-pencil"></i>
                                        </button>
                                        <?php if (user_has_permission('contacts.delete')): ?>
                                            <button class="btn btn-outline-danger" data-bs-toggle="modal" 
                                                    data-bs-target="#deleteCustomerModal<?= $cust['id']; ?>" title="Delete">
                                                <i class="bi bi-trash"></i>
                                            </button>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
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
                            <a class="page-link" href="?page=1<?php echo $status ? '&status=' . urlencode($status) : ''; ?><?php echo $search ? '&search=' . urlencode($search) : ''; ?>">First</a>
                        </li>
                        <li class="page-item <?php echo $page <= 1 ? 'disabled' : ''; ?>">
                            <a class="page-link" href="?page=<?php echo $page - 1; ?><?php echo $status ? '&status=' . urlencode($status) : ''; ?><?php echo $search ? '&search=' . urlencode($search) : ''; ?>">Previous</a>
                        </li>
                        
                        <?php for ($i = max(1, $page - 2); $i <= min($total_pages, $page + 2); $i++): ?>
                            <li class="page-item <?php echo $i === $page ? 'active' : ''; ?>">
                                <a class="page-link" href="?page=<?php echo $i; ?><?php echo $status ? '&status=' . urlencode($status) : ''; ?><?php echo $search ? '&search=' . urlencode($search) : ''; ?>">
                                    <?php echo $i; ?>
                                </a>
                            </li>
                        <?php endfor; ?>
                        
                        <li class="page-item <?php echo $page >= $total_pages ? 'disabled' : ''; ?>">
                            <a class="page-link" href="?page=<?php echo $page + 1; ?><?php echo $status ? '&status=' . urlencode($status) : ''; ?><?php echo $search ? '&search=' . urlencode($search) : ''; ?>">Next</a>
                        </li>
                        <li class="page-item <?php echo $page >= $total_pages ? 'disabled' : ''; ?>">
                            <a class="page-link" href="?page=<?php echo $total_pages; ?><?php echo $status ? '&status=' . urlencode($status) : ''; ?><?php echo $search ? '&search=' . urlencode($search) : ''; ?>">Last</a>
                        </li>
                    </ul>
                </nav>
          <?php endif; ?>
      </div>
    </main>
  </div>
</div>

<!-- Add Customer Modal -->
<div class="modal fade" id="addCustomerModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title">Add New Customer</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <div class="modal-body">
                    <input type="hidden" name="action" value="add_customer">
                    
                    <div class="row">
                        <div class="col-md-6">
                            <label class="form-label" for="newName">Name *</label>
                            <input type="text" class="form-control" id="newName" name="name" placeholder="Customer name" required>
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
                            <label class="form-label" for="newAddress">Address</label>
                            <input type="text" class="form-control" id="newAddress" name="address" placeholder="Full address">
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Add Customer</button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php include __DIR__ . '/../../templates/layout/footer.php'; ?>
