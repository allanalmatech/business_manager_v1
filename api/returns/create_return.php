<?php
// api/returns/create_return.php
declare(strict_types=1);

header('Content-Type: application/json');

require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/auth.php';

if (session_status() === PHP_SESSION_NONE) session_start();

function json_out(array $data, int $code = 200): void {
  http_response_code($code);
  echo json_encode($data);
  exit;
}

function table_exists(mysqli $db, string $table): bool {
  $r = $db->query("SHOW TABLES LIKE '" . $db->real_escape_string($table) . "'");
  return $r && $r->num_rows > 0;
}

function column_exists(mysqli $db, string $table, string $col): bool {
  $sql = "SELECT 1
          FROM INFORMATION_SCHEMA.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE()
            AND TABLE_NAME = ?
            AND COLUMN_NAME = ?
          LIMIT 1";
  $st = $db->prepare($sql);
  if (!$st) return false;
  $st->bind_param('ss', $table, $col);
  $st->execute();
  $ok = (bool)$st->get_result()->fetch_row();
  $st->close();
  return $ok;
}

// Stock management function for returns
function updateStockForReturn(mysqli $db, int $productId, float $quantity, int $locationId, int $saleId): void {
  try {
    $hasStockTable = table_exists($db, 'stock_by_location');
    if ($hasStockTable) {
      $checkStock = $db->prepare("SELECT qty_base FROM stock_by_location WHERE product_id = ? AND location_id = ?");
      $checkStock->bind_param('ii', $productId, $locationId);
      $checkStock->execute();
      $stockData = $checkStock->get_result()->fetch_assoc();
      $checkStock->close();

      if ($stockData) {
        $updateStock = $db->prepare("UPDATE stock_by_location SET qty_base = qty_base + ? WHERE product_id = ? AND location_id = ?");
        $updateStock->bind_param('dii', $quantity, $productId, $locationId);
        $updateStock->execute();
        $updateStock->close();
      } else {
        $insertStock = $db->prepare("INSERT INTO stock_by_location (product_id, location_id, qty_base, low_level_base) VALUES (?, ?, ?, 0)");
        $insertStock->bind_param('iid', $productId, $locationId, $quantity);
        $insertStock->execute();
        $insertStock->close();
      }
    }

    $hasMovementsTable = table_exists($db, 'stock_movements');
    if ($hasMovementsTable) {
      $insertMovement = $db->prepare("
        INSERT INTO stock_movements
          (product_id, location_id, quantity, movement_type, reference_type, reference_id, notes, created_by, created_at)
        VALUES
          (?, ?, ?, 'return_in', 'sale_return', ?, 'Stock added from return', ?, NOW())
      ");
      if ($insertMovement) {
        $createdBy = (int)($_SESSION['user']['id'] ?? 0);
        $insertMovement->bind_param('iidii', $productId, $locationId, $quantity, $saleId, $createdBy);
        $insertMovement->execute();
        $insertMovement->close();
      }
    }
  } catch (Throwable $e) {
    error_log("Stock update error: " . $e->getMessage());
  }
}

// Auth
if (empty($_SESSION['user']['id'])) {
  json_out(['success' => false, 'message' => 'Authentication required'], 401);
}

// Permission check
if (function_exists('user_has_permission')) {
  $canManage = user_has_permission('pos.void') || user_has_permission('pos.manage');
  if (!$canManage) json_out(['success' => false, 'message' => 'Insufficient permissions'], 403);
}

$db = $GLOBALS['db'] ?? null;
if (!$db instanceof mysqli) {
  json_out(['success' => false, 'message' => 'Database not available'], 500);
}

// Input
$receiptNo    = trim((string)($_POST['receipt_no'] ?? ''));
$saleId       = (int)($_POST['sale_id'] ?? 0);
$reason       = trim((string)($_POST['reason'] ?? ''));
$refundAmount = (float)($_POST['refund_amount'] ?? 0);
$status       = trim((string)($_POST['status'] ?? 'pending'));
$locationId   = (int)($_POST['selling_location_id'] ?? 0);
$returnDate   = trim((string)($_POST['return_date'] ?? date('Y-m-d')));
$refunded     = (int)($_POST['refunded'] ?? 0); // 1/0
$returnItems  = json_decode((string)($_POST['return_items'] ?? '[]'), true);

// Basic validation
if ($receiptNo === '' && $saleId <= 0) json_out(['success' => false, 'message' => 'Receipt number is required']);
if ($reason === '') json_out(['success' => false, 'message' => 'Return reason is required']);
if ($status === '') json_out(['success' => false, 'message' => 'Return status is required']);
if ($locationId <= 0) json_out(['success' => false, 'message' => 'Location is required']);
if (empty($returnItems) || !is_array($returnItems)) json_out(['success' => false, 'message' => 'No items selected for return']);

// Resolve saleId via receipt
if ($saleId <= 0 && $receiptNo !== '') {
  $st = $db->prepare("SELECT id FROM sales WHERE doc_no = ? LIMIT 1");
  if (!$st) json_out(['success' => false, 'message' => 'Query preparation failed'], 500);
  $st->bind_param('s', $receiptNo);
  $st->execute();
  $saleRow = $st->get_result()->fetch_assoc();
  $st->close();
  if (!$saleRow) json_out(['success' => false, 'message' => 'Receipt not found']);
  $saleId = (int)$saleRow['id'];
}

// Verify sale exists + date validation
$saleCheck = $db->prepare("SELECT id, created_at FROM sales WHERE id = ? LIMIT 1");
$saleCheck->bind_param('i', $saleId);
$saleCheck->execute();
$saleData = $saleCheck->get_result()->fetch_assoc();
$saleCheck->close();

if (!$saleData) json_out(['success' => false, 'message' => 'Sale not found']);

$saleDate = substr((string)$saleData['created_at'], 0, 10);
$today = date('Y-m-d');

if ($returnDate > $today) json_out(['success' => false, 'message' => 'Return date cannot be in the future']);
if ($returnDate < $saleDate) json_out(['success' => false, 'message' => 'Return date cannot be before the sale date']);

// Return No
$returnNo = 'RET-' . date('Y') . '-' . str_pad((string)$saleId, 6, '0', STR_PAD_LEFT) . '-' . substr(bin2hex(random_bytes(2)), 0, 4);

// Ensure DATETIME
$createdAt = $returnDate . ' 00:00:00';

// Check tables
$hasReturnsTable = table_exists($db, 'sale_returns');
$hasItemsTable   = table_exists($db, 'return_items');

if (!$hasReturnsTable) {
  json_out(['success' => false, 'message' => 'sale_returns table missing. Create it first.'], 500);
}

try {
  $db->begin_transaction();

  // Detect refunded column exists (your table might not have it yet)
  $hasRefundedCol = column_exists($db, 'sale_returns', 'refunded');

  // Insert return header
  if ($hasRefundedCol) {
    $sql = "INSERT INTO sale_returns
      (sale_id, return_no, reason, refund_amount, status, selling_location_id, created_at, created_by, refunded)
      VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)";
    $st = $db->prepare($sql);
    $createdBy = (int)$_SESSION['user']['id'];
    $st->bind_param('issdsisii', $saleId, $returnNo, $reason, $refundAmount, $status, $locationId, $createdAt, $createdBy, $refunded);
  } else {
    $sql = "INSERT INTO sale_returns
      (sale_id, return_no, reason, refund_amount, status, selling_location_id, created_at, created_by)
      VALUES (?, ?, ?, ?, ?, ?, ?, ?)";
    $st = $db->prepare($sql);
    $createdBy = (int)$_SESSION['user']['id'];
    $st->bind_param('issdsisi', $saleId, $returnNo, $reason, $refundAmount, $status, $locationId, $createdAt, $createdBy);
  }

  if (!$st || !$st->execute()) {
    throw new Exception('Failed to insert return: ' . $db->error);
  }
  $returnId = (int)$db->insert_id;
  $st?->close();

  // Insert items
  if ($hasItemsTable) {
    $hasItemLoc = column_exists($db, 'return_items', 'location_id');

    // prepare insert with/without location_id
    if ($hasItemLoc) {
      $ins = $db->prepare("INSERT INTO return_items (return_id, product_id, location_id, quantity, unit_price, total)
                           VALUES (?, ?, ?, ?, ?, ?)");
    } else {
      $ins = $db->prepare("INSERT INTO return_items (return_id, product_id, quantity, unit_price, total)
                           VALUES (?, ?, ?, ?, ?)");
    }
    if (!$ins) throw new Exception('Prepare return_items failed: ' . $db->error);

    foreach ($returnItems as $productId => $qty) {
      $productId = (int)$productId;
      $qty = (float)$qty;
      if ($qty <= 0) continue;

      // unit price from sale_items
      $pc = $db->prepare("SELECT unit_price FROM sale_items WHERE sale_id = ? AND product_id = ? LIMIT 1");
      $pc->bind_param('ii', $saleId, $productId);
      $pc->execute();
      $pd = $pc->get_result()->fetch_assoc();
      $pc->close();

      if (!$pd) continue;

      $unitPrice = (float)$pd['unit_price'];
      $total = $unitPrice * $qty;

      if ($hasItemLoc) {
        $ins->bind_param('iiiddd', $returnId, $productId, $locationId, $qty, $unitPrice, $total);
      } else {
        $ins->bind_param('iiddd', $returnId, $productId, $qty, $unitPrice, $total);
      }

      if (!$ins->execute()) {
        throw new Exception('Failed to insert return item: ' . $db->error);
      }

      // Stock update only if completed
      if ($status === 'completed') {
        updateStockForReturn($db, $productId, $qty, $locationId, $saleId);
      }
    }

    $ins->close();
  }

  $db->commit();

  json_out([
    'success' => true,
    'message' => 'Return created successfully',
    'return_id' => $returnId,
    'return_no' => $returnNo
  ]);

} catch (Throwable $e) {
  $db->rollback();
  json_out(['success' => false, 'message' => $e->getMessage()], 500);
}