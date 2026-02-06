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
$tab = trim((string)($_GET['tab'] ?? 'categories'));

// Validate tab
if (!in_array($tab, ['categories', 'tags'])) {
    $tab = 'categories';
}

// ===================== CATEGORIES =====================
$page = max(1, (int)($_GET['page'] ?? 1));
$per_page = 50;
$offset = ($page - 1) * $per_page;

// Handle category actions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if ($tab === 'categories') {
        if ($_POST['action'] === 'add_category' && user_has_permission('contacts.create')) {
            $result = handle_add_category();
            $message = $result['message'];
            $message_type = $result['success'] ? 'success' : 'danger';
        } elseif ($_POST['action'] === 'update_category' && user_has_permission('contacts.update')) {
            $result = handle_update_category();
            $message = $result['message'];
            $message_type = $result['success'] ? 'success' : 'danger';
        } elseif ($_POST['action'] === 'delete_category' && user_has_permission('contacts.delete')) {
            $result = handle_delete_category();
            $message = $result['message'];
            $message_type = $result['success'] ? 'success' : 'danger';
        }
    } elseif ($tab === 'tags') {
        if ($_POST['action'] === 'add_tag' && user_has_permission('contacts.create')) {
            $result = handle_add_tag();
            $message = $result['message'];
            $message_type = $result['success'] ? 'success' : 'danger';
        } elseif ($_POST['action'] === 'update_tag' && user_has_permission('contacts.update')) {
            $result = handle_update_tag();
            $message = $result['message'];
            $message_type = $result['success'] ? 'success' : 'danger';
        } elseif ($_POST['action'] === 'delete_tag' && user_has_permission('contacts.delete')) {
            $result = handle_delete_tag();
            $message = $result['message'];
            $message_type = $result['success'] ? 'success' : 'danger';
        }
    }
}

// Apply category filters
$where = ["1 = 1"];
$params = [];
$types = '';

$search = trim((string)($_GET['search'] ?? ''));
if ($search && $tab === 'categories') {
    $where[] = "(name LIKE ? OR description LIKE ?)";
    $search_term = "%$search%";
    $params[] = $search_term;
    $params[] = $search_term;
    $types .= 'ss';
}

// Apply tag filters
if ($search && $tab === 'tags') {
    $where[] = "(name LIKE ?)";
    $search_term = "%$search%";
    $params[] = $search_term;
    $types .= 's';
}

$where_clause = implode(' AND ', $where);

// Count categories or tags
if ($tab === 'categories') {
    $count_query = "SELECT COUNT(*) as cnt FROM contact_categories WHERE $where_clause";
} else {
    $count_query = "SELECT COUNT(*) as cnt FROM contact_tags WHERE $where_clause";
}

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

// Fetch categories or tags
if ($tab === 'categories') {
    $query = "
        SELECT c.*,
               COUNT(DISTINCT CASE WHEN cc.contact_id IS NOT NULL THEN cc.contact_id END) as contact_count
        FROM contact_categories c
        LEFT JOIN contact_category_map cc ON c.id = cc.category_id
        WHERE $where_clause
        GROUP BY c.id
        ORDER BY c.created_at DESC
        LIMIT ? OFFSET ?
    ";
} else {
    $query = "
        SELECT t.*,
               COUNT(DISTINCT CASE WHEN ct.contact_id IS NOT NULL THEN ct.contact_id END) as contact_count
        FROM contact_tags t
        LEFT JOIN contact_tag_map ct ON t.id = ct.tag_id
        WHERE $where_clause
        GROUP BY t.id
        ORDER BY t.created_at DESC
        LIMIT ? OFFSET ?
    ";
}

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
if ($tab === 'categories') {
    $totals_query = "
        SELECT 
            COUNT(*) as total,
            COUNT(DISTINCT CASE WHEN is_active = 1 THEN id END) as active,
            SUM(CASE WHEN is_active = 1 THEN 1 ELSE 0 END) as active_count
        FROM contact_categories
        WHERE $where_clause
    ";
} else {
    $totals_query = "
        SELECT 
            COUNT(*) as total,
            COUNT(DISTINCT CASE WHEN is_active = 1 THEN id END) as active,
            SUM(CASE WHEN is_active = 1 THEN 1 ELSE 0 END) as active_count
        FROM contact_tags
        WHERE $where_clause
    ";
}

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


function handle_add_category(): array {
    global $db, $user_id;
    
    $name = trim((string)($_POST['name'] ?? ''));
    $description = trim((string)($_POST['description'] ?? ''));
    $color = trim((string)($_POST['color'] ?? '#007bff'));
    
    if (!$name) {
        return ['success' => false, 'message' => 'Category name is required'];
    }
    
    try {
        $is_active = 1;
        
        $st = $db->prepare("INSERT INTO contact_categories 
            (name, description, color, is_active, created_at) 
            VALUES (?, ?, ?, ?, NOW())");
        
        if (!$st) throw new Exception('Prepare failed');
        $st->bind_param('sssi', $name, $description, $color, $is_active);
        $st->execute();
        $category_id = $st->insert_id;
        $st->close();
        
        if (function_exists('audit_log')) {
            audit_log('contacts.category_add', 'contact_categories', (string)$category_id, "Added: $name");
        }
        
        return ['success' => true, 'message' => 'Category added successfully'];
    } catch (Exception $e) {
        return ['success' => false, 'message' => $e->getMessage()];
    }
}

function handle_update_category(): array {
    global $db, $user_id;
    
    $id = (int)($_POST['category_id'] ?? 0);
    $name = trim((string)($_POST['name'] ?? ''));
    $description = trim((string)($_POST['description'] ?? ''));
    $color = trim((string)($_POST['color'] ?? '#007bff'));
    $is_active = isset($_POST['is_active']) ? 1 : 0;
    
    if ($id <= 0 || !$name) {
        return ['success' => false, 'message' => 'Invalid parameters'];
    }
    
    try {
        $st = $db->prepare("UPDATE contact_categories 
            SET name = ?, description = ?, color = ?, is_active = ?
            WHERE id = ? LIMIT 1");
        
        if (!$st) throw new Exception('Prepare failed');
        $st->bind_param('sssii', $name, $description, $color, $is_active, $id);
        $st->execute();
        $st->close();
        
        if (function_exists('audit_log')) {
            audit_log('contacts.category_update', 'contact_categories', (string)$id, "Updated: $name");
        }
        
        return ['success' => true, 'message' => 'Category updated successfully'];
    } catch (Exception $e) {
        return ['success' => false, 'message' => $e->getMessage()];
    }
}

function handle_delete_category(): array {
    global $db, $user_id;
    
    $id = (int)($_POST['category_id'] ?? 0);
    
    if ($id <= 0) {
        return ['success' => false, 'message' => 'Invalid category'];
    }
    
    try {
        $st = $db->prepare("DELETE FROM contact_categories WHERE id = ? LIMIT 1");
        if (!$st) throw new Exception('Prepare failed');
        $st->bind_param('i', $id);
        $st->execute();
        $st->close();
        
        if (function_exists('audit_log')) {
            audit_log('contacts.category_delete', 'contact_categories', (string)$id, 'Category deleted');
        }
        
        return ['success' => true, 'message' => 'Category deleted successfully'];
    } catch (Exception $e) {
        return ['success' => false, 'message' => $e->getMessage()];
    }
}

function handle_add_tag(): array {
    global $db, $user_id;
    
    $name = trim((string)($_POST['name'] ?? ''));
    $color = trim((string)($_POST['color'] ?? '#28a745'));
    
    if (!$name) {
        return ['success' => false, 'message' => 'Tag name is required'];
    }
    
    try {
        $is_active = 1;
        
        $st = $db->prepare("INSERT INTO contact_tags 
            (name, color, is_active, created_at) 
            VALUES (?, ?, ?, NOW())");
        
        if (!$st) throw new Exception('Prepare failed');
        $st->bind_param('ssi', $name, $color, $is_active);
        $st->execute();
        $tag_id = $st->insert_id;
        $st->close();
        
        if (function_exists('audit_log')) {
            audit_log('contacts.tag_add', 'contact_tags', (string)$tag_id, "Added: $name");
        }
        
        return ['success' => true, 'message' => 'Tag added successfully'];
    } catch (Exception $e) {
        return ['success' => false, 'message' => $e->getMessage()];
    }
}

function handle_update_tag(): array {
    global $db, $user_id;
    
    $id = (int)($_POST['tag_id'] ?? 0);
    $name = trim((string)($_POST['name'] ?? ''));
    $color = trim((string)($_POST['color'] ?? '#28a745'));
    $is_active = isset($_POST['is_active']) ? 1 : 0;
    
    if ($id <= 0 || !$name) {
        return ['success' => false, 'message' => 'Invalid parameters'];
    }
    
    try {
        $st = $db->prepare("UPDATE contact_tags 
            SET name = ?, color = ?, is_active = ?
            WHERE id = ? LIMIT 1");
        
        if (!$st) throw new Exception('Prepare failed');
        $st->bind_param('ssii', $name, $color, $is_active, $id);
        $st->execute();
        $st->close();
        
        if (function_exists('audit_log')) {
            audit_log('contacts.tag_update', 'contact_tags', (string)$id, "Updated: $name");
        }
        
        return ['success' => true, 'message' => 'Tag updated successfully'];
    } catch (Exception $e) {
        return ['success' => false, 'message' => $e->getMessage()];
    }
}

function handle_delete_tag(): array {
    global $db, $user_id;
    
    $id = (int)($_POST['tag_id'] ?? 0);
    
    if ($id <= 0) {
        return ['success' => false, 'message' => 'Invalid tag'];
    }
    
    try {
        $st = $db->prepare("DELETE FROM contact_tags WHERE id = ? LIMIT 1");
        if (!$st) throw new Exception('Prepare failed');
        $st->bind_param('i', $id);
        $st->execute();
        $st->close();
        
        if (function_exists('audit_log')) {
            audit_log('contacts.tag_delete', 'contact_tags', (string)$id, 'Tag deleted');
        }
        
        return ['success' => true, 'message' => 'Tag deleted successfully'];
    } catch (Exception $e) {
        return ['success' => false, 'message' => $e->getMessage()];
    }
}

$page_title = 'Categories & Tags';
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
            <h3 class="mb-2 fw-bold">Categories & Tags</h3>
            <div class="text-muted">Organize your contacts with custom categories and tags</div>
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

        <!-- Tab Navigation -->
        <ul class="nav nav-tabs mb-4" role="tablist">
          <li class="nav-item" role="presentation">
            <a class="nav-link <?php echo $tab === 'categories' ? 'active' : ''; ?>" 
               href="?tab=categories" role="tab">
              <i class="bi bi-folder"></i> Categories
            </a>
          </li>
          <li class="nav-item" role="presentation">
            <a class="nav-link <?php echo $tab === 'tags' ? 'active' : ''; ?>" 
               href="?tab=tags" role="tab">
              <i class="bi bi-tags"></i> Tags
            </a>
          </li>
        </ul>

            <!-- Summary Cards -->
        <div class="row g-3 mb-4">
          <div class="col-lg-6 col-md-12">
            <div class="card shadow-sm h-100">
              <div class="card-body">
                <div class="d-flex align-items-center">
                  <div class="flex-shrink-0">
                    <div class="bg-primary bg-opacity-10 rounded-circle p-3">
                      <i class="bi bi-<?php echo $tab === 'categories' ? 'folder' : 'tags'; ?> text-primary" style="font-size: 1.2rem;"></i>
                    </div>
                  </div>
                  <div class="flex-grow-1 ms-3">
                    <div class="h5 mb-0 text-primary"><?= $totals['total'] ?? 0 ?></div>
                    <div class="small text-muted">Total <?php echo $tab === 'categories' ? 'Categories' : 'Tags'; ?></div>
                  </div>
                </div>
              </div>
            </div>
          </div>
          <div class="col-lg-6 col-md-12">
            <div class="card shadow-sm h-100">
              <div class="card-body">
                <div class="d-flex align-items-center">
                  <div class="flex-shrink-0">
                    <div class="bg-success bg-opacity-10 rounded-circle p-3">
                      <i class="bi bi-check-circle text-success" style="font-size: 1.2rem;"></i>
                    </div>
                  </div>
                  <div class="flex-grow-1 ms-3">
                    <div class="h5 mb-0 text-success"><?= $totals['active_count'] ?? 0 ?></div>
                    <div class="small text-muted">Active</div>
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
                <i class="bi bi-funnel"></i> Search & Filter <?php echo ucfirst($tab); ?>
              </h6>
            </div>
            <div class="card-body">
              <form method="GET" class="row g-3">
                <input type="hidden" name="tab" value="<?php echo $tab; ?>">
                <div class="col-lg-9 col-md-12">
                  <label for="search" class="form-label">Search <?php echo ucfirst($tab); ?></label>
                  <input type="text" id="search" name="search" class="form-control" 
                         placeholder="Search <?php echo $tab; ?>..." 
                         value="<?php echo h2($search); ?>">
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

            <!-- Categories Tab Content -->
        <?php if ($tab === 'categories'): ?>
          <div class="mb-3">
            <?php if (user_has_permission('contacts.create')): ?>
              <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addCategoryModal">
                <i class="bi bi-plus-circle"></i> Add Category
              </button>
            <?php endif; ?>
          </div>

          <div class="card shadow-sm">
            <div class="card-header bg-light">
              <h6 class="mb-0">
                <i class="bi bi-folder"></i> Categories
                <span class="badge bg-primary rounded-pill float-end"><?= $total ?></span>
              </h6>
            </div>
            <div class="card-body p-0">
              <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                  <thead class="table-light">
                    <tr>
                      <th style="width: 60px;">Color</th>
                      <th style="min-width: 200px;">Name</th>
                      <th style="min-width: 250px;">Description</th>
                      <th style="width: 80px;" class="text-end">Contacts</th>
                      <th style="width: 80px;">Status</th>
                      <th style="width: 120px;" class="text-center">Actions</th>
                    </tr>
                  </thead>
                        <tbody>
                            <?php if (empty($items)): ?>
                                <tr>
                                    <td colspan="6" class="text-center text-muted py-4">
                                        No categories found
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($items as $cat): ?>
                                    <tr>
                                        <td>
                                            <span class="badge" style="background-color: <?php echo h2($cat['color']); ?>; color: #fff;">
                                                <i class="bi bi-square-fill"></i>
                                            </span>
                                        </td>
                                        <td><strong><?php echo h2($cat['name']); ?></strong></td>
                                        <td><?php echo h2($cat['description'] ?? '-'); ?></td>
                                        <td><?php echo (int)($cat['contact_count'] ?? 0); ?></td>
                                        <td>
                                            <span class="badge bg-<?php echo $cat['is_active'] ? 'success' : 'secondary'; ?>">
                                                <?php echo $cat['is_active'] ? 'Active' : 'Inactive'; ?>
                                            </span>
                                        </td>
                                        <td>
                                            <div class="btn-group btn-group-sm" role="group">
                                                <?php if (user_has_permission('contacts.update')): ?>
                                                    <button class="btn btn-primary" data-bs-toggle="modal" 
                                                            data-bs-target="#editCategoryModal<?php echo $cat['id']; ?>" title="Edit">
                                                        <i class="bi bi-pencil"></i>
                                                    </button>
                                                <?php endif; ?>
                                                <?php if (user_has_permission('contacts.delete')): ?>
                                                    <button class="btn btn-danger" data-bs-toggle="modal" 
                                                            data-bs-target="#deleteCategoryModal<?php echo $cat['id']; ?>" title="Delete">
                                                        <i class="bi bi-trash"></i>
                                                    </button>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                    </tr>

                                    <!-- Edit Category Modal -->
                                    <div class="modal fade" id="editCategoryModal<?php echo $cat['id']; ?>" tabindex="-1">
                                        <div class="modal-dialog">
                                            <div class="modal-content">
                                                <div class="modal-header bg-primary text-white">
                                                    <h5 class="modal-title">Edit Category</h5>
                                                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                                                </div>
                                                <form method="POST">
                                                    <div class="modal-body">
                                                        <input type="hidden" name="action" value="update_category">
                                                        <input type="hidden" name="category_id" value="<?php echo $cat['id']; ?>">
                                                        <input type="hidden" name="tab" value="categories">
                                                        
                                                        <div class="mb-3">
                                                            <label class="form-label" for="catName<?php echo $cat['id']; ?>">Name *</label>
                                                            <input type="text" class="form-control" id="catName<?php echo $cat['id']; ?>" 
                                                                   name="name" value="<?php echo h2($cat['name']); ?>" required>
                                                        </div>
                                                        
                                                        <div class="mb-3">
                                                            <label class="form-label" for="catDesc<?php echo $cat['id']; ?>">Description</label>
                                                            <textarea class="form-control" id="catDesc<?php echo $cat['id']; ?>" 
                                                                      name="description" rows="3"><?php echo h2($cat['description']); ?></textarea>
                                                        </div>
                                                        
                                                        <div class="mb-3">
                                                            <label class="form-label" for="catColor<?php echo $cat['id']; ?>">Color</label>
                                                            <input type="color" class="form-control form-control-color" id="catColor<?php echo $cat['id']; ?>" 
                                                                   name="color" value="<?php echo h2($cat['color']); ?>">
                                                        </div>
                                                        
                                                        <div class="form-check">
                                                            <input class="form-check-input" type="checkbox" id="catActive<?php echo $cat['id']; ?>" 
                                                                   name="is_active" <?php echo $cat['is_active'] ? 'checked' : ''; ?>>
                                                            <label class="form-check-label" for="catActive<?php echo $cat['id']; ?>">
                                                                Active
                                                            </label>
                                                        </div>
                                                    </div>
                                                    <div class="modal-footer">
                                                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                                                        <button type="submit" class="btn btn-primary">Update Category</button>
                                                    </div>
                                                </form>
                                            </div>
                                        </div>
                                    </div>

                                    <!-- Delete Category Modal -->
                                    <div class="modal fade" id="deleteCategoryModal<?php echo $cat['id']; ?>" tabindex="-1">
                                        <div class="modal-dialog">
                                            <div class="modal-content">
                                                <div class="modal-header bg-danger text-white">
                                                    <h5 class="modal-title">Confirm Delete</h5>
                                                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                                                </div>
                                                <form method="POST">
                                                    <div class="modal-body">
                                                        <p>Are you sure you want to delete this category?</p>
                                                        <p class="text-muted"><strong><?php echo h2($cat['name']); ?></strong></p>
                                                    </div>
                                                    <div class="modal-footer">
                                                        <input type="hidden" name="action" value="delete_category">
                                                        <input type="hidden" name="category_id" value="<?php echo $cat['id']; ?>">
                                                        <input type="hidden" name="tab" value="categories">
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
            <?php endif; ?>

            <!-- Tags Tab Content -->
        <?php if ($tab === 'tags'): ?>
          <div class="mb-3">
            <?php if (user_has_permission('contacts.create')): ?>
              <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addTagModal">
                <i class="bi bi-plus-circle"></i> Add Tag
              </button>
            <?php endif; ?>
          </div>

          <div class="card shadow-sm">
            <div class="card-header bg-light">
              <h6 class="mb-0">
                <i class="bi bi-tags"></i> Tags
                <span class="badge bg-primary rounded-pill float-end"><?= $total ?></span>
              </h6>
            </div>
            <div class="card-body p-0">
              <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                  <thead class="table-light">
                    <tr>
                      <th style="width: 60px;">Color</th>
                      <th style="min-width: 200px;">Name</th>
                      <th style="width: 80px;" class="text-end">Contacts</th>
                      <th style="width: 80px;">Status</th>
                      <th style="width: 120px;" class="text-center">Actions</th>
                    </tr>
                  </thead>
                        <tbody>
                            <?php if (empty($items)): ?>
                                <tr>
                                    <td colspan="5" class="text-center text-muted py-4">
                                        No tags found
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($items as $tag): ?>
                                    <tr>
                                        <td>
                                            <span class="badge" style="background-color: <?php echo h2($tag['color']); ?>; color: #fff;">
                                                <i class="bi bi-tag-fill"></i>
                                            </span>
                                        </td>
                                        <td><strong><?php echo h2($tag['name']); ?></strong></td>
                                        <td><?php echo (int)($tag['contact_count'] ?? 0); ?></td>
                                        <td>
                                            <span class="badge bg-<?php echo $tag['is_active'] ? 'success' : 'secondary'; ?>">
                                                <?php echo $tag['is_active'] ? 'Active' : 'Inactive'; ?>
                                            </span>
                                        </td>
                                        <td>
                                            <div class="btn-group btn-group-sm" role="group">
                                                <?php if (user_has_permission('contacts.update')): ?>
                                                    <button class="btn btn-primary" data-bs-toggle="modal" 
                                                            data-bs-target="#editTagModal<?php echo $tag['id']; ?>" title="Edit">
                                                        <i class="bi bi-pencil"></i>
                                                    </button>
                                                <?php endif; ?>
                                                <?php if (user_has_permission('contacts.delete')): ?>
                                                    <button class="btn btn-danger" data-bs-toggle="modal" 
                                                            data-bs-target="#deleteTagModal<?php echo $tag['id']; ?>" title="Delete">
                                                        <i class="bi bi-trash"></i>
                                                    </button>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                    </tr>

                                    <!-- Edit Tag Modal -->
                                    <div class="modal fade" id="editTagModal<?php echo $tag['id']; ?>" tabindex="-1">
                                        <div class="modal-dialog">
                                            <div class="modal-content">
                                                <div class="modal-header bg-primary text-white">
                                                    <h5 class="modal-title">Edit Tag</h5>
                                                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                                                </div>
                                                <form method="POST">
                                                    <div class="modal-body">
                                                        <input type="hidden" name="action" value="update_tag">
                                                        <input type="hidden" name="tag_id" value="<?php echo $tag['id']; ?>">
                                                        <input type="hidden" name="tab" value="tags">
                                                        
                                                        <div class="mb-3">
                                                            <label class="form-label" for="tagName<?php echo $tag['id']; ?>">Name *</label>
                                                            <input type="text" class="form-control" id="tagName<?php echo $tag['id']; ?>" 
                                                                   name="name" value="<?php echo h2($tag['name']); ?>" required>
                                                        </div>
                                                        
                                                        <div class="mb-3">
                                                            <label class="form-label" for="tagColor<?php echo $tag['id']; ?>">Color</label>
                                                            <input type="color" class="form-control form-control-color" id="tagColor<?php echo $tag['id']; ?>" 
                                                                   name="color" value="<?php echo h2($tag['color']); ?>">
                                                        </div>
                                                        
                                                        <div class="form-check">
                                                            <input class="form-check-input" type="checkbox" id="tagActive<?php echo $tag['id']; ?>" 
                                                                   name="is_active" <?php echo $tag['is_active'] ? 'checked' : ''; ?>>
                                                            <label class="form-check-label" for="tagActive<?php echo $tag['id']; ?>">
                                                                Active
                                                            </label>
                                                        </div>
                                                    </div>
                                                    <div class="modal-footer">
                                                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                                                        <button type="submit" class="btn btn-primary">Update Tag</button>
                                                    </div>
                                                </form>
                                            </div>
                                        </div>
                                    </div>

                                    <!-- Delete Tag Modal -->
                                    <div class="modal fade" id="deleteTagModal<?php echo $tag['id']; ?>" tabindex="-1">
                                        <div class="modal-dialog">
                                            <div class="modal-content">
                                                <div class="modal-header bg-danger text-white">
                                                    <h5 class="modal-title">Confirm Delete</h5>
                                                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                                                </div>
                                                <form method="POST">
                                                    <div class="modal-body">
                                                        <p>Are you sure you want to delete this tag?</p>
                                                        <p class="text-muted"><strong><?php echo h2($tag['name']); ?></strong></p>
                                                    </div>
                                                    <div class="modal-footer">
                                                        <input type="hidden" name="action" value="delete_tag">
                                                        <input type="hidden" name="tag_id" value="<?php echo $tag['id']; ?>">
                                                        <input type="hidden" name="tab" value="tags">
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
            <?php endif; ?>

            <!-- Pagination -->
            <?php if ($total_pages > 1): ?>
                <nav aria-label="Page navigation" class="mt-4">
                    <ul class="pagination justify-content-center">
                        <li class="page-item <?php echo $page <= 1 ? 'disabled' : ''; ?>">
                            <a class="page-link" href="?page=1&tab=<?php echo $tab; ?><?php echo $search ? '&search=' . urlencode($search) : ''; ?>">First</a>
                        </li>
                        <li class="page-item <?php echo $page <= 1 ? 'disabled' : ''; ?>">
                            <a class="page-link" href="?page=<?php echo $page - 1; ?>&tab=<?php echo $tab; ?><?php echo $search ? '&search=' . urlencode($search) : ''; ?>">Previous</a>
                        </li>
                        
                        <?php for ($i = max(1, $page - 2); $i <= min($total_pages, $page + 2); $i++): ?>
                            <li class="page-item <?php echo $i === $page ? 'active' : ''; ?>">
                                <a class="page-link" href="?page=<?php echo $i; ?>&tab=<?php echo $tab; ?><?php echo $search ? '&search=' . urlencode($search) : ''; ?>">
                                    <?php echo $i; ?>
                                </a>
                            </li>
                        <?php endfor; ?>
                        
                        <li class="page-item <?php echo $page >= $total_pages ? 'disabled' : ''; ?>">
                            <a class="page-link" href="?page=<?php echo $page + 1; ?>&tab=<?php echo $tab; ?><?php echo $search ? '&search=' . urlencode($search) : ''; ?>">Next</a>
                        </li>
                        <li class="page-item <?php echo $page >= $total_pages ? 'disabled' : ''; ?>">
                            <a class="page-link" href="?page=<?php echo $total_pages; ?>&tab=<?php echo $tab; ?><?php echo $search ? '&search=' . urlencode($search) : ''; ?>">Last</a>
                        </li>
                    </ul>
                </nav>
          <?php endif; ?>
      </div>
    </main>
  </div>
</div>

<!-- Add Category Modal -->
<div class="modal fade" id="addCategoryModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title">Add New Category</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <div class="modal-body">
                    <input type="hidden" name="action" value="add_category">
                    <input type="hidden" name="tab" value="categories">
                    
                    <div class="mb-3">
                        <label class="form-label" for="newCatName">Name *</label>
                        <input type="text" class="form-control" id="newCatName" name="name" placeholder="Category name" required>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label" for="newCatDesc">Description</label>
                        <textarea class="form-control" id="newCatDesc" name="description" rows="3" placeholder="Category description"></textarea>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label" for="newCatColor">Color</label>
                        <input type="color" class="form-control form-control-color" id="newCatColor" name="color" value="#007bff">
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Add Category</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Add Tag Modal -->
<div class="modal fade" id="addTagModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title">Add New Tag</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <div class="modal-body">
                    <input type="hidden" name="action" value="add_tag">
                    <input type="hidden" name="tab" value="tags">
                    
                    <div class="mb-3">
                        <label class="form-label" for="newTagName">Name *</label>
                        <input type="text" class="form-control" id="newTagName" name="name" placeholder="Tag name" required>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label" for="newTagColor">Color</label>
                        <input type="color" class="form-control form-control-color" id="newTagColor" name="color" value="#28a745">
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Add Tag</button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php include __DIR__ . '/../../templates/layout/footer.php'; ?>
