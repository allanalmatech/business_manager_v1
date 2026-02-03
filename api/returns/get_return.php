<?php
// api/returns/get_return.php
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

  $db = $GLOBALS['db'] ?? null;
  if (!$db instanceof mysqli) {
    http_response_code(500);
    ob_end_clean();
    echo json_encode(['success' => false, 'message' => 'DB not available']);
    exit;
  }

  $returnId = (int)($_GET['id'] ?? 0);
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

  // Get return details
  $sql = "SELECT sr.*, s.doc_no as sale_doc_no, s.doc_type as sale_doc_type
          FROM sale_returns sr
          LEFT JOIN sales s ON s.id = sr.sale_id
          WHERE sr.id = ? LIMIT 1";
  
  $st = $db->prepare($sql);
  $st->bind_param('i', $returnId);
  $st->execute();
  $result = $st->get_result();
  $return = $result->fetch_assoc();
  $result->free();
  $st->close();

  if (!$return) {
    ob_end_clean();
    echo json_encode(['success' => false, 'message' => 'Return not found']);
    exit;
  }

  // Get return items if table exists
  $items = [];
  $hasReturnItems = $db->query("SHOW TABLES LIKE 'return\\_items'")?->num_rows ?? 0;
  if ($hasReturnItems) {
    $sqlItems = "SELECT ri.*, p.name as product_name
                 FROM return_items ri
                 LEFT JOIN products p ON p.id = ri.product_id
                 WHERE ri.return_id = ?
                 ORDER BY ri.id ASC";
    
    $st2 = $db->prepare($sqlItems);
    $st2->bind_param('i', $returnId);
    $st2->execute();
    $rs = $st2->get_result();
    
    while ($item = $rs->fetch_assoc()) {
      $items[] = [
        'id' => (int)$item['id'],
        'product_id' => (int)$item['product_id'],
        'product_name' => (string)($item['product_name'] ?? ''),
        'quantity' => (float)$item['quantity'],
        'unit_price' => (float)$item['unit_price'],
        'total' => (float)$item['total']
      ];
    }
    $rs->free();
    $st2->close();
  }

  // Clean output buffer and send JSON response
  ob_end_clean();
  echo json_encode([
    'success' => true,
    'return' => [
      'id' => (int)$return['id'],
      'sale_id' => (int)$return['sale_id'],
      'return_no' => (string)($return['return_no'] ?? ''),
      'reason' => (string)($return['reason'] ?? ''),
      'refund_amount' => (float)($return['refund_amount'] ?? 0),
      'status' => (string)($return['status'] ?? ''),
      'selling_location_id' => (int)($return['selling_location_id'] ?? 0),
      'created_at' => (string)($return['created_at'] ?? ''),
      'refunded' => (int)($return['refunded'] ?? 0),
      'sale_doc_no' => (string)($return['sale_doc_no'] ?? ''),
      'sale_doc_type' => (string)($return['sale_doc_type'] ?? ''),
      'items' => $items
    ]
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
