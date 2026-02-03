<?php
// api/sales/get_sale_details.php
declare(strict_types=1);
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

  $receiptNo = trim((string)($_GET['receipt_no'] ?? ''));
  $saleId = (int)($_GET['sale_id'] ?? 0);

  if ($receiptNo === '' && $saleId <= 0) {
    ob_end_clean();
    echo json_encode(['success' => false, 'message' => 'receipt_no or sale_id is required']);
    exit;
  }

  $hasCustomers = ($db->query("SHOW TABLES LIKE 'customers'")?->num_rows ?? 0) > 0;
  $join = $hasCustomers ? "LEFT JOIN customers c ON c.id = s.customer_id" : "";
  $selCustomer = $hasCustomers ? "c.name AS customer_name" : "NULL AS customer_name";

  if ($saleId > 0) {
    $sqlSale = "SELECT s.*, $selCustomer
                FROM sales s
                $join
                WHERE s.id = ? LIMIT 1";
    $st = $db->prepare($sqlSale);
    $st->bind_param('i', $saleId);
  } else {
    $sqlSale = "SELECT s.*, $selCustomer
                FROM sales s
                $join
                WHERE s.doc_no = ? LIMIT 1";
    $st = $db->prepare($sqlSale);
    $st->bind_param('s', $receiptNo);
  }

  $st->execute();
  $result = $st->get_result();
  $sale = $result->fetch_assoc();
  $result->free();
  $st->close();

  if (!$sale) {
    ob_end_clean();
    echo json_encode(['success' => false, 'message' => 'Sale not found']);
    exit;
  }

  $saleId = (int)$sale['id'];

  // sale_items required
  $hasSaleItems = ($db->query("SHOW TABLES LIKE 'sale\\_items'")?->num_rows ?? 0) > 0;
  if (!$hasSaleItems) {
    ob_end_clean();
    echo json_encode(['success' => false, 'message' => 'sale_items table missing']);
    exit;
  }

  // optional products table for names
  $hasProducts = ($db->query("SHOW TABLES LIKE 'products'")?->num_rows ?? 0) > 0;
  $joinP = $hasProducts ? "LEFT JOIN products p ON p.id = si.product_id" : "";
  $selP = $hasProducts ? "p.name AS product_name" : "NULL AS product_name";

  // optional return_items to calculate already returned qty
  $hasReturnItems = ($db->query("SHOW TABLES LIKE 'return\\_items'")?->num_rows ?? 0) > 0;
  $hasSaleReturns = ($db->query("SHOW TABLES LIKE 'sale\\_returns'")?->num_rows ?? 0) > 0;

  $returnedExpr = "0 AS returned_quantity";
  $joinReturned = "";
  if ($hasReturnItems && $hasSaleReturns) {
    $returnedExpr = "COALESCE(rq.returned_qty,0) AS returned_quantity";
    $joinReturned = "
      LEFT JOIN (
        SELECT ri.product_id, SUM(ri.quantity) AS returned_qty
        FROM return_items ri
        INNER JOIN sale_returns sr ON sr.id = ri.return_id
        WHERE sr.sale_id = ?
        GROUP BY ri.product_id
      ) rq ON rq.product_id = si.product_id
    ";
  }

  $sqlItems = "SELECT
                si.product_id,
                $selP,
                si.qty_base as quantity,
                si.unit_price,
                si.line_total as total,
                $returnedExpr
              FROM sale_items si
              $joinP
              $joinReturned
              WHERE si.sale_id = ?
              ORDER BY si.id ASC";

  $st2 = $db->prepare($sqlItems);

  if ($hasReturnItems && $hasSaleReturns) {
    $st2->bind_param('ii', $saleId, $saleId);
  } else {
    $st2->bind_param('i', $saleId);
  }

  $st2->execute();
  $rs = $st2->get_result();

  $items = [];
  while ($it = $rs->fetch_assoc()) {
    $sold = (float)$it['quantity'];
    $returned = (float)$it['returned_quantity'];
    $max = max(0, $sold - $returned);

    $items[] = [
      'product_id' => (int)$it['product_id'],
      'product_name' => (string)($it['product_name'] ?? ''),
      'quantity' => (float)$sold,
      'unit_price' => (float)$it['unit_price'],
      'returned_quantity' => (float)$returned,
      'max_return_qty' => (float)$max,
    ];
  }
  $rs->free();
  $st2->close();

  // Clean output buffer and send JSON response
  ob_end_clean();
  echo json_encode([
    'success' => true,
    'sale' => [
      'id' => (int)$sale['id'],
      'doc_no' => (string)$sale['doc_no'],
      'created_at' => (string)$sale['created_at'],
      'grand_total' => (float)$sale['grand_total'],
      'status' => (string)($sale['status'] ?? ''),
      'payment_status' => (string)($sale['payment_status'] ?? ''),
      'customer_name' => (string)($sale['customer_name'] ?? ''),
    ],
    'items' => $items
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