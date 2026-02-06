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
    // No contact modification actions available for UNION-based contacts
    // Contacts are managed through their respective modules (staff, customers, suppliers)
}

// Apply filters
$where = [];
$params = [];
$types = '';

$type = trim((string)($_GET['type'] ?? ''));
if ($type && in_array($type, ['staff', 'customer', 'supplier'])) {
    $where[] = "type = ?";
    $params[] = $type;
    $types .= 's';
}

$search = trim((string)($_GET['search'] ?? ''));
if ($search) {
    $where[] = "(first_name LIKE ? OR last_name LIKE ? OR email LIKE ? OR phone LIKE ?)";
    $search_term = "%$search%";
    $params[] = $search_term;
    $params[] = $search_term;
    $params[] = $search_term;
    $params[] = $search_term;
    $types .= 'ssss';
}

$where_clause = !empty($where) ? implode(' AND ', $where) : '1=1';

// Count total
$count_query = "
    SELECT COUNT(*) as cnt FROM (
        SELECT id, first_name, last_name, email, phone, address as company, 'staff' as type, created_at FROM staff WHERE is_active = 1
        UNION ALL
        SELECT id, name as first_name, '' as last_name, email, phone, address as company, 'customer' as type, created_at FROM customers WHERE is_active = 1
        UNION ALL
        SELECT id, name as first_name, '' as last_name, email, phone, company_name as company, 'supplier' as type, created_at FROM suppliers WHERE status = 'active'
    ) as combined_contacts
    WHERE $where_clause
";
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

// Fetch contacts
$query = "
    SELECT * FROM (
        SELECT id, first_name, last_name, email, phone, address as company, 'staff' as type, created_at FROM staff WHERE is_active = 1
        UNION ALL
        SELECT id, name as first_name, '' as last_name, email, phone, address as company, 'customer' as type, created_at FROM customers WHERE is_active = 1
        UNION ALL
        SELECT id, name as first_name, '' as last_name, email, phone, company_name as company, 'supplier' as type, created_at FROM suppliers WHERE status = 'active'
    ) as combined_contacts
    WHERE $where_clause
    ORDER BY created_at DESC
    LIMIT ? OFFSET ?
";

$st = $db->prepare($query);
if (!$st) {
    $message = 'Database error';
    $message_type = 'danger';
    $contacts = [];
} else {
    $bind_params = array_slice($params, 0);
    $bind_params[] = $per_page;
    $bind_params[] = $offset;
    
    $bind_types = $types . 'ii';
    
    if ($bind_types) {
        $st->bind_param($bind_types, ...$bind_params);
    }
    $st->execute();
    $contacts = $st->get_result()->fetch_all(MYSQLI_ASSOC);
    $st->close();
}

// Fetch totals
$totals_query = "
    SELECT 
        COUNT(*) as total_contacts,
        SUM(CASE WHEN type = 'staff' THEN 1 ELSE 0 END) as staff_contacts,
        SUM(CASE WHEN type = 'customer' THEN 1 ELSE 0 END) as customer_contacts,
        SUM(CASE WHEN type = 'supplier' THEN 1 ELSE 0 END) as supplier_contacts
    FROM (
        SELECT id, 'staff' as type FROM staff WHERE is_active = 1
        UNION ALL
        SELECT id, 'customer' as type FROM customers WHERE is_active = 1
        UNION ALL
        SELECT id, 'supplier' as type FROM suppliers WHERE status = 'active'
    ) as combined_contacts
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



// Handle CSV export
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=contacts_' . date('Y-m-d') . '.csv');
    
    $output = fopen('php://output', 'w');
    fputcsv($output, ['ID', 'First Name', 'Last Name', 'Email', 'Phone', 'Company', 'Type', 'Date Added']);
    
    $query = "
        SELECT * FROM (
            SELECT id, first_name, last_name, email, phone, address as company, 'staff' as type, created_at FROM staff WHERE is_active = 1
            UNION ALL
            SELECT id, name as first_name, '' as last_name, email, phone, address as company, 'customer' as type, created_at FROM customers WHERE is_active = 1
            UNION ALL
            SELECT id, name as first_name, '' as last_name, email, phone, company_name as company, 'supplier' as type, created_at FROM suppliers WHERE status = 'active'
        ) as combined_contacts
        WHERE $where_clause
        ORDER BY created_at DESC
    ";
    
    $st = $db->prepare($query);
    if ($st && $types) {
        $bind_params = array_slice($params, 0);
        $st->bind_param($types, ...$bind_params);
    }
    
    if ($st) {
        $st->execute();
        $export_contacts = $st->get_result()->fetch_all(MYSQLI_ASSOC);
        $st->close();
        
        foreach ($export_contacts as $cont) {
            fputcsv($output, [
                $cont['id'],
                $cont['first_name'],
                $cont['last_name'],
                $cont['email'],
                $cont['phone'],
                $cont['company'],
                $cont['type'],
                date('M d, Y', strtotime($cont['created_at']))
            ]);
        }
    }
    
    fclose($output);
    exit;
}

$page_title = 'Contacts';
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
            <h3 class="mb-2 fw-bold">Contacts</h3>
            <div class="text-muted">Unified view of staff, customers, and suppliers</div>
          </div>
          <div class="gap-2 d-flex">
            <a href="export.php" class="btn btn-outline-success">
              <i class="bi bi-download me-1"></i> Export Contacts
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
                    <div class="bg-primary bg-opacity-10 rounded-circle p-3">
                      <i class="bi bi-people text-primary" style="font-size: 1.2rem;"></i>
                    </div>
                  </div>
                  <div class="flex-grow-1 ms-3">
                    <div class="h5 mb-0 text-primary"><?= $totals['total_contacts'] ?? 0 ?></div>
                    <div class="small text-muted">Total Contacts</div>
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
                      <i class="bi bi-person-badge text-primary" style="font-size: 1.2rem;"></i>
                    </div>
                  </div>
                  <div class="flex-grow-1 ms-3">
                    <div class="h5 mb-0 text-primary"><?= $totals['staff_contacts'] ?? 0 ?></div>
                    <div class="small text-muted">Staff</div>
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
                      <i class="bi bi-person-check text-info" style="font-size: 1.2rem;"></i>
                    </div>
                  </div>
                  <div class="flex-grow-1 ms-3">
                    <div class="h5 mb-0 text-info"><?= $totals['customer_contacts'] ?? 0 ?></div>
                    <div class="small text-muted">Customers</div>
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
                      <i class="bi bi-truck text-warning" style="font-size: 1.2rem;"></i>
                    </div>
                  </div>
                  <div class="flex-grow-1 ms-3">
                    <div class="h5 mb-0 text-warning"><?= $totals['supplier_contacts'] ?? 0 ?></div>
                    <div class="small text-muted">Suppliers</div>
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
              <i class="bi bi-funnel"></i> Search & Filter Contacts
            </h6>
          </div>
          <div class="card-body">
            <form method="GET" class="row g-3">
              <div class="col-lg-6 col-md-12">
                <label for="search" class="form-label">Search Contacts</label>
                <input type="text" id="search" name="search" class="form-control" 
                       placeholder="Search by name, email, phone..." 
                       value="<?php echo h2($search); ?>">
              </div>
              <div class="col-lg-4 col-md-6">
                <label for="type" class="form-label">Contact Type</label>
                <select id="type" name="type" class="form-select">
                  <option value="">All Types</option>
                  <option value="staff" <?php echo $type === 'staff' ? 'selected' : ''; ?>>Staff</option>
                  <option value="customer" <?php echo $type === 'customer' ? 'selected' : ''; ?>>Customer</option>
                  <option value="supplier" <?php echo $type === 'supplier' ? 'selected' : ''; ?>>Supplier</option>
                </select>
              </div>
              <div class="col-lg-2 col-md-6">
                <label class="form-label">&nbsp;</label>
                <div>
                  <button type="submit" class="btn btn-primary w-100">
                    <i class="bi bi-filter"></i> Filter
                  </button>
                </div>
              </div>
            </form>
          </div>
        </div>

            <!-- Contacts Table -->
            <div class="card shadow-sm">
              <div class="card-header bg-light">
                <h6 class="mb-0">
                  <i class="bi bi-people"></i> Contacts
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
                        <th style="width: 150px;">Company</th>
                        <th style="width: 100px;">Type</th>
                        <th style="width: 120px;" class="text-center">Actions</th>
                      </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($contacts)): ?>
                            <tr>
                                <td colspan="6" class="text-center text-muted py-4">
                                    <i class="bi bi-inbox" style="font-size: 2rem;"></i>
                                    <div class="fw-semibold">No contacts found</div>
                                    <div class="small">Try adjusting your search criteria</div>
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($contacts as $contact): ?>
                                <tr>
                                    <td>
                                        <strong><?php echo h2($contact['first_name']); ?> <?php echo h2($contact['last_name']); ?></strong>
                                    </td>
                                    <td><a href="mailto:<?php echo h2($contact['email']); ?>"><?php echo h2($contact['email']); ?></a></td>
                                    <td>
                                        <?php if ($contact['phone']): ?>
                                            <a href="tel:<?php echo h2($contact['phone']); ?>"><?php echo h2($contact['phone']); ?></a>
                                        <?php else: ?>
                                            <span class="text-muted">-</span>
                                        <?php endif; ?>
                                    </td>
                                    <td><?php echo h2($contact['company'] ?? '-'); ?></td>
                                    <td>
                                        <span class="badge bg-<?php 
                                            echo match($contact['type']) {
                                                'staff' => 'primary',
                                                'customer' => 'info',
                                                'supplier' => 'warning',
                                                default => 'secondary'
                                            };
                                        ?>">
                                            <?php echo ucfirst($contact['type']); ?>
                                        </span>
                                    </td>
                                    <td class="text-center">
                                        <div class="btn-group btn-group-sm" role="group">
                                            <button class="btn btn-outline-primary" title="View Details">
                                                <i class="bi bi-eye"></i>
                                            </button>
                                        </div>
                                    </td>
                                </tr>
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
                            <a class="page-link" href="?page=1<?php echo $type ? '&type=' . urlencode($type) : ''; ?><?php echo $search ? '&search=' . urlencode($search) : ''; ?>">First</a>
                        </li>
                        <li class="page-item <?php echo $page <= 1 ? 'disabled' : ''; ?>">
                            <a class="page-link" href="?page=<?php echo $page - 1; ?><?php echo $type ? '&type=' . urlencode($type) : ''; ?><?php echo $search ? '&search=' . urlencode($search) : ''; ?>">Previous</a>
                        </li>
                        
                        <?php for ($i = max(1, $page - 2); $i <= min($total_pages, $page + 2); $i++): ?>
                            <li class="page-item <?php echo $i === $page ? 'active' : ''; ?>">
                                <a class="page-link" href="?page=<?php echo $i; ?><?php echo $type ? '&type=' . urlencode($type) : ''; ?><?php echo $search ? '&search=' . urlencode($search) : ''; ?>">
                                    <?php echo $i; ?>
                                </a>
                            </li>
                        <?php endfor; ?>
                        
                        <li class="page-item <?php echo $page >= $total_pages ? 'disabled' : ''; ?>">
                            <a class="page-link" href="?page=<?php echo $page + 1; ?><?php echo $type ? '&type=' . urlencode($type) : ''; ?><?php echo $search ? '&search=' . urlencode($search) : ''; ?>">Next</a>
                        </li>
                        <li class="page-item <?php echo $page >= $total_pages ? 'disabled' : ''; ?>">
                            <a class="page-link" href="?page=<?php echo $total_pages; ?><?php echo $type ? '&type=' . urlencode($type) : ''; ?><?php echo $search ? '&search=' . urlencode($search) : ''; ?>">Last</a>
                        </li>
                    </ul>
                </nav>
          <?php endif; ?>
      </div>
    </main>
  </div>
</div>

<?php include __DIR__ . '/../../templates/layout/footer.php'; ?>
