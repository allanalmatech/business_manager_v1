<?php
// api/admin/upload_theme.php
declare(strict_types=1);

header('Content-Type: application/json');
require_once dirname(dirname(__DIR__)) . '/includes/bootstrap.php';
require_once dirname(dirname(__DIR__)) . '/includes/auth.php';
require_once dirname(dirname(__DIR__)) . '/includes/rbac.php';

if (!is_logged_in()) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

if (!has_permission('settings.manage')) {
    echo json_encode(['success' => false, 'message' => 'Forbidden']);
    exit;
}

$db = $GLOBALS['db'] ?? null;
if (!$db) {
    echo json_encode(['success' => false, 'message' => 'Database connection failed']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
    exit;
}

$theme_name = trim($_POST['theme_name'] ?? '');
if ($theme_name === '') {
    echo json_encode(['success' => false, 'message' => 'Theme name is required']);
    exit;
}

if (!isset($_FILES['theme_file']) || $_FILES['theme_file']['error'] !== UPLOAD_ERR_OK) {
    echo json_encode(['success' => false, 'message' => 'CSS file upload failed']);
    exit;
}

$file = $_FILES['theme_file'];
$ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));

if ($ext !== 'css') {
    echo json_encode(['success' => false, 'message' => 'Only .css files are allowed']);
    exit;
}

// Generate a safe theme ID
$theme_id = 'custom_' . preg_replace('/[^a-z0-9]/', '_', strtolower($theme_name));
$target_path = dirname(dirname(__DIR__)) . '/assets/css/themes/' . $theme_id . '.css';

if (move_uploaded_file($file['tmp_name'], $target_path)) {
    // Add the new theme to themes_bundle.css or just set it as current
    // For simplicity, we'll just set it as the current theme and the header will need to load it
    
    // Update setting in DB
    $stmt = $db->prepare("INSERT INTO settings (`key`, `value`, `description`) VALUES ('app_theme', ?, 'Current system theme') ON DUPLICATE KEY UPDATE `value` = ?");
    $stmt->bind_param('ss', $theme_id, $theme_id);
    $stmt->execute();
    $stmt->close();

    echo json_encode(['success' => true, 'message' => 'Theme uploaded and applied']);
} else {
    echo json_encode(['success' => false, 'message' => 'Failed to save CSS file']);
}
