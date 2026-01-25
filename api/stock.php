<?php
// api/stock.php
declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/rbac.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/audit.php';

header('Content-Type: application/json; charset=utf-8');

require_login();
require_permission('products.view');

$db = $GLOBALS['db'];
$action = $_GET['action'] ?? '';

function ok($data=[]){ echo json_encode(['ok'=>true,'data'=>$data]); exit; }
function err($m,$c=400){ http_response_code($c); echo json_encode(['ok'=>false,'error'=>$m]); exit; }

if (!$db instanceof mysqli) err("DB not available", 500);

if ($action === 'locations') {
  $res = $db->query("SELECT id, name FROM locations WHERE is_active=1 ORDER BY name ASC");
  $rows = [];
  while ($r = $res->fetch_assoc()) $rows[] = $r;
  ok($rows);
}

if ($action === 'stock_locations') {
  $pid = (int)($_GET['product_id'] ?? 0);
  if ($pid <= 0) err("Invalid product");

  $stmt = $db->prepare("
    SELECT
      l.id AS location_id,
      l.name AS location_name,
      COALESCE(s.qty_base,0) AS qty_base
    FROM locations l
    LEFT JOIN stock_by_location s ON s.location_id = l.id AND s.product_id = ?
    WHERE l.is_active = 1
    ORDER BY l.name ASC
  ");
  $stmt->bind_param("i", $pid);
  $stmt->execute();
  $res = $stmt->get_result();
  $rows = [];
  while ($r = $res->fetch_assoc()) $rows[] = $r;
  $stmt->close();
  ok($rows);
}

if ($action === 'stock_by_location') {
  $pid = (int)($_GET['product_id'] ?? 0);
  $lid = (int)($_GET['location_id'] ?? 0);
  if ($pid <= 0 || $lid <= 0) err("Invalid product or location");

  $stmt = $db->prepare("SELECT qty_base FROM stock_by_location WHERE product_id=? AND location_id=? LIMIT 1");
  $stmt->bind_param("ii", $pid, $lid);
  $stmt->execute();
  $row = $stmt->get_result()->fetch_assoc();
  $stmt->close();

  $qty = (float)($row['qty_base'] ?? 0);
  ok(['product_id'=>$pid, 'location_id'=>$lid, 'qty_base'=>$qty]);
}

if ($action === 'movements') {
  $pid = (int)($_GET['product_id'] ?? 0);
  if ($pid <= 0) err("Invalid product");

  $limit = min(200, max(10, (int)($_GET['limit'] ?? 50)));

  $stmt = $db->prepare("
    SELECT sm.*, 
           lf.name AS from_loc, lt.name AS to_loc,
           u.username, u.full_name
    FROM stock_movements sm
    LEFT JOIN locations lf ON lf.id = sm.from_location_id
    LEFT JOIN locations lt ON lt.id = sm.to_location_id
    LEFT JOIN users u ON u.id = sm.created_by
    WHERE sm.product_id = ?
    ORDER BY sm.id DESC
    LIMIT {$limit}
  ");
  $stmt->bind_param("i", $pid);
  $stmt->execute();
  $res = $stmt->get_result();
  $rows = [];
  while ($r = $res->fetch_assoc()) $rows[] = $r;
  $stmt->close();
  ok($rows);
}

if ($action === 'transfer') {
  require_permission('products.update'); // or create a dedicated permission later: products.transfer

  $raw = json_decode((string)file_get_contents('php://input'), true);
  if (!is_array($raw)) err("Invalid JSON");

  $product_id = (int)($raw['product_id'] ?? 0);
  $from_id    = (int)($raw['from_location_id'] ?? 0);
  $to_id      = (int)($raw['to_location_id'] ?? 0);
  $qty        = (float)($raw['qty_base'] ?? 0);
  $note       = trim((string)($raw['note'] ?? ''));

  if ($product_id <= 0 || $from_id <= 0 || $to_id <= 0) err("Invalid transfer data");
  if ($from_id === $to_id) err("From and To locations must differ");
  if ($qty <= 0) err("Quantity must be > 0");

  $uid = (int)($_SESSION['user']['id'] ?? 0);

  $db->begin_transaction();

  try {
    // Ensure rows exist
    $db->query("INSERT IGNORE INTO stock_by_location (product_id, location_id, qty_base, low_level_base)
                VALUES ($product_id, $from_id, 0, 0), ($product_id, $to_id, 0, 0)");

    // Get current from qty
    $stmt = $db->prepare("SELECT qty_base FROM stock_by_location WHERE product_id=? AND location_id=? FOR UPDATE");
    $stmt->bind_param("ii", $product_id, $from_id);
    $stmt->execute();
    $fromQty = (float)($stmt->get_result()->fetch_assoc()['qty_base'] ?? 0);
    $stmt->close();

    if ($fromQty < $qty) throw new Exception("Not enough stock in source location");

    // Get to qty
    $stmt = $db->prepare("SELECT qty_base FROM stock_by_location WHERE product_id=? AND location_id=? FOR UPDATE");
    $stmt->bind_param("ii", $product_id, $to_id);
    $stmt->execute();
    $toQty = (float)($stmt->get_result()->fetch_assoc()['qty_base'] ?? 0);
    $stmt->close();

    $fromAfter = $fromQty - $qty;
    $toAfter   = $toQty + $qty;

    // Update stocks
    $stmt = $db->prepare("UPDATE stock_by_location SET qty_base=? WHERE product_id=? AND location_id=?");
    $stmt->bind_param("dii", $fromAfter, $product_id, $from_id);
    $stmt->execute();
    $stmt->close();

    $stmt = $db->prepare("UPDATE stock_by_location SET qty_base=? WHERE product_id=? AND location_id=?");
    $stmt->bind_param("dii", $toAfter, $product_id, $to_id);
    $stmt->execute();
    $stmt->close();

    // Insert movement ledger row (transfer)
    $before = $fromQty;      // before at source
    $change = -$qty;         // leaves source
    $after  = $fromAfter;

    $stmt = $db->prepare("
      INSERT INTO stock_movements
      (product_id, from_location_id, to_location_id, movement_type, qty_change, qty_before, qty_after,
       reference_type, reference_id, note, created_by)
      VALUES (?,?,?,'transfer',?,?,?, 'transfer', NULL, ?, ?)
    ");
    $stmt->bind_param("iiidddsi", $product_id, $from_id, $to_id, $change, $before, $after, $note, $uid);
    $stmt->execute();
    $mid = (int)$stmt->insert_id;
    $stmt->close();

    audit_log('stock.transfer', 'product', (string)$product_id, "Transfer #$mid from $from_id to $to_id qty $qty");

    $db->commit();
    ok(['movement_id'=>$mid, 'from_after'=>$fromAfter, 'to_after'=>$toAfter]);
  } catch (Throwable $e) {
    $db->rollback();
    err($e->getMessage(), 400);
  }
}
if ($action === 'movement_get') {
  $id = (int)($_GET['id'] ?? 0);
  if ($id <= 0) err("Invalid movement id");

  $stmt = $db->prepare("
    SELECT sm.*,
           lf.name AS from_loc, lt.name AS to_loc,
           p.sku, p.name AS product_name,
           u.username, u.full_name
    FROM stock_movements sm
    JOIN products p ON p.id = sm.product_id
    LEFT JOIN locations lf ON lf.id = sm.from_location_id
    LEFT JOIN locations lt ON lt.id = sm.to_location_id
    LEFT JOIN users u ON u.id = sm.created_by
    WHERE sm.id = ?
    LIMIT 1
  ");
  $stmt->bind_param("i", $id);
  $stmt->execute();
  $row = $stmt->get_result()->fetch_assoc();
  $stmt->close();

  if (!$row) err("Not found", 404);
  ok($row);
}

if ($action === 'stock_in') {
  require_permission('products.update'); // or make a dedicated permission: products.stock_in

  $raw = json_decode((string)file_get_contents('php://input'), true);
  if (!is_array($raw)) err("Invalid JSON");

  $product_id  = (int)($raw['product_id'] ?? 0);
  $to_location = (int)($raw['to_location_id'] ?? 0);
  $qty         = (float)($raw['qty_base'] ?? 0);
  $note        = trim((string)($raw['note'] ?? ''));
  $refType     = trim((string)($raw['reference_type'] ?? 'stock_in'));
  $refId       = trim((string)($raw['reference_id'] ?? ''));

  if ($product_id <= 0) err("Invalid product");
  if ($to_location <= 0) err("Select destination location");
  if ($qty <= 0) err("Quantity must be > 0");

  $uid = (int)($_SESSION['user']['id'] ?? 0);

  $db->begin_transaction();
  try {
    // ensure stock row exists
    $stmt = $db->prepare("INSERT IGNORE INTO stock_by_location (product_id, location_id, qty_base, low_level_base)
                          VALUES (?,?,0,0)");
    $stmt->bind_param("ii", $product_id, $to_location);
    $stmt->execute();
    $stmt->close();

    // lock row
    $stmt = $db->prepare("SELECT qty_base FROM stock_by_location WHERE product_id=? AND location_id=? FOR UPDATE");
    $stmt->bind_param("ii", $product_id, $to_location);
    $stmt->execute();
    $before = (float)($stmt->get_result()->fetch_assoc()['qty_base'] ?? 0);
    $stmt->close();

    $after = $before + $qty;

    // update
    $stmt = $db->prepare("UPDATE stock_by_location SET qty_base=? WHERE product_id=? AND location_id=?");
    $stmt->bind_param("dii", $after, $product_id, $to_location);
    $stmt->execute();
    $stmt->close();

    // ledger entry
    $change = +$qty;
    $stmt = $db->prepare("
      INSERT INTO stock_movements
      (product_id, from_location_id, to_location_id, movement_type,
       qty_change, qty_before, qty_after, reference_type, reference_id, note, created_by)
      VALUES (?, NULL, ?, 'stock_in', ?, ?, ?, ?, ?, ?, ?)
    ");
    $stmt->bind_param("idddsssi",
      $product_id,
      $to_location,
      $change,
      $before,
      $after,
      $refType,
      $refId,
      $note,
      $uid
    );
    $stmt->execute();
    $mid = (int)$stmt->insert_id;
    $stmt->close();

    audit_log('stock.stock_in', 'product', (string)$product_id, "Stock In #$mid loc:$to_location qty:$qty");

    $db->commit();
    ok(['movement_id'=>$mid, 'after'=>$after]);
  } catch (Throwable $e) {
    $db->rollback();
    err($e->getMessage(), 400);
  }
}

err("Unknown action", 400);
