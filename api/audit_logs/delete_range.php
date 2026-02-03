<?php
// api/audit_logs/delete_range.php
declare(strict_types=1);

require_once dirname(dirname(__DIR__)) . '/includes/bootstrap.php';
require_once dirname(dirname(__DIR__)) . '/includes/auth.php';
require_once dirname(dirname(__DIR__)) . '/includes/rbac.php';

// Enable output buffering to prevent HTML output before JSON
ob_start();

try {
    // Check authentication and permissions
    if (function_exists('require_admin_login')) require_admin_login();
    require_permission('audit.manage'); // Using audit.manage for delete operations
    
    $db = $GLOBALS['db'] ?? null;
    if (!$db instanceof mysqli) {
        throw new Exception('Database not available');
    }
    
    // Get and validate input
    $deleteFrom = $_POST['delete_from'] ?? '';
    $deleteTo = $_POST['delete_to'] ?? '';
    
    if (empty($deleteFrom) || empty($deleteTo)) {
        throw new Exception('Both from and to dates are required');
    }
    
    // Validate date format
    if (!DateTime::createFromFormat('Y-m-d', $deleteFrom) || !DateTime::createFromFormat('Y-m-d', $deleteTo)) {
        throw new Exception('Invalid date format. Please use YYYY-MM-DD format');
    }
    
    // Validate date range
    $fromDate = new DateTime($deleteFrom);
    $toDate = new DateTime($deleteTo);
    $toDate->setTime(23, 59, 59); // Include end of day
    
    if ($fromDate > $toDate) {
        throw new Exception('From date cannot be later than to date');
    }
    
    // Check if audit_logs table exists
    $result = $db->query("SHOW TABLES LIKE 'audit_logs'");
    if (!$result || $result->num_rows === 0) {
        throw new Exception('audit_logs table not found');
    }
    
    // Count logs to be deleted first
    $countStmt = $db->prepare("SELECT COUNT(*) as count FROM audit_logs WHERE created_at BETWEEN ? AND ?");
    $countStmt->bind_param('ss', $deleteFrom, $deleteTo);
    $countStmt->execute();
    $countResult = $countStmt->get_result();
    $deletedCount = $countResult->fetch_assoc()['count'] ?? 0;
    $countStmt->close();
    
    if ($deletedCount == 0) {
        throw new Exception('No audit logs found in the specified date range');
    }
    
    // Delete the logs
    $deleteStmt = $db->prepare("DELETE FROM audit_logs WHERE created_at BETWEEN ? AND ?");
    $deleteStmt->bind_param('ss', $deleteFrom, $deleteTo);
    
    if (!$deleteStmt->execute()) {
        throw new Exception('Failed to delete audit logs: ' . $deleteStmt->error);
    }
    
    $deleteStmt->close();
    
    // Log this deletion action
    $currentUser = current_user();
    $logDetails = "Deleted {$deletedCount} audit logs from {$deleteFrom} to {$deleteTo}";
    
    $logStmt = $db->prepare("INSERT INTO audit_logs (user_id, action, entity, details, ip_address, created_at) VALUES (?, ?, ?, ?, ?, NOW())");
    $action = 'DELETE_AUDIT_LOGS';
    $entity = 'audit_logs';
    $ipAddress = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    
    $logStmt->bind_param('issss', 
        $currentUser['id'] ?? null, 
        $action, 
        $entity, 
        $logDetails, 
        $ipAddress
    );
    $logStmt->execute();
    $logStmt->close();
    
    // Send success response
    header('Content-Type: application/json');
    echo json_encode([
        'success' => true,
        'message' => "Successfully deleted {$deletedCount} audit logs",
        'deleted_count' => (int)$deletedCount,
        'date_range' => [
            'from' => $deleteFrom,
            'to' => $deleteTo
        ]
    ]);
    
} catch (Exception $e) {
    // Send error response
    header('Content-Type: application/json');
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
} finally {
    // Clean output buffer
    ob_end_flush();
}
?>
