<?php
// api/settings/delete.php
declare(strict_types=1);

// Enable error reporting for debugging but don't display errors
error_reporting(E_ALL);
ini_set('display_errors', 0);

// Start output buffering to catch any unexpected output
ob_start();

require_once dirname(dirname(__DIR__)) . '/includes/bootstrap.php';
require_once dirname(dirname(__DIR__)) . '/includes/auth.php';
require_once dirname(dirname(__DIR__)) . '/includes/helpers.php';

// Clear any output that might have been generated
ob_clean();

header('Content-Type: application/json');

// Check authentication
try {
    if (function_exists('require_admin_login')) {
        require_admin_login();
    }
    if (function_exists('require_permission')) {
        require_permission('settings.manage');
    }
} catch (Exception $e) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Authentication failed: ' . $e->getMessage()]);
    exit;
}

$db = $GLOBALS['db'] ?? null;

$response = ['success' => false, 'message' => 'Invalid request'];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $db instanceof mysqli) {
    $key = trim($_POST['key'] ?? '');
    
    if (empty($key)) {
        $response['message'] = 'Setting key is required';
    } else {
        // Delete the setting
        $stmt = $db->prepare("DELETE FROM settings WHERE `key` = ?");
        $stmt->bind_param('s', $key);
        
        if ($stmt->execute()) {
            if ($stmt->affected_rows > 0) {
                $response = ['success' => true, 'message' => 'Setting deleted successfully'];
            } else {
                $response['message'] = 'Setting not found';
            }
        } else {
            $response['message'] = 'Failed to delete setting: ' . $db->error;
        }
        $stmt->close();
    }
} else {
    $response['message'] = 'Method not allowed or database unavailable';
}

// Ensure clean JSON output
try {
    echo json_encode($response, JSON_THROW_ON_ERROR);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'JSON encoding error: ' . $e->getMessage()]);
}
