<?php
// api/locations/get_locations.php
header('Content-Type: application/json');

// Start output buffering to catch any unwanted output
ob_start();

// Custom error handler
function handleError($errno, $errstr, $errfile, $errline) {
  ob_end_clean();
  echo json_encode([
    'success' => false, 
    'message' => "PHP Error: $errstr in $errfile on line $errline",
    'error_type' => $errno
  ]);
  exit;
}
set_error_handler('handleError');

// Exception handler
function handleException($exception) {
  ob_end_clean();
  echo json_encode([
    'success' => false, 
    'message' => "Exception: " . $exception->getMessage(),
    'file' => $exception->getFile(),
    'line' => $exception->getLine()
  ]);
  exit;
}
set_exception_handler('handleException');

try {
  require_once __DIR__ . '/../../includes/bootstrap.php';
  require_once __DIR__ . '/../../includes/auth.php';

  // Session and auth check
  if (session_status() === PHP_SESSION_NONE) {
    session_start();
  }

  if (empty($_SESSION['user']['id'])) {
    http_response_code(401);
    ob_end_clean();
    echo json_encode(['success' => false, 'message' => 'Authentication required']);
    exit;
  }

  // Permission check
  if (function_exists('user_has_permission')) {
    $canView = user_has_permission('pos.view') || user_has_permission('pos.create');
    if (!$canView) {
      http_response_code(403);
      ob_end_clean();
      echo json_encode(['success' => false, 'message' => 'Forbidden']);
      exit;
    }
  }

  $db = $GLOBALS['db'] ?? null;
  if (!$db instanceof mysqli) {
    http_response_code(500);
    ob_end_clean();
    echo json_encode(['success' => false, 'message' => 'Database not available']);
    exit;
  }

  $locations = [];

  // Priority 1: Check for dedicated 'locations' table (for stock management)
  $hasLocationsTable = $db->query("SHOW TABLES LIKE 'locations'")?->num_rows ?? 0;
  if ($hasLocationsTable) {
    // Check available columns first
    $columnsResult = $db->query("SHOW COLUMNS FROM locations");
    $availableColumns = [];
    if ($columnsResult) {
      while ($col = $columnsResult->fetch_assoc()) {
        $availableColumns[] = $col['Field'];
      }
      $columnsResult->free();
    }
    
    // Build query with only available columns
    $selectColumns = ['id', 'name'];
    if (in_array('description', $availableColumns)) {
      $selectColumns[] = 'description';
    }
    if (in_array('is_active', $availableColumns)) {
      $selectColumns[] = 'is_active';
    }
    
    $sql = "SELECT " . implode(', ', $selectColumns) . " FROM locations";
    
    // Add WHERE clause only if is_active column exists
    if (in_array('is_active', $availableColumns)) {
      $sql .= " WHERE is_active = 1";
    }
    $sql .= " ORDER BY name ASC";
    
    $result = $db->query($sql);
    
    if ($result) {
      while ($row = $result->fetch_assoc()) {
        $locations[] = [
          'id' => (int)$row['id'],
          'name' => (string)$row['name'],
          'description' => (string)($row['description'] ?? ''),
          'type' => 'stock_location'
        ];
      }
      $result->free();
    }
  }

  // Priority 2: Check for 'selling_locations' table
  if (empty($locations)) {
    $hasSellingLocationsTable = $db->query("SHOW TABLES LIKE 'selling\\_locations'")?->num_rows ?? 0;
    if ($hasSellingLocationsTable) {
      $sql = "SELECT id, name FROM selling_locations ORDER BY name ASC";
      $result = $db->query($sql);
      
      if ($result) {
        while ($row = $result->fetch_assoc()) {
          $locations[] = [
            'id' => (int)$row['id'],
            'name' => (string)$row['name'],
            'description' => '',
            'type' => 'selling_location'
          ];
        }
        $result->free();
      }
    }
  }

  // Priority 3: Fallback to unique location IDs from sales
  if (empty($locations)) {
    $sql = "SELECT DISTINCT selling_location_id as id, 
                   CONCAT('Location ', selling_location_id) as name 
            FROM sales 
            WHERE selling_location_id IS NOT NULL AND selling_location_id > 0
            ORDER BY selling_location_id";
    $result = $db->query($sql);
    
    if ($result) {
      while ($row = $result->fetch_assoc()) {
        $locations[] = [
          'id' => (int)$row['id'],
          'name' => (string)$row['name'],
          'description' => 'Generated from sales data',
          'type' => 'fallback'
        ];
      }
      $result->free();
    } else {
      // Check if there are any sales at all
      $salesCheck = $db->query("SELECT COUNT(*) as count FROM sales LIMIT 1");
      $hasSales = $salesCheck && $salesCheck->fetch_assoc()['count'] > 0;
      $salesCheck?->free();
      
      if ($hasSales) {
        // Create a default location if sales exist but no locations found
        $locations = [
          [
            'id' => 1,
            'name' => 'Default Location',
            'description' => 'Default location for returns',
            'type' => 'default'
          ]
        ];
      }
    }
  }

  // Clean output buffer and send JSON response
  ob_end_clean();
  echo json_encode([
    'success' => true,
    'locations' => $locations,
    'count' => count($locations)
  ]);

} catch (Exception $e) {
  ob_end_clean();
  echo json_encode([
    'success' => false, 
    'message' => 'Caught exception: ' . $e->getMessage(),
    'file' => $e->getFile(),
    'line' => $e->getLine(),
    'trace' => $e->getTraceAsString()
  ]);
} catch (Error $e) {
  ob_end_clean();
  echo json_encode([
    'success' => false, 
    'message' => 'Caught error: ' . $e->getMessage(),
    'file' => $e->getFile(),
    'line' => $e->getLine(),
    'trace' => $e->getTraceAsString()
  ]);
}
?>
