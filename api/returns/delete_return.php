<?php
// api/returns/delete_return.php
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

  if (session_status() === PHP_SESSION_NONE) session_start();
  if (empty($_SESSION['user']['id'])) {
    http_response_code(401);
    ob_end_clean();
    echo json_encode(['success' => false, 'message' => 'Authentication required']);
    exit;
  }

  // Permission check
  if (function_exists('user_has_permission')) {
    $canManage = user_has_permission('pos.void') || user_has_permission('pos.manage');
    if (!$canManage) {
      http_response_code(403);
      ob_end_clean();
      echo json_encode(['success' => false, 'message' => 'Insufficient permissions']);
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

  // Get JSON input
  $input = json_decode(file_get_contents('php://input'), true);
  $returnId = (int)($input['return_id'] ?? 0);
  
  if ($returnId <= 0) {
    ob_end_clean();
    echo json_encode(['success' => false, 'message' => 'Return ID is required']);
    exit;
  }

  // Check if sale_returns table exists
  $hasReturnsTable = $db->query("SHOW TABLES LIKE 'sale\\_returns'")?->num_rows ?? 0;
  if (!$hasReturnsTable) {
    ob_end_clean();
    echo json_encode(['success' => false, 'message' => 'sale_returns table not found']);
    exit;
  }

  // Get current return data
  $getCurrent = $db->prepare("SELECT * FROM sale_returns WHERE id = ?");
  $getCurrent->bind_param('i', $returnId);
  $getCurrent->execute();
  $currentReturn = $getCurrent->get_result()->fetch_assoc();
  $getCurrent->close();

  if (!$currentReturn) {
    ob_end_clean();
    echo json_encode(['success' => false, 'message' => 'Return not found']);
    exit;
  }

  // Check if return can be deleted (only pending returns)
  $currentStatus = $currentReturn['status'] ?? '';
  // Treat empty status and 'n/a' as pending
  if ($currentStatus === '' || $currentStatus === 'n/a') {
    $currentStatus = 'pending';
  }
  if ($currentStatus !== 'pending') {
    ob_end_clean();
    echo json_encode(['success' => false, 'message' => 'Cannot delete return in ' . $currentStatus . ' status. Only pending returns can be deleted.']);
    exit;
  }

  // Start transaction
  $db->begin_transaction();

  try {
    // Reverse stock updates if return was completed
    if ($currentStatus === 'completed' && ($currentReturn['selling_location_id'] ?? 0) > 0) {
      $locationId = (int)($currentReturn['selling_location_id'] ?? 0);
      $saleId = (int)($currentReturn['sale_id'] ?? 0);
      
      // Get return items to reverse stock
      $hasReturnItems = $db->query("SHOW TABLES LIKE 'return\\_items'")?->num_rows ?? 0;
      if ($hasReturnItems) {
        $getItems = $db->prepare("SELECT product_id, quantity FROM return_items WHERE return_id = ?");
        $getItems->bind_param('i', $returnId);
        $getItems->execute();
        $itemsResult = $getItems->get_result();
        
        while ($item = $itemsResult->fetch_assoc()) {
          reverseStockForReturn($db, (int)$item['product_id'], (float)$item['quantity'], $locationId, $saleId);
        }
        $itemsResult->free();
        $getItems->close();
      }
    }

    // Delete return items first (if table exists)
    $hasReturnItems = $db->query("SHOW TABLES LIKE 'return\\_items'")?->num_rows ?? 0;
    if ($hasReturnItems) {
      $deleteItems = $db->prepare("DELETE FROM return_items WHERE return_id = ?");
      $deleteItems->bind_param('i', $returnId);
      $deleteItems->execute();
      $deleteItems->close();
    }

    // Delete the main return record
    $deleteReturn = $db->prepare("DELETE FROM sale_returns WHERE id = ?");
    $deleteReturn->bind_param('i', $returnId);
    $deleteReturn->execute();
    $deleteReturn->close();

    $db->commit();

    // Clean output buffer and send JSON response
    ob_end_clean();
    echo json_encode([
      'success' => true, 
      'message' => 'Return deleted successfully',
      'return_id' => $returnId
    ]);

  } catch (Exception $e) {
    $db->rollback();
    throw $e;
  }

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

// Stock reversal function
function reverseStockForReturn($db, $productId, $quantity, $locationId, $saleId) {
  try {
    // Check if stock_by_location table exists
    $hasStockTable = $db->query("SHOW TABLES LIKE 'stock\\_by\\_location'")?->num_rows ?? 0;
    
    if ($hasStockTable) {
      // Update existing stock (subtract the returned quantity)
      $updateStock = $db->prepare("UPDATE stock_by_location SET qty_base = GREATEST(0, qty_base - ?) WHERE product_id = ? AND location_id = ? AND qty_base >= ?");
      $updateStock->bind_param('diii', $quantity, $productId, $locationId, $quantity);
      $result = $updateStock->execute();
      $updateStock->close();
      
      // If update failed (not enough stock), don't allow negative stock
      if (!$result || $updateStock->affected_rows === 0) {
        error_log("Failed to reverse stock for product $productId, location $locationId - insufficient stock");
        return false;
      }
    }
    
    // Log the stock movement if stock_movements table exists
    $hasMovementsTable = $db->query("SHOW TABLES LIKE 'stock\\_movements'")?->num_rows ?? 0;
    if ($hasMovementsTable) {
      $insertMovement = $db->prepare("INSERT INTO stock_movements (product_id, location_id, quantity, movement_type, reference_type, reference_id, notes, created_by, created_at) VALUES (?, ?, ?, 'return_out', 'sale_return_delete', ?, 'Stock reversed from deleted return', ?, NOW())");
      if ($insertMovement) {
        $createdBy = $_SESSION['user']['id'] ?? 0;
        $insertMovement->bind_param('iiiiii', $productId, $locationId, $quantity, $saleId, $createdBy);
        $insertMovement->execute();
        $insertMovement->close();
      }
    }
    
    return true;
  } catch (Exception $e) {
    error_log("Stock reversal error: " . $e->getMessage());
    return false;
  }
}
?>
