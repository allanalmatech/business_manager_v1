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
$export_dir = __DIR__ . '/../../uploads/exports/';

// Create export directory if it doesn't exist
if (!is_dir($export_dir)) {
    mkdir($export_dir, 0755, true);
}

// Handle file deletion
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] === 'delete_file' && user_has_permission('contacts.delete')) {
        $filename = basename((string)($_POST['filename'] ?? ''));
        $filepath = $export_dir . $filename;
        
        if ($filename && file_exists($filepath) && strpos(realpath($filepath), realpath($export_dir)) === 0) {
            if (unlink($filepath)) {
                $message = 'Export file deleted successfully';
                $message_type = 'success';
                if (function_exists('audit_log')) {
                    audit_log('contacts.export_delete', 'exports', $filename, "Deleted: $filename");
                }
            } else {
                $message = 'Failed to delete file';
                $message_type = 'danger';
            }
        } else {
            $message = 'Invalid file';
            $message_type = 'danger';
        }
    } elseif ($_POST['action'] === 'clear_all' && user_has_permission('contacts.delete')) {
        $files = glob($export_dir . '*.txt');
        $deleted_count = 0;
        foreach ($files as $file) {
            if (unlink($file)) {
                $deleted_count++;
            }
        }
        $message = "Cleared $deleted_count export files";
        $message_type = 'success';
        if (function_exists('audit_log')) {
            audit_log('contacts.export_clear', 'exports', '', "Cleared $deleted_count files");
        }
    }
}

// Handle export generation
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['export_action'])) {
    if ($_POST['export_action'] === 'generate_export' && user_has_permission('contacts.create')) {
        $format = trim((string)($_POST['format'] ?? 'txt'));
        $status_filter = trim((string)($_POST['status_filter'] ?? ''));
        $type_filter = trim((string)($_POST['type_filter'] ?? ''));
        
        if (!in_array($format, ['txt', 'detailed', 'csv'])) {
            $format = 'txt';
        }
        
        // Build query with filters
        $where = ["1 = 1"];
        $params = [];
        $types = '';
        
        if ($status_filter && in_array($status_filter, ['active', 'inactive', 'prospect', 'archived'])) {
            $where[] = "c.status = ?";
            $params[] = $status_filter;
            $types .= 's';
        }
        
        if ($type_filter && in_array($type_filter, ['individual', 'business', 'lead'])) {
            $where[] = "c.type = ?";
            $params[] = $type_filter;
            $types .= 's';
        }
        
        $where_clause = implode(' AND ', $where);
        
        $query = "
            SELECT c.*,
                   GROUP_CONCAT(DISTINCT cat.name SEPARATOR ', ') as categories,
                   GROUP_CONCAT(DISTINCT tag.name SEPARATOR ', ') as tags
            FROM contacts c
            LEFT JOIN contact_category_map ccm ON c.id = ccm.contact_id
            LEFT JOIN contact_categories cat ON ccm.category_id = cat.id
            LEFT JOIN contact_tag_map ctm ON c.id = ctm.contact_id
            LEFT JOIN contact_tags tag ON ctm.tag_id = tag.id
            WHERE $where_clause
            GROUP BY c.id
            ORDER BY c.first_name, c.last_name
        ";
        
        $st = $db->prepare($query);
        if ($st && $types) {
            $st->bind_param($types, ...$params);
        }
        
        $contacts = [];
        if ($st) {
            $st->execute();
            $contacts = $st->get_result()->fetch_all(MYSQLI_ASSOC);
            $st->close();
        }
        
        if (empty($contacts)) {
            $message = 'No contacts found with selected filters';
            $message_type = 'warning';
        } else {
            // Generate file based on format
            $content = '';
            $file_ext = 'txt';
            
            if ($format === 'detailed') {
                $content = generate_detailed_text($contacts);
                $filename = 'contacts_detailed_' . date('Y-m-d_His') . '.txt';
            } elseif ($format === 'csv') {
                $content = generate_csv($contacts);
                $filename = 'contacts_' . date('Y-m-d_His') . '.csv';
                $file_ext = 'csv';
            } else {
                $content = generate_plain_text($contacts);
                $filename = 'contacts_' . date('Y-m-d_His') . '.txt';
            }
            
            $filepath = $export_dir . $filename;
            
            if (file_put_contents($filepath, $content)) {
                $message = 'Export generated successfully: ' . $filename . ' (' . count($contacts) . ' contacts)';
                $message_type = 'success';
                if (function_exists('audit_log')) {
                    audit_log('contacts.export_generate', 'exports', $filename, "Generated: $filename (" . count($contacts) . " contacts)");
                }
            } else {
                $message = 'Failed to generate export file';
                $message_type = 'danger';
            }
        }
    }
}

// Get export files
$export_files = [];
if (is_dir($export_dir)) {
    $files = glob($export_dir . '*.*');
    usort($files, function($a, $b) {
        return filemtime($b) - filemtime($a);
    });
    
    foreach ($files as $file) {
        $export_files[] = [
            'name' => basename($file),
            'size' => filesize($file),
            'date' => filemtime($file),
            'type' => pathinfo($file, PATHINFO_EXTENSION)
        ];
    }
}

// Get directory stats
$total_size = 0;
foreach ($export_files as $file) {
    $total_size += $file['size'];
}

// Get contact statistics
$stats_query = "
    SELECT 
        COUNT(*) as total,
        SUM(CASE WHEN type = 'individual' THEN 1 ELSE 0 END) as individuals,
        SUM(CASE WHEN type = 'business' THEN 1 ELSE 0 END) as businesses,
        SUM(CASE WHEN type = 'lead' THEN 1 ELSE 0 END) as leads,
        SUM(CASE WHEN status = 'active' THEN 1 ELSE 0 END) as active,
        SUM(CASE WHEN status = 'prospect' THEN 1 ELSE 0 END) as prospects
    FROM contacts
";

$stats = $db->query($stats_query)->fetch_assoc();

function h2($text) {
    return htmlspecialchars($text ?? '', ENT_QUOTES, 'UTF-8');
}

function user_has_permission(string $permission): bool {
    if (!function_exists('user_has_permission')) {
        return true;
    }
    return (bool)user_has_permission($permission);
}

function format_bytes($bytes) {
    $units = ['B', 'KB', 'MB', 'GB'];
    $bytes = max($bytes, 0);
    $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
    $pow = min($pow, count($units) - 1);
    $bytes /= (1 << (10 * $pow));
    return round($bytes, 2) . ' ' . $units[$pow];
}

function generate_plain_text($contacts) {
    $output = "CONTACTS EXPORT\n";
    $output .= "Generated: " . date('Y-m-d H:i:s') . "\n";
    $output .= "Total Records: " . count($contacts) . "\n";
    $output .= str_repeat("=", 80) . "\n\n";
    
    foreach ($contacts as $idx => $contact) {
        $output .= ($idx + 1) . ". " . h2($contact['first_name'] . ' ' . $contact['last_name']) . "\n";
        $output .= "   Email: " . h2($contact['email']) . "\n";
        
        if ($contact['phone']) {
            $output .= "   Phone: " . h2($contact['phone']) . "\n";
        }
        
        if ($contact['company']) {
            $output .= "   Company: " . h2($contact['company']) . "\n";
        }
        
        $output .= "   Type: " . ucfirst($contact['type']) . "\n";
        $output .= "   Status: " . ucfirst($contact['status']) . "\n";
        
        if ($contact['categories']) {
            $output .= "   Categories: " . h2($contact['categories']) . "\n";
        }
        
        if ($contact['tags']) {
            $output .= "   Tags: " . h2($contact['tags']) . "\n";
        }
        
        $output .= "\n";
    }
    
    return $output;
}

function generate_detailed_text($contacts) {
    $output = "DETAILED CONTACTS EXPORT\n";
    $output .= "Generated: " . date('Y-m-d H:i:s') . "\n";
    $output .= "Total Records: " . count($contacts) . "\n";
    $output .= str_repeat("=", 100) . "\n\n";
    
    foreach ($contacts as $idx => $contact) {
        $output .= str_repeat("-", 100) . "\n";
        $output .= "CONTACT #" . ($idx + 1) . "\n";
        $output .= str_repeat("-", 100) . "\n";
        
        $output .= "Name:         " . h2($contact['first_name'] . ' ' . $contact['last_name']) . "\n";
        $output .= "Email:        " . h2($contact['email']) . "\n";
        
        if ($contact['phone']) {
            $output .= "Phone:        " . h2($contact['phone']) . "\n";
        }
        
        if ($contact['company']) {
            $output .= "Company:      " . h2($contact['company']) . "\n";
        }
        
        $output .= "Type:         " . ucfirst($contact['type']) . "\n";
        $output .= "Status:       " . ucfirst($contact['status']) . "\n";
        
        if ($contact['address']) {
            $output .= "Address:      " . h2($contact['address']) . "\n";
        }
        
        if ($contact['city']) {
            $output .= "City:         " . h2($contact['city']) . "\n";
        }
        
        if ($contact['state']) {
            $output .= "State:        " . h2($contact['state']) . "\n";
        }
        
        if ($contact['country']) {
            $output .= "Country:      " . h2($contact['country']) . "\n";
        }
        
        if ($contact['categories']) {
            $output .= "Categories:   " . h2($contact['categories']) . "\n";
        }
        
        if ($contact['tags']) {
            $output .= "Tags:         " . h2($contact['tags']) . "\n";
        }
        
        if ($contact['notes']) {
            $output .= "Notes:\n";
            $output .= "  " . wordwrap(h2($contact['notes']), 96, "\n  ") . "\n";
        }
        
        $output .= "Added:        " . date('M d, Y H:i', strtotime($contact['created_at'])) . "\n";
        $output .= "\n";
    }
    
    $output .= str_repeat("=", 100) . "\n";
    $output .= "End of Export\n";
    
    return $output;
}

function generate_csv($contacts) {
    $output = "First Name,Last Name,Email,Phone,Company,Type,Address,City,State,Country,Categories,Tags,Status,Added\n";
    
    foreach ($contacts as $contact) {
        $line = [
            $contact['first_name'],
            $contact['last_name'],
            $contact['email'],
            $contact['phone'],
            $contact['company'],
            ucfirst($contact['type']),
            $contact['address'],
            $contact['city'],
            $contact['state'],
            $contact['country'],
            $contact['categories'],
            $contact['tags'],
            ucfirst($contact['status']),
            date('M d, Y H:i', strtotime($contact['created_at']))
        ];
        
        $output .= '"' . implode('","', array_map(function($v) {
            return str_replace('"', '""', $v);
        }, $line)) . '"' . "\n";
    }
    
    return $output;
}

$page_title = 'Bulk Export';
include __DIR__ . '/../../templates/layout/header.php';
?>

<div class="container-fluid mt-4">
    <div class="row">
        <div class="col-md-3">
            <?php include __DIR__ . '/../../templates/layout/sidebar.php'; ?>
        </div>
        <div class="col-md-9">
            <div class="mb-4">
                <h2>Bulk Export Manager</h2>
            </div>

            <?php if ($message): ?>
                <div class="alert alert-<?php echo $message_type; ?> alert-dismissible fade show" role="alert">
                    <?php echo h2($message); ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>

            <!-- Contact Statistics -->
            <div class="row mb-4">
                <div class="col-md-2">
                    <div class="card text-center">
                        <div class="card-body">
                            <h6 class="card-title text-muted">Total Contacts</h6>
                            <h4 class="text-primary"><?php echo $stats['total'] ?? 0; ?></h4>
                        </div>
                    </div>
                </div>
                <div class="col-md-2">
                    <div class="card text-center">
                        <div class="card-body">
                            <h6 class="card-title text-muted">Individuals</h6>
                            <h4 class="text-info"><?php echo $stats['individuals'] ?? 0; ?></h4>
                        </div>
                    </div>
                </div>
                <div class="col-md-2">
                    <div class="card text-center">
                        <div class="card-body">
                            <h6 class="card-title text-muted">Businesses</h6>
                            <h4 class="text-warning"><?php echo $stats['businesses'] ?? 0; ?></h4>
                        </div>
                    </div>
                </div>
                <div class="col-md-2">
                    <div class="card text-center">
                        <div class="card-body">
                            <h6 class="card-title text-muted">Leads</h6>
                            <h4 class="text-success"><?php echo $stats['leads'] ?? 0; ?></h4>
                        </div>
                    </div>
                </div>
                <div class="col-md-2">
                    <div class="card text-center">
                        <div class="card-body">
                            <h6 class="card-title text-muted">Active</h6>
                            <h4 class="text-success"><?php echo $stats['active'] ?? 0; ?></h4>
                        </div>
                    </div>
                </div>
                <div class="col-md-2">
                    <div class="card text-center">
                        <div class="card-body">
                            <h6 class="card-title text-muted">Prospects</h6>
                            <h4 class="text-info"><?php echo $stats['prospects'] ?? 0; ?></h4>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Export Directory Stats -->
            <div class="row mb-4">
                <div class="col-md-6">
                    <div class="card">
                        <div class="card-body">
                            <h6 class="card-title">Export Directory</h6>
                            <p class="mb-1"><strong><?php echo count($export_files); ?></strong> files</p>
                            <p class="mb-0 text-muted"><strong><?php echo format_bytes($total_size); ?></strong> total size</p>
                        </div>
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="card">
                        <div class="card-body">
                            <h6 class="card-title">Export Path</h6>
                            <p class="mb-0 text-muted" style="word-break: break-all; font-size: 0.9em;">
                                <code><?php echo h2($export_dir); ?></code>
                            </p>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Export Generator -->
            <div class="card mb-4">
                <div class="card-header bg-primary text-white">
                    <h5 class="mb-0">Generate New Export</h5>
                </div>
                <div class="card-body">
                    <form method="POST">
                        <div class="row">
                            <div class="col-md-3">
                                <label class="form-label" for="format">Format</label>
                                <select class="form-select" id="format" name="format">
                                    <option value="txt">Plain Text</option>
                                    <option value="detailed">Detailed Text</option>
                                    <option value="csv">CSV</option>
                                </select>
                            </div>
                            <div class="col-md-3">
                                <label class="form-label" for="type_filter">Contact Type</label>
                                <select class="form-select" id="type_filter" name="type_filter">
                                    <option value="">All Types</option>
                                    <option value="individual">Individual</option>
                                    <option value="business">Business</option>
                                    <option value="lead">Lead</option>
                                </select>
                            </div>
                            <div class="col-md-3">
                                <label class="form-label" for="status_filter">Status</label>
                                <select class="form-select" id="status_filter" name="status_filter">
                                    <option value="">All Status</option>
                                    <option value="active">Active</option>
                                    <option value="prospect">Prospect</option>
                                    <option value="inactive">Inactive</option>
                                    <option value="archived">Archived</option>
                                </select>
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">&nbsp;</label>
                                <button type="submit" class="btn btn-primary w-100" name="export_action" value="generate_export">
                                    <i class="fas fa-download"></i> Generate Export
                                </button>
                            </div>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Export Files -->
            <div class="card">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h5 class="mb-0">Export Files</h5>
                    <?php if (count($export_files) > 0 && user_has_permission('contacts.delete')): ?>
                        <button class="btn btn-sm btn-danger" data-bs-toggle="modal" data-bs-target="#clearAllModal">
                            <i class="fas fa-trash"></i> Clear All
                        </button>
                    <?php endif; ?>
                </div>
                <div class="table-responsive">
                    <table class="table table-hover mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>Filename</th>
                                <th>Size</th>
                                <th>Type</th>
                                <th>Created</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($export_files)): ?>
                                <tr>
                                    <td colspan="5" class="text-center text-muted py-4">
                                        No export files yet
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($export_files as $file): ?>
                                    <tr>
                                        <td>
                                            <i class="fas fa-file-<?php echo $file['type'] === 'csv' ? 'csv' : 'alt'; ?>"></i>
                                            <?php echo h2($file['name']); ?>
                                        </td>
                                        <td><?php echo format_bytes($file['size']); ?></td>
                                        <td>
                                            <span class="badge bg-<?php echo $file['type'] === 'csv' ? 'info' : 'secondary'; ?>">
                                                <?php echo strtoupper($file['type']); ?>
                                            </span>
                                        </td>
                                        <td><?php echo date('M d, Y H:i', $file['date']); ?></td>
                                        <td>
                                            <div class="btn-group btn-group-sm" role="group">
                                                <a href="<?php echo $base_url; ?>uploads/exports/<?php echo urlencode($file['name']); ?>" 
                                                   class="btn btn-success" title="Download">
                                                    <i class="fas fa-download"></i>
                                                </a>
                                                <?php if (user_has_permission('contacts.delete')): ?>
                                                    <button class="btn btn-danger" data-bs-toggle="modal" 
                                                            data-bs-target="#deleteModal<?php echo md5($file['name']); ?>" title="Delete">
                                                        <i class="fas fa-trash"></i>
                                                    </button>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                    </tr>

                                    <!-- Delete Modal -->
                                    <div class="modal fade" id="deleteModal<?php echo md5($file['name']); ?>" tabindex="-1">
                                        <div class="modal-dialog">
                                            <div class="modal-content">
                                                <div class="modal-header bg-danger text-white">
                                                    <h5 class="modal-title">Confirm Delete</h5>
                                                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                                                </div>
                                                <form method="POST">
                                                    <div class="modal-body">
                                                        <p>Are you sure you want to delete this export file?</p>
                                                        <p class="text-muted"><strong><?php echo h2($file['name']); ?></strong></p>
                                                    </div>
                                                    <div class="modal-footer">
                                                        <input type="hidden" name="action" value="delete_file">
                                                        <input type="hidden" name="filename" value="<?php echo h2($file['name']); ?>">
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
            </div>
        </div>
    </div>
</div>

<!-- Clear All Modal -->
<div class="modal fade" id="clearAllModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header bg-danger text-white">
                <h5 class="modal-title">Confirm Clear All</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <div class="modal-body">
                    <p>Are you sure you want to delete all export files?</p>
                    <p class="text-muted">This action cannot be undone.</p>
                </div>
                <div class="modal-footer">
                    <input type="hidden" name="action" value="clear_all">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-danger">Delete All Files</button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php include __DIR__ . '/../../templates/layout/footer.php'; ?>
