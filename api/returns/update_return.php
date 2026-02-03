<?php
// api/returns/update_return.php
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

// Stock update (same behavior as create_return.php)
function updateStockForReturn(mysqli $db, int $productId, float $quantity, int $locationId, int $saleId): void {
  try {
    if (table_exists($db, 'stock_by_location')) {
      $check = $db->prepare("SELECT qty_base FROM stock_by_location WHERE product_id = ? AND location_id = ?");
      $check->bind_param('ii', $productId, $locationId);
      $check->execute();
      $row = $check->get_result()->fetch_assoc();
      $check->close();

      if ($row) {
        $up = $db->prepare("UPDATE stock_by_location SET qty_base = qty_base + ? WHERE product_id = ? AND location_id = ?");
        $up->bind_param('dii', $quantity, $productId, $locationId);
        $up->execute();
        $up->close();
      } else {
        $ins = $db->prepare("INSERT INTO stock_by_location (product_id, location_id, qty_base, low_level_base) VALUES (?, ?, ?, 0)");
        $ins->bind_param('iid', $productId, $locationId, $quantity);
        $ins->execute();
        $ins->close();
      }
    }

    if (table_exists($db, 'stock_movements')) {
      $mv = $db->prepare("
        INSERT INTO stock_movements
          (product_id, location_id, quantity, movement_type, reference_type, reference_id, notes, created_by, created_at)
        VALUES
          (?, ?, ?, 'return_in', 'sale_return', ?, 'Stock added from return', ?, NOW())
      ");
      if ($mv) {
        $createdBy = (int)($_SESSION['user']['id'] ?? 0);
        $mv->bind_param('iidii', $productId, $locationId, $quantity, $saleId, $createdBy);
        $mv->execute();
        $mv->close();
      }
    }
  } catch (Throwable $e) {
    error_log("Stock update error: " . $e->getMessage());
  }
}

// -------------------- AUTH --------------------
if (empty($_SESSION['user']['id'])) {
  json_out(['success' => false, 'message' => 'Authentication required'], 401);
}

if (function_exists('user_has_permission')) {
  $canManage = user_has_permission('pos.void') || user_has_permission('pos.manage');
  if (!$canManage) json_out(['success' => false, 'message' => 'Insufficient permissions'], 403);
}

$db = $GLOBALS['db'] ?? null;
if (!$db instanceof mysqli) {
  json_out(['success' => false, 'message' => 'Database not available'], 500);
}

if (!table_exists($db, 'sale_returns')) {
  json_out(['success' => false, 'message' => 'sale_returns table not found'], 500);
}

// -------------------- INPUT --------------------
$returnId = (int)($_POST['return_id'] ?? 0);
if ($returnId <= 0) json_out(['success' => false, 'message' => 'Return ID is required'], 400);

$reason       = trim((string)($_POST['reason'] ?? ''));
$status       = trim((string)($_POST['status'] ?? 'pending'));
$refundAmount = (float)($_POST['refund_amount'] ?? 0);
$refunded     = (int)($_POST['refunded'] ?? 0);
$locationId   = (int)($_POST['selling_location_id'] ?? 0);
$returnDate   = trim((string)($_POST['return_date'] ?? ''));

// Validate required
if ($reason === '') json_out(['success' => false, 'message' => 'Return reason is required'], 400);
if ($status === '') json_out(['success' => false, 'message' => 'Return status is required'], 400);
if ($locationId <= 0) json_out(['success' => false, 'message' => 'Location is required'], 400);

$allowed = ['pending','approved','completed','rejected','cancelled'];
if (!in_array($status, $allowed, true)) {
  json_out(['success' => false, 'message' => 'Invalid status'], 400);
}

// Validate date if provided
if ($returnDate !== '') {
  $dt = DateTime::createFromFormat('Y-m-d', $returnDate);
  if (!$dt || $dt->format('Y-m-d') !== $returnDate) {
    json_out(['success' => false, 'message' => 'Invalid return date format'], 400);
  }
  $today = new DateTime();
  $today->setTime(0, 0, 0); // Set to midnight for fair comparison
  if ($dt > $today) {
    json_out(['success' => false, 'message' => 'Return date cannot be in the future'], 400);
  }
}

// -------------------- CURRENT RETURN --------------------
$get = $db->prepare("SELECT id, sale_id, status FROM sale_returns WHERE id = ? LIMIT 1");
$get->bind_param('i', $returnId);
$get->execute();
$current = $get->get_result()->fetch_assoc();
$get->close();

if (!$current) json_out(['success' => false, 'message' => 'Return not found'], 404);

$currentStatus = (string)($current['status'] ?? '');
if ($currentStatus === '' || $currentStatus === 'n/a') $currentStatus = 'pending';

// only pending/approved editable
if (!in_array($currentStatus, ['pending','approved'], true)) {
  json_out(['success' => false, 'message' => "Cannot edit return in {$currentStatus} status"], 400);
}

$oldStatus = $currentStatus;
$newStatus = $status;

// -------------------- UPDATE --------------------
$hasRefundedCol = column_exists($db, 'sale_returns', 'refunded');

try {
  $db->begin_transaction();

  $sql = "UPDATE sale_returns SET reason = ?, status = ?, refund_amount = ?, selling_location_id = ?";
  $types = "ssdi";
  $params = [$reason, $status, $refundAmount, $locationId];

  if ($hasRefundedCol) {
    $sql .= ", refunded = ?";
    $types .= "i";
    $params[] = $refunded;
  }

  if ($returnDate !== '') {
    $sql .= ", created_at = ?";
    $types .= "s";
    $params[] = $returnDate . " 00:00:00";
  }

  $sql .= " WHERE id = ?";
  $types .= "i";
  $params[] = $returnId;

  $st = $db->prepare($sql);
  if (!$st) throw new Exception("Prepare failed: " . $db->error);

  $st->bind_param($types, ...$params);

  if (!$st->execute()) {
    throw new Exception("Failed to update return: " . $db->error);
  }
  $st->close();

  // If status becomes completed, update stock once
  if ($oldStatus !== 'completed' && $newStatus === 'completed' && $locationId > 0) {
    if (table_exists($db, 'return_items')) {
      $it = $db->prepare("SELECT product_id, quantity FROM return_items WHERE return_id = ?");
      $it->bind_param('i', $returnId);
      $it->execute();
      $rs = $it->get_result();

      $saleId = (int)($current['sale_id'] ?? 0);

      while ($row = $rs->fetch_assoc()) {
        updateStockForReturn(
          $db,
          (int)$row['product_id'],
          (float)$row['quantity'],
          $locationId,
          $saleId
        );
      }
      $it->close();
    }
  }

  $db->commit();

  json_out(['success' => true, 'message' => 'Return updated successfully', 'return_id' => $returnId]);

} catch (Throwable $e) {
  $db->rollback();
  json_out(['success' => false, 'message' => 'Caught exception: ' . $e->getMessage()], 500);
}