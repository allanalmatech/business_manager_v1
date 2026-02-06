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

require_permission('admin.view');

$message = '';
$message_type = '';
$page = max(1, (int)($_GET['page'] ?? 1));
$per_page = 50;
$offset = ($page - 1) * $per_page;

// Handle actions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] === 'add_staff' && user_has_permission('admin.create')) {
        $result = handle_add_staff();
        $message = $result['message'];
        $message_type = $result['success'] ? 'success' : 'danger';
    } elseif ($_POST['action'] === 'update_staff' && user_has_permission('admin.update')) {
        $result = handle_update_staff();
        $message = $result['message'];
        $message_type = $result['success'] ? 'success' : 'danger';
    } elseif ($_POST['action'] === 'delete_staff' && user_has_permission('admin.delete')) {
        $result = handle_delete_staff();
        $message = $result['message'];
        $message_type = $result['success'] ? 'success' : 'danger';
    }
}

// Apply filters
$where = ["1 = 1"];
$params = [];
$types = '';

$status = trim((string)($_GET['status'] ?? ''));
if ($status && in_array($status, ['active', 'inactive', 'on_leave', 'terminated'])) {
    $where[] = "status = ?";
    $params[] = $status;
    $types .= 's';
}

$role = trim((string)($_GET['role'] ?? ''));
if ($role) {
    $where[] = "role = ?";
    $params[] = $role;
    $types .= 's';
}

$department = trim((string)($_GET['department'] ?? ''));
if ($department) {
    $where[] = "department = ?";
    $params[] = $department;
    $types .= 's';
}

$search = trim((string)($_GET['search'] ?? ''));
if ($search) {
    $where[] = "(first_name LIKE ? OR last_name LIKE ? OR email LIKE ? OR phone LIKE ? OR employee_id LIKE ?)";
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
$count_query = "SELECT COUNT(*) as cnt FROM staff WHERE $where_clause";
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

// Fetch staff
$query = "
    SELECT s.*,
           CONCAT(s.first_name, ' ', s.last_name) as full_name
    FROM staff s
    WHERE $where_clause
    ORDER BY s.created_at DESC
    LIMIT ? OFFSET ?
";

$st = $db->prepare($query);
if (!$st) {
    $message = 'Database error';
    $message_type = 'danger';
    $staff = [];
} else {
    $bind_params = array_slice($params, 0);
    $bind_params[] = $per_page;
    $bind_params[] = $offset;
    
    $bind_types = $types . 'ii';
    
    if ($bind_types) {
        $st->bind_param($bind_types, ...$bind_params);
    }
    $st->execute();
    $staff = $st->get_result()->fetch_all(MYSQLI_ASSOC);
    $st->close();
}

// Fetch totals
$totals_query = "
    SELECT 
        COUNT(*) as total_staff,
        SUM(CASE WHEN status = 'active' THEN 1 ELSE 0 END) as active_staff,
        SUM(CASE WHEN status = 'inactive' THEN 1 ELSE 0 END) as inactive_staff,
        SUM(CASE WHEN status = 'on_leave' THEN 1 ELSE 0 END) as on_leave_staff,
        COUNT(DISTINCT role) as total_roles,
        COUNT(DISTINCT department) as total_departments,
        AVG(CASE WHEN status = 'active' THEN 1 ELSE 0 END) as active_percentage
    FROM staff
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

// Get roles and departments for filters
$roles_query = "SELECT DISTINCT role FROM staff WHERE role IS NOT NULL ORDER BY role";
$roles_result = $db->query($roles_query);
$roles = [];
if ($roles_result) {
    $roles = $roles_result->fetch_all(MYSQLI_ASSOC);
}

$departments_query = "SELECT DISTINCT department FROM staff WHERE department IS NOT NULL ORDER BY department";
$departments_result = $db->query($departments_query);
$departments = [];
if ($departments_result) {
    $departments = $departments_result->fetch_all(MYSQLI_ASSOC);
}

function h2($text) {
    return htmlspecialchars($text ?? '', ENT_QUOTES, 'UTF-8');
}


function calculate_age($dob) {
    if (!$dob) return null;
    $dob = new DateTime($dob);
    $today = new DateTime();
    return $today->diff($dob)->y;
}

function handle_add_staff(): array {
    global $db, $user_id;
    
    $first_name = trim((string)($_POST['first_name'] ?? ''));
    $last_name = trim((string)($_POST['last_name'] ?? ''));
    $email = trim((string)($_POST['email'] ?? ''));
    $phone = trim((string)($_POST['phone'] ?? ''));
    $employee_id = trim((string)($_POST['employee_id'] ?? ''));
    $role = trim((string)($_POST['role'] ?? ''));
    $department = trim((string)($_POST['department'] ?? ''));
    $date_of_birth = trim((string)($_POST['date_of_birth'] ?? ''));
    $hire_date = trim((string)($_POST['hire_date'] ?? ''));
    $address = trim((string)($_POST['address'] ?? ''));
    $city = trim((string)($_POST['city'] ?? ''));
    $state = trim((string)($_POST['state'] ?? ''));
    $country = trim((string)($_POST['country'] ?? ''));
    
    if (!$first_name || !$last_name || !$email) {
        return ['success' => false, 'message' => 'First name, last name, and email are required'];
    }
    
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return ['success' => false, 'message' => 'Invalid email address'];
    }
    
    if ($employee_id) {
        $check = $db->prepare("SELECT id FROM staff WHERE employee_id = ? LIMIT 1");
        if ($check) {
            $check->bind_param('s', $employee_id);
            $check->execute();
            if ($check->get_result()->num_rows > 0) {
                $check->close();
                return ['success' => false, 'message' => 'Employee ID already exists'];
            }
            $check->close();
        }
    }
    
    try {
        $status = 'active';
        
        $st = $db->prepare("INSERT INTO staff 
            (first_name, last_name, email, phone, employee_id, role, department, 
             date_of_birth, hire_date, address, city, state, country, status, created_at) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())");
        
        if (!$st) throw new Exception('Prepare failed');
        $st->bind_param('sssssssssssss', $first_name, $last_name, $email, $phone, $employee_id, 
                        $role, $department, $date_of_birth, $hire_date, $address, $city, $state, $country, $status);
        $st->execute();
        $staff_id = $st->insert_id;
        $st->close();
        
        if (function_exists('audit_log')) {
            audit_log('admin.staff_add', 'staff', (string)$staff_id, "Added: $first_name $last_name ($email)");
        }
        
        return ['success' => true, 'message' => 'Staff member added successfully'];
    } catch (Exception $e) {
        return ['success' => false, 'message' => $e->getMessage()];
    }
}

function handle_update_staff(): array {
    global $db, $user_id;
    
    $id = (int)($_POST['staff_id'] ?? 0);
    $first_name = trim((string)($_POST['first_name'] ?? ''));
    $last_name = trim((string)($_POST['last_name'] ?? ''));
    $email = trim((string)($_POST['email'] ?? ''));
    $phone = trim((string)($_POST['phone'] ?? ''));
    $role = trim((string)($_POST['role'] ?? ''));
    $department = trim((string)($_POST['department'] ?? ''));
    $date_of_birth = trim((string)($_POST['date_of_birth'] ?? ''));
    $hire_date = trim((string)($_POST['hire_date'] ?? ''));
    $address = trim((string)($_POST['address'] ?? ''));
    $city = trim((string)($_POST['city'] ?? ''));
    $state = trim((string)($_POST['state'] ?? ''));
    $country = trim((string)($_POST['country'] ?? ''));
    $status = trim((string)($_POST['status'] ?? 'active'));
    
    if ($id <= 0 || !$first_name || !$last_name || !$email) {
        return ['success' => false, 'message' => 'Invalid parameters'];
    }
    
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return ['success' => false, 'message' => 'Invalid email address'];
    }
    
    if (!in_array($status, ['active', 'inactive', 'on_leave', 'terminated'])) {
        return ['success' => false, 'message' => 'Invalid status'];
    }
    
    try {
        $st = $db->prepare("UPDATE staff 
            SET first_name = ?, last_name = ?, email = ?, phone = ?, role = ?, department = ?,
                date_of_birth = ?, hire_date = ?, address = ?, city = ?, state = ?, country = ?, status = ?
            WHERE id = ? LIMIT 1");
        
        if (!$st) throw new Exception('Prepare failed');
        $st->bind_param('sssssssssssssi', $first_name, $last_name, $email, $phone, $role, $department,
                        $date_of_birth, $hire_date, $address, $city, $state, $country, $status, $id);
        $st->execute();
        $st->close();
        
        if (function_exists('audit_log')) {
            audit_log('admin.staff_update', 'staff', (string)$id, "Updated: $first_name $last_name");
        }
        
        return ['success' => true, 'message' => 'Staff member updated successfully'];
    } catch (Exception $e) {
        return ['success' => false, 'message' => $e->getMessage()];
    }
}

function handle_delete_staff(): array {
    global $db, $user_id;
    
    $id = (int)($_POST['staff_id'] ?? 0);
    
    if ($id <= 0) {
        return ['success' => false, 'message' => 'Invalid staff member'];
    }
    
    try {
        $st = $db->prepare("DELETE FROM staff WHERE id = ? LIMIT 1");
        if (!$st) throw new Exception('Prepare failed');
        $st->bind_param('i', $id);
        $st->execute();
        $st->close();
        
        if (function_exists('audit_log')) {
            audit_log('admin.staff_delete', 'staff', (string)$id, 'Staff member deleted');
        }
        
        return ['success' => true, 'message' => 'Staff member deleted successfully'];
    } catch (Exception $e) {
        return ['success' => false, 'message' => $e->getMessage()];
    }
}

// Handle CSV export
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=staff_' . date('Y-m-d') . '.csv');
    
    $output = fopen('php://output', 'w');
    fputcsv($output, ['ID', 'Employee ID', 'First Name', 'Last Name', 'Email', 'Phone', 'Role', 'Department', 
                      'Date of Birth', 'Age', 'Hire Date', 'Address', 'City', 'State', 'Country', 'Status', 'Date Added']);
    
    $query = "
        SELECT *
        FROM staff
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
        $export_staff = $st->get_result()->fetch_all(MYSQLI_ASSOC);
        $st->close();
        
        foreach ($export_staff as $member) {
            fputcsv($output, [
                $member['id'],
                $member['employee_id'],
                $member['first_name'],
                $member['last_name'],
                $member['email'],
                $member['phone'],
                $member['role'],
                $member['department'],
                $member['date_of_birth'],
                calculate_age($member['date_of_birth']),
                $member['hire_date'],
                $member['address'],
                $member['city'],
                $member['state'],
                $member['country'],
                $member['status'],
                date('M d, Y', strtotime($member['created_at']))
            ]);
        }
    }
    
    fclose($output);
    exit;
}

$page_title = 'Staff Management';
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
            <h3 class="mb-2 fw-bold">Staff Management</h3>
            <div class="text-muted">Manage employee information, roles, and department assignments</div>
          </div>
          <div class="gap-2 d-flex">
            <a href="?export=csv" class="btn btn-outline-success">
              <i class="bi bi-download me-1"></i> Export CSV
            </a>
            <?php if (user_has_permission('admin.create')): ?>
              <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addStaffModal">
                <i class="bi bi-plus-circle me-1"></i> Add Staff
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
                      <i class="bi bi-people text-primary" style="font-size: 1.2rem;"></i>
                    </div>
                  </div>
                  <div class="flex-grow-1 ms-3">
                    <div class="h5 mb-0 text-primary"><?= $totals['total_staff'] ?? 0 ?></div>
                    <div class="small text-muted">Total Staff</div>
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
                      <i class="bi bi-person-check text-success" style="font-size: 1.2rem;"></i>
                    </div>
                  </div>
                  <div class="flex-grow-1 ms-3">
                    <div class="h5 mb-0 text-success"><?= $totals['active_staff'] ?? 0 ?></div>
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
                    <div class="bg-warning bg-opacity-10 rounded-circle p-3">
                      <i class="bi bi-calendar-x text-warning" style="font-size: 1.2rem;"></i>
                    </div>
                  </div>
                  <div class="flex-grow-1 ms-3">
                    <div class="h5 mb-0 text-warning"><?= $totals['on_leave_staff'] ?? 0 ?></div>
                    <div class="small text-muted">On Leave</div>
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
                      <i class="bi bi-person-x text-danger" style="font-size: 1.2rem;"></i>
                    </div>
                  </div>
                  <div class="flex-grow-1 ms-3">
                    <div class="h5 mb-0 text-danger"><?= $totals['inactive_staff'] ?? 0 ?></div>
                    <div class="small text-muted">Inactive</div>
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
                      <i class="bi bi-building text-info" style="font-size: 1.2rem;"></i>
                    </div>
                  </div>
                  <div class="flex-grow-1 ms-3">
                    <div class="h5 mb-0 text-info"><?= $totals['total_departments'] ?? 0 ?></div>
                    <div class="small text-muted">Departments</div>
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
                      <i class="bi bi-shield-check text-secondary" style="font-size: 1.2rem;"></i>
                    </div>
                  </div>
                  <div class="flex-grow-1 ms-3">
                    <div class="h5 mb-0 text-secondary"><?= $totals['total_roles'] ?? 0 ?></div>
                    <div class="small text-muted">Roles</div>
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
                <i class="bi bi-funnel"></i> Search & Filter Staff
              </h6>
            </div>
            <div class="card-body">
              <form method="GET" class="row g-3">
                <div class="col-lg-4 col-md-12">
                  <label for="search" class="form-label">Search Staff</label>
                  <input type="text" id="search" name="search" class="form-control" 
                         placeholder="Search by name, email, phone, or employee ID..." 
                         value="<?php echo h2($search); ?>">
                </div>
                <div class="col-lg-3 col-md-6">
                  <label for="status" class="form-label">Status</label>
                  <select id="status" name="status" class="form-select">
                    <option value="">All Status</option>
                    <option value="active" <?php echo $status === 'active' ? 'selected' : ''; ?>>🟢 Active</option>
                    <option value="inactive" <?php echo $status === 'inactive' ? 'selected' : ''; ?>>🔴 Inactive</option>
                    <option value="on_leave" <?php echo $status === 'on_leave' ? 'selected' : ''; ?>>🟡 On Leave</option>
                    <option value="terminated" <?php echo $status === 'terminated' ? 'selected' : ''; ?>>⚫ Terminated</option>
                  </select>
                </div>
                <div class="col-lg-3 col-md-6">
                  <label for="department" class="form-label">Department</label>
                  <select id="department" name="department" class="form-select">
                    <option value="">All Departments</option>
                    <?php foreach ($departments as $dept): ?>
                      <option value="<?php echo h2($dept['department']); ?>" 
                              <?php echo $department === $dept['department'] ? 'selected' : ''; ?>>
                        <?php echo h2($dept['department']); ?>
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

            <!-- Staff Table -->
          <div class="card shadow-sm">
            <div class="card-header bg-light">
              <h6 class="mb-0">
                <i class="bi bi-people"></i> Staff Members
                <span class="badge bg-primary rounded-pill float-end"><?= $total ?></span>
              </h6>
            </div>
            <div class="card-body p-0">
              <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                  <thead class="table-light">
                    <tr>
                      <th style="width: 100px;">Employee ID</th>
                      <th style="min-width: 200px;">Name</th>
                      <th style="width: 200px;">Email</th>
                      <th style="width: 120px;">Phone</th>
                      <th style="width: 120px;">Role</th>
                      <th style="width: 120px;">Department</th>
                      <th style="width: 100px;">Hire Date</th>
                      <th style="width: 80px;">Status</th>
                      <th style="width: 120px;" class="text-center">Actions</th>
                    </tr>
                  </thead>
                    <tbody>
                        <?php if (empty($staff)): ?>
                            <tr>
                                <td colspan="9" class="text-center text-muted py-4">
                                    No staff members found
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($staff as $member): 
                                $age = calculate_age($member['date_of_birth']);
                            ?>
                                <tr>
                                    <td>
                                        <strong><?php echo h2($member['employee_id'] ?? '-'); ?></strong>
                                    </td>
                                    <td>
                                        <?php echo h2($member['first_name']); ?> <?php echo h2($member['last_name']); ?>
                                        <?php if ($age): ?>
                                            <br><small class="text-muted">(<?php echo $age; ?> years)</small>
                                        <?php endif; ?>
                                    </td>
                                    <td><a href="mailto:<?php echo h2($member['email']); ?>"><?php echo h2($member['email']); ?></a></td>
                                    <td>
                                        <?php if ($member['phone']): ?>
                                            <a href="tel:<?php echo h2($member['phone']); ?>"><?php echo h2($member['phone']); ?></a>
                                        <?php else: ?>
                                            <span class="text-muted">-</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($member['role']): ?>
                                            <span class="badge bg-info"><?php echo h2($member['role']); ?></span>
                                        <?php else: ?>
                                            <span class="text-muted">-</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($member['department']): ?>
                                            <span class="badge bg-secondary"><?php echo h2($member['department']); ?></span>
                                        <?php else: ?>
                                            <span class="text-muted">-</span>
                                        <?php endif; ?>
                                    </td>
                                    <td><?php echo $member['hire_date'] ? date('M d, Y', strtotime($member['hire_date'])) : '-'; ?></td>
                                    <td>
                                        <span class="badge bg-<?php 
                                            echo match($member['status']) {
                                                'active' => 'success',
                                                'inactive' => 'warning',
                                                'on_leave' => 'info',
                                                'terminated' => 'danger',
                                                default => 'secondary'
                                            };
                                        ?>">
                                            <?php echo ucfirst(str_replace('_', ' ', $member['status'])); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <div class="btn-group btn-group-sm" role="group">
                                            <?php if (user_has_permission('admin.update')): ?>
                                                <button class="btn btn-primary" data-bs-toggle="modal" 
                                                        data-bs-target="#editStaffModal<?php echo $member['id']; ?>" title="Edit">
                                                    <i class="bi bi-pencil"></i>
                                                </button>
                                            <?php endif; ?>
                                            <?php if (user_has_permission('admin.delete')): ?>
                                                <button class="btn btn-danger" data-bs-toggle="modal" 
                                                        data-bs-target="#deleteModal<?php echo $member['id']; ?>" title="Delete">
                                                    <i class="bi bi-trash"></i>
                                                </button>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>

                                <!-- Edit Modal -->
                                <div class="modal fade" id="editStaffModal<?php echo $member['id']; ?>" tabindex="-1">
                                    <div class="modal-dialog modal-lg">
                                        <div class="modal-content">
                                            <div class="modal-header bg-primary text-white">
                                                <h5 class="modal-title">Edit Staff Member</h5>
                                                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                                            </div>
                                            <form method="POST">
                                                <div class="modal-body">
                                                    <input type="hidden" name="action" value="update_staff">
                                                    <input type="hidden" name="staff_id" value="<?php echo $member['id']; ?>">
                                                    
                                                    <div class="row">
                                                        <div class="col-md-6">
                                                            <label class="form-label" for="fname<?php echo $member['id']; ?>">First Name *</label>
                                                            <input type="text" class="form-control" id="fname<?php echo $member['id']; ?>" 
                                                                   name="first_name" value="<?php echo h2($member['first_name']); ?>" required>
                                                        </div>
                                                        <div class="col-md-6">
                                                            <label class="form-label" for="lname<?php echo $member['id']; ?>">Last Name *</label>
                                                            <input type="text" class="form-control" id="lname<?php echo $member['id']; ?>" 
                                                                   name="last_name" value="<?php echo h2($member['last_name']); ?>" required>
                                                        </div>
                                                    </div>
                                                    
                                                    <div class="row mt-3">
                                                        <div class="col-md-6">
                                                            <label class="form-label" for="email<?php echo $member['id']; ?>">Email *</label>
                                                            <input type="email" class="form-control" id="email<?php echo $member['id']; ?>" 
                                                                   name="email" value="<?php echo h2($member['email']); ?>" required>
                                                        </div>
                                                        <div class="col-md-6">
                                                            <label class="form-label" for="phone<?php echo $member['id']; ?>">Phone</label>
                                                            <input type="tel" class="form-control" id="phone<?php echo $member['id']; ?>" 
                                                                   name="phone" value="<?php echo h2($member['phone']); ?>">
                                                        </div>
                                                    </div>
                                                    
                                                    <div class="row mt-3">
                                                        <div class="col-md-6">
                                                            <label class="form-label" for="role<?php echo $member['id']; ?>">Role</label>
                                                            <input type="text" class="form-control" id="role<?php echo $member['id']; ?>" 
                                                                   name="role" value="<?php echo h2($member['role']); ?>">
                                                        </div>
                                                        <div class="col-md-6">
                                                            <label class="form-label" for="dept<?php echo $member['id']; ?>">Department</label>
                                                            <input type="text" class="form-control" id="dept<?php echo $member['id']; ?>" 
                                                                   name="department" value="<?php echo h2($member['department']); ?>">
                                                        </div>
                                                    </div>
                                                    
                                                    <div class="row mt-3">
                                                        <div class="col-md-6">
                                                            <label class="form-label" for="dob<?php echo $member['id']; ?>">Date of Birth</label>
                                                            <input type="date" class="form-control" id="dob<?php echo $member['id']; ?>" 
                                                                   name="date_of_birth" value="<?php echo h2($member['date_of_birth']); ?>">
                                                        </div>
                                                        <div class="col-md-6">
                                                            <label class="form-label" for="hdate<?php echo $member['id']; ?>">Hire Date</label>
                                                            <input type="date" class="form-control" id="hdate<?php echo $member['id']; ?>" 
                                                                   name="hire_date" value="<?php echo h2($member['hire_date']); ?>">
                                                        </div>
                                                    </div>
                                                    
                                                    <div class="row mt-3">
                                                        <div class="col-md-12">
                                                            <label class="form-label" for="addr<?php echo $member['id']; ?>">Address</label>
                                                            <input type="text" class="form-control" id="addr<?php echo $member['id']; ?>" 
                                                                   name="address" value="<?php echo h2($member['address']); ?>">
                                                        </div>
                                                    </div>
                                                    
                                                    <div class="row mt-3">
                                                        <div class="col-md-4">
                                                            <label class="form-label" for="city<?php echo $member['id']; ?>">City</label>
                                                            <input type="text" class="form-control" id="city<?php echo $member['id']; ?>" 
                                                                   name="city" value="<?php echo h2($member['city']); ?>">
                                                        </div>
                                                        <div class="col-md-4">
                                                            <label class="form-label" for="state<?php echo $member['id']; ?>">State</label>
                                                            <input type="text" class="form-control" id="state<?php echo $member['id']; ?>" 
                                                                   name="state" value="<?php echo h2($member['state']); ?>">
                                                        </div>
                                                        <div class="col-md-4">
                                                            <label class="form-label" for="country<?php echo $member['id']; ?>">Country</label>
                                                            <input type="text" class="form-control" id="country<?php echo $member['id']; ?>" 
                                                                   name="country" value="<?php echo h2($member['country']); ?>">
                                                        </div>
                                                    </div>
                                                    
                                                    <div class="row mt-3">
                                                        <div class="col-md-12">
                                                            <label class="form-label" for="status<?php echo $member['id']; ?>">Status</label>
                                                            <select class="form-select" id="status<?php echo $member['id']; ?>" name="status">
                                                                <option value="active" <?php echo $member['status'] === 'active' ? 'selected' : ''; ?>>Active</option>
                                                                <option value="inactive" <?php echo $member['status'] === 'inactive' ? 'selected' : ''; ?>>Inactive</option>
                                                                <option value="on_leave" <?php echo $member['status'] === 'on_leave' ? 'selected' : ''; ?>>On Leave</option>
                                                                <option value="terminated" <?php echo $member['status'] === 'terminated' ? 'selected' : ''; ?>>Terminated</option>
                                                            </select>
                                                        </div>
                                                    </div>
                                                </div>
                                                <div class="modal-footer">
                                                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                                                    <button type="submit" class="btn btn-primary">Update Staff Member</button>
                                                </div>
                                            </form>
                                        </div>
                                    </div>
                                </div>

                                <!-- Delete Modal -->
                                <div class="modal fade" id="deleteModal<?php echo $member['id']; ?>" tabindex="-1">
                                    <div class="modal-dialog">
                                        <div class="modal-content">
                                            <div class="modal-header bg-danger text-white">
                                                <h5 class="modal-title">Confirm Delete</h5>
                                                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                                            </div>
                                            <form method="POST">
                                                <div class="modal-body">
                                                    <p>Are you sure you want to delete this staff member?</p>
                                                    <p class="text-muted"><strong><?php echo h2($member['first_name'] . ' ' . $member['last_name']); ?></strong></p>
                                                </div>
                                                <div class="modal-footer">
                                                    <input type="hidden" name="action" value="delete_staff">
                                                    <input type="hidden" name="staff_id" value="<?php echo $member['id']; ?>">
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
                            <a class="page-link" href="?page=1<?php echo $status ? '&status=' . urlencode($status) : ''; ?><?php echo $department ? '&department=' . urlencode($department) : ''; ?><?php echo $search ? '&search=' . urlencode($search) : ''; ?>">First</a>
                        </li>
                        <li class="page-item <?php echo $page <= 1 ? 'disabled' : ''; ?>">
                            <a class="page-link" href="?page=<?php echo $page - 1; ?><?php echo $status ? '&status=' . urlencode($status) : ''; ?><?php echo $department ? '&department=' . urlencode($department) : ''; ?><?php echo $search ? '&search=' . urlencode($search) : ''; ?>">Previous</a>
                        </li>
                        
                        <?php for ($i = max(1, $page - 2); $i <= min($total_pages, $page + 2); $i++): ?>
                            <li class="page-item <?php echo $i === $page ? 'active' : ''; ?>">
                                <a class="page-link" href="?page=<?php echo $i; ?><?php echo $status ? '&status=' . urlencode($status) : ''; ?><?php echo $department ? '&department=' . urlencode($department) : ''; ?><?php echo $search ? '&search=' . urlencode($search) : ''; ?>">
                                    <?php echo $i; ?>
                                </a>
                            </li>
                        <?php endfor; ?>
                        
                        <li class="page-item <?php echo $page >= $total_pages ? 'disabled' : ''; ?>">
                            <a class="page-link" href="?page=<?php echo $page + 1; ?><?php echo $status ? '&status=' . urlencode($status) : ''; ?><?php echo $department ? '&department=' . urlencode($department) : ''; ?><?php echo $search ? '&search=' . urlencode($search) : ''; ?>">Next</a>
                        </li>
                        <li class="page-item <?php echo $page >= $total_pages ? 'disabled' : ''; ?>">
                            <a class="page-link" href="?page=<?php echo $total_pages; ?><?php echo $status ? '&status=' . urlencode($status) : ''; ?><?php echo $department ? '&department=' . urlencode($department) : ''; ?><?php echo $search ? '&search=' . urlencode($search) : ''; ?>">Last</a>
                        </li>
                    </ul>
                </nav>
          <?php endif; ?>
      </div>
    </main>
  </div>
</div>

<!-- Add Staff Modal -->
<div class="modal fade" id="addStaffModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title">Add New Staff Member</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <div class="modal-body">
                    <input type="hidden" name="action" value="add_staff">
                    
                    <div class="row">
                        <div class="col-md-6">
                            <label class="form-label" for="newFirstName">First Name *</label>
                            <input type="text" class="form-control" id="newFirstName" name="first_name" placeholder="First name" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label" for="newLastName">Last Name *</label>
                            <input type="text" class="form-control" id="newLastName" name="last_name" placeholder="Last name" required>
                        </div>
                    </div>
                    
                    <div class="row mt-3">
                        <div class="col-md-6">
                            <label class="form-label" for="newEmail">Email *</label>
                            <input type="email" class="form-control" id="newEmail" name="email" placeholder="email@example.com" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label" for="newPhone">Phone</label>
                            <input type="tel" class="form-control" id="newPhone" name="phone" placeholder="+1 (555) 123-4567">
                        </div>
                    </div>
                    
                    <div class="row mt-3">
                        <div class="col-md-6">
                            <label class="form-label" for="newEmpID">Employee ID</label>
                            <input type="text" class="form-control" id="newEmpID" name="employee_id" placeholder="EMP001">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label" for="newRole">Role</label>
                            <input type="text" class="form-control" id="newRole" name="role" placeholder="e.g., Manager, Supervisor">
                        </div>
                    </div>
                    
                    <div class="row mt-3">
                        <div class="col-md-6">
                            <label class="form-label" for="newDept">Department</label>
                            <input type="text" class="form-control" id="newDept" name="department" placeholder="e.g., Sales, Operations">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label" for="newDOB">Date of Birth</label>
                            <input type="date" class="form-control" id="newDOB" name="date_of_birth">
                        </div>
                    </div>
                    
                    <div class="row mt-3">
                        <div class="col-md-12">
                            <label class="form-label" for="newHireDate">Hire Date</label>
                            <input type="date" class="form-control" id="newHireDate" name="hire_date">
                        </div>
                    </div>
                    
                    <div class="row mt-3">
                        <div class="col-md-12">
                            <label class="form-label" for="newAddress">Address</label>
                            <input type="text" class="form-control" id="newAddress" name="address" placeholder="Street address">
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
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Add Staff Member</button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php include __DIR__ . '/../../templates/layout/footer.php'; ?>
