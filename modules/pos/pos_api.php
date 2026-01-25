<?php
// modules/pos/pos_api.php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/rbac.php';
require_once __DIR__ . '/../../includes/helpers.php';

require_permission('pos.create');

header('Content-Type: application/json; charset=utf-8');

$db = $GLOBALS['db'] ?? null;
if (!$db instanceof mysqli) {
  http_response_code(500);
  echo json_encode(['ok' => false, 'error' => 'DB not available']);
  exit;
}

if (session_status() === PHP_SESSION_NONE) session_start();

// -------------------- helpers --------------------
function json_out(array $data, int $code = 200): void {
  http_response_code($code);
  echo json_encode($data);
  exit;
}
function must_post(): void {
  if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') json_out(['ok'=>false,'error'=>'POST required'], 405);
}
function csrf_check(): void {
  $csrf = (string)($_POST['csrf'] ?? '');
  if (empty($_SESSION['csrf']) || !hash_equals((string)$_SESSION['csrf'], $csrf)) {
    // Temporarily disabled for debugging
    // json_out(['ok'=>false,'error'=>'CSRF failed','session_csrf'=>$_SESSION['csrf']??'none','post_csrf'=>$csrf], 403);
  }
}
function s($v): string { return trim((string)$v); }
function i($v): int { return (int)$v; }
function f($v): float { return (float)$v; }
function now_dt(): string { return date('Y-m-d H:i:s'); }

// NOTE: your project may expose has_permission/user_has_permission. Support both.
function has_perm(string $p): bool {
  if (function_exists('user_has_permission')) return (bool)user_has_permission($p);
  return true;
}

/**
 * Safe "does column exist" check (cached per request)
 */
function table_has_column(mysqli $db, string $table, string $column): bool {
  static $cache = [];
  $key = $table . '.' . $column;
  if (isset($cache[$key])) return (bool)$cache[$key];

  $sql = "SELECT COUNT(*) AS c
          FROM INFORMATION_SCHEMA.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?";
  $st = $db->prepare($sql);
  if (!$st) { $cache[$key] = false; return false; }
  $st->bind_param('ss', $table, $column);
  $st->execute();
  $rs = $st->get_result();
  $ok = false;
  if ($rs && ($row = $rs->fetch_assoc())) $ok = ((int)$row['c'] > 0);
  $st->close();
  $cache[$key] = $ok;
  return $ok;
}

/**
 * Stock helpers (base qty stored in stock_by_location.qty_base)
 */
function get_stock(mysqli $db, int $product_id, int $location_id): float {
  $sql = "SELECT qty_base FROM stock_by_location WHERE product_id=? AND location_id=? LIMIT 1";
  $st = $db->prepare($sql);
  if (!$st) return 0.0;
  $st->bind_param('ii', $product_id, $location_id);
  $st->execute();
  $rs = $st->get_result();
  $qty = 0.0;
  if ($rs && ($row = $rs->fetch_assoc())) $qty = (float)$row['qty_base'];
  $st->close();
  return $qty;
}
function set_stock(mysqli $db, int $product_id, int $location_id, float $qty): void {
  $sql = "INSERT INTO stock_by_location (product_id, location_id, qty_base)
          VALUES (?,?,?)
          ON DUPLICATE KEY UPDATE qty_base=VALUES(qty_base)";
  $st = $db->prepare($sql);
  if (!$st) return;
  $st->bind_param('iid', $product_id, $location_id, $qty);
  $st->execute();
  $st->close();
}

/**
 * Generate document number without requiring doc_sequences table
 */
function next_doc_no(mysqli $db, string $doc_type): string {
  $doc_type = strtolower(trim($doc_type));
  $year = date('Y');
  $time = date('His'); // Hours, minutes, seconds

  $prefixMap = [
    'receipt' => "RC-$year-",
    'invoice' => "INV-$year-",
    'delivery_note' => "DN-$year-",
  ];
  $prefix = $prefixMap[$doc_type] ?? ("DOC-$year-");

  // Simple approach: use prefix + time + random 2 digits
  $random = mt_rand(10, 99);
  return $prefix . $time . $random;
}

/**
 * Compute totals from normalized items
 */
function compute_totals(array $items): array {
  $subtotal = 0.0;
  $discount_total = 0.0;
  $grand_total = 0.0;

  foreach ($items as $it) {
    $qty = (float)($it['qty_base'] ?? 0);
    $price = (float)($it['unit_price'] ?? 0);
    $disc = (float)($it['discount_amount'] ?? 0);

    $line_sub = $qty * $price;
    $line_total = max(0.0, $line_sub - $disc);

    $subtotal += $line_sub;
    $discount_total += $disc;
    $grand_total += $line_total;
  }

  return [
    'subtotal' => round($subtotal, 2),
    'discount_total' => round($discount_total, 2),
    'grand_total' => round($grand_total, 2),
  ];
}

function sum_payments(array $payments): float {
  $t = 0.0;
  foreach ($payments as $p) $t += (float)($p['amount'] ?? 0);
  return round($t, 2);
}

// -------------------- routing --------------------
$action = s($_REQUEST['action'] ?? '');
if ($action === '') {
  error_log("POS API Debug - Missing action. POST data: " . file_get_contents('php://input'));
  error_log("POS API Debug - \$_POST: " . print_r($_POST, true));
  error_log("POS API Debug - \$_REQUEST: " . print_r($_REQUEST, true));
  json_out(['ok'=>false,'error'=>'Missing action parameter'], 400);
}

// -------------------- product_search (live suggestions with thumbs) --------------------
if ($action === 'product_search') {
  $q = s($_GET['q'] ?? '');
  $location_id = i($_GET['location_id'] ?? 0);

  if ($q === '') json_out(['ok'=>true,'results'=>[]]);

  // Optional fields: image_url/image/thumb, images (JSON)
  $thumbExpr = "'' AS thumb";
  if (table_has_column($db, 'products', 'image_url')) $thumbExpr = "COALESCE(p.image_url,'') AS thumb";
  else if (table_has_column($db, 'products', 'image')) $thumbExpr = "COALESCE(p.image,'') AS thumb";
  else if (table_has_column($db, 'products', 'thumb')) $thumbExpr = "COALESCE(p.thumb,'') AS thumb";
  else if (table_has_column($db, 'products', 'images')) {
    // If images column exists (JSON array), get first image as thumb
    $thumbExpr = "CASE 
      WHEN p.images IS NULL OR p.images = '' THEN '' 
      WHEN JSON_VALID(p.images) AND JSON_LENGTH(p.images) > 0 THEN JSON_UNQUOTE(JSON_EXTRACT(p.images, '$[0]'))
      ELSE '' 
    END AS thumb";
  }


  $like = '%' . $q . '%';
  $limit = 20;

  $sql = "SELECT
            p.id, p.sku, p.name,
            p.wholesale_price, p.retail_price,
            COALESCE(p.pieces_per_box,0) AS pieces_per_box,
            $thumbExpr
          FROM products p
          WHERE p.is_active=1
            AND (p.sku LIKE ? OR p.name LIKE ?)
          ORDER BY
            (p.sku = ?) DESC,
            (p.name LIKE ?) DESC,
            p.name ASC
          LIMIT $limit";

  $st = $db->prepare($sql);
  if (!$st) json_out(['ok'=>false,'error'=>'Query failed'], 500);

  // bind params
  $st->bind_param('ssss', $like, $like, $q, $like);

  $st->execute();
  $rs = $st->get_result();
  $out = [];

  if ($rs) {
    while ($r = $rs->fetch_assoc()) {
      $r['wholesale_price'] = (float)$r['wholesale_price'];
      $r['retail_price'] = (float)$r['retail_price'];
      $r['pieces_per_box'] = (int)$r['pieces_per_box'];
      $r['thumb'] = (string)($r['thumb'] ?? '');

      if ($location_id > 0) {
        $r['stock_qty_base'] = get_stock($db, (int)$r['id'], $location_id);
      } else {
        $r['stock_qty_base'] = null;
      }
      $out[] = $r;
    }
    $rs->free();
  }
  $st->close();

  json_out(['ok'=>true,'results'=>$out]);
}

// -------------------- product_get (scan exact barcode/SKU or top match) --------------------
if ($action === 'product_get') {
  $q = s($_GET['q'] ?? '');
  $location_id = i($_GET['location_id'] ?? 0);
  if ($q === '') json_out(['ok'=>false,'error'=>'Missing q'], 400);

  $thumbExpr = "'' AS thumb";
  if (table_has_column($db, 'products', 'image_url')) $thumbExpr = "COALESCE(image_url,'') AS thumb";
  else if (table_has_column($db, 'products', 'image')) $thumbExpr = "COALESCE(image,'') AS thumb";
  else if (table_has_column($db, 'products', 'thumb')) $thumbExpr = "COALESCE(thumb,'') AS thumb";
  else if (table_has_column($db, 'products', 'images')) {
    // If images column exists (JSON array), get first image as thumb
    $thumbExpr = "CASE 
      WHEN images IS NULL OR images = '' THEN '' 
      WHEN JSON_VALID(images) AND JSON_LENGTH(images) > 0 THEN JSON_UNQUOTE(JSON_EXTRACT(images, '$[0]'))
      ELSE '' 
    END AS thumb";
  }

  // exact match first
  $sql = "SELECT id, sku, name, wholesale_price, retail_price, COALESCE(pieces_per_box,0) AS pieces_per_box,
                 $thumbExpr
          FROM products
          WHERE is_active=1 AND sku=?
          LIMIT 1";
  $st = $db->prepare($sql);
  $row = null;

  if ($st) {
    $st->bind_param('s', $q);
    $st->execute();
    $rs = $st->get_result();
    $row = $rs ? $rs->fetch_assoc() : null;
    $st->close();
  }

  // fallback to top search result
  if (!$row) {
    $like = '%' . $q . '%';
    $sql2 = "SELECT id, sku, name, wholesale_price, retail_price, COALESCE(pieces_per_box,0) AS pieces_per_box,
                    $thumbExpr
             FROM products
             WHERE is_active=1 AND (sku LIKE ? OR name LIKE ?)
             ORDER BY (name LIKE ?) DESC, name ASC
             LIMIT 1";
    $st2 = $db->prepare($sql2);
    if ($st2) {
      $st2->bind_param('sss', $like, $like, $like);
      $st2->execute();
      $rs2 = $st2->get_result();
      $row = $rs2 ? $rs2->fetch_assoc() : null;
      $st2->close();
    }
  }

  if (!$row) json_out(['ok'=>false,'error'=>'Product not found'], 404);

  $row['wholesale_price'] = (float)$row['wholesale_price'];
  $row['retail_price'] = (float)$row['retail_price'];
  $row['pieces_per_box'] = (int)$row['pieces_per_box'];
  $row['thumb'] = (string)($row['thumb'] ?? '');

  if ($location_id > 0) $row['stock_qty_base'] = get_stock($db, (int)$row['id'], $location_id);
  else $row['stock_qty_base'] = null;

  json_out(['ok'=>true,'product'=>$row]);
}

// -------------------- customers_search (optional modal) --------------------
if ($action === 'customers_search') {
  $q = s($_GET['q'] ?? '');
  $like = '%' . $q . '%';

  $sql = "SELECT id, name, phone, category_id
          FROM customers
          WHERE is_active=1 AND (name LIKE ? OR phone LIKE ?)
          ORDER BY name ASC
          LIMIT 30";
  $st = $db->prepare($sql);
  if (!$st) json_out(['ok'=>false,'error'=>'Query failed'], 500);

  $st->bind_param('ss', $like, $like);
  $st->execute();
  $rs = $st->get_result();
  $out = [];

  if ($rs) {
    while ($r = $rs->fetch_assoc()) $out[] = $r;
    $rs->free();
  }
  $st->close();

  json_out(['ok'=>true,'results'=>$out]);
}

// -------------------- confirm_sale (POS finalization) --------------------
if ($action === 'confirm_sale') {
  must_post();
  csrf_check();

  // Debug logging
  error_log("POS confirm_sale: Starting processing");
  
  $raw = (string)($_POST['payload'] ?? '');
  error_log("POS confirm_sale: Raw payload: " . $raw);
  
  if ($raw === '') {
    error_log("POS confirm_sale: Missing payload");
    json_out(['ok'=>false,'error'=>'Missing payload'], 400);
  }

  $payload = json_decode($raw, true);
  if (!is_array($payload)) {
    error_log("POS confirm_sale: Invalid JSON payload");
    json_out(['ok'=>false,'error'=>'Invalid JSON payload'], 400);
  }

  $doc_type = strtolower(s($payload['doc_type'] ?? 'receipt'));
  if (!in_array($doc_type, ['receipt','invoice','delivery_note'], true)) {
    json_out(['ok'=>false,'error'=>'Invalid doc_type'], 400);
  }
  if ($doc_type === 'invoice' && !has_perm('pos.invoice')) json_out(['ok'=>false,'error'=>'No permission for invoice'], 403);
  if ($doc_type === 'delivery_note' && !has_perm('pos.delivery_note')) json_out(['ok'=>false,'error'=>'No permission for delivery note'], 403);

  $pricing_mode = strtolower(s($payload['pricing_mode'] ?? 'retail'));
  if (!in_array($pricing_mode, ['retail','wholesale'], true)) json_out(['ok'=>false,'error'=>'Invalid pricing_mode'], 400);

  $selling_location_id = i($payload['selling_location_id'] ?? 0);
  if ($selling_location_id <= 0) json_out(['ok'=>false,'error'=>'Selling location required'], 400);

  $customer_id_raw = $payload['customer_id'] ?? null;
  $customer_id = (is_numeric($customer_id_raw) && (int)$customer_id_raw > 0) ? (int)$customer_id_raw : null;

  $currency = s($payload['currency'] ?? 'UGX');
  $notes = s($payload['notes'] ?? '');

  $items = $payload['items'] ?? [];
  $payments = $payload['payments'] ?? [];

  if (!is_array($items) || count($items) === 0) json_out(['ok'=>false,'error'=>'Cart is empty'], 400);
  if (!is_array($payments)) $payments = [];

  $can_discount = has_perm('pos.apply_discount');
  $can_editprice = has_perm('pos.edit_price');
  $allow_stock_override = has_perm('pos.stock_override'); // optional permission

  $normalized = [];
  $needed = []; // product_id => qty_base

  // normalize items
  foreach ($items as $idx => $it) {
    if (!is_array($it)) continue;

    $is_external = !empty($it['is_external']) ? 1 : 0;

    if ($is_external) {
      $name = s($it['name'] ?? $it['name_snapshot'] ?? '');
      if ($name === '') json_out(['ok'=>false,'error'=>"External item name missing on line ".($idx+1)], 400);

      $qty = f($it['qty_base'] ?? $it['qty_input'] ?? 0);
      if ($qty <= 0) json_out(['ok'=>false,'error'=>"Invalid qty on line ".($idx+1)], 400);

      $unit_price = f($it['unit_price'] ?? 0);
      if ($unit_price < 0) $unit_price = 0;

      $disc = f($it['discount_amount'] ?? 0);
      if (!$can_discount) $disc = 0;
      if ($disc < 0) $disc = 0;

      $normalized[] = [
        'product_id' => null,
        'is_external' => 1,
        'sku_snapshot' => s($it['sku'] ?? ''),
        'name_snapshot' => $name,
        'unit_type_snapshot' => s($it['qty_unit'] ?? 'unit'),
        'pieces_per_box_snapshot' => 0,
        'qty_base' => $qty,
        'unit_price' => $unit_price,
        'discount_amount' => $disc,
        'external_cost' => f($it['external_cost'] ?? 0),
        'external_source' => s($it['external_source'] ?? ''),
      ];
      continue;
    }

    $product_id = i($it['product_id'] ?? 0);
    if ($product_id <= 0) json_out(['ok'=>false,'error'=>"Product missing on line ".($idx+1)], 400);

    $sqlP = "SELECT id, sku, name, wholesale_price, retail_price, COALESCE(pieces_per_box,0) AS pieces_per_box, is_active
             FROM products WHERE id=? LIMIT 1";
    $stP = $db->prepare($sqlP);
    if (!$stP) json_out(['ok'=>false,'error'=>'DB error (product)'], 500);

    $stP->bind_param('i', $product_id);
    $stP->execute();
    $rsP = $stP->get_result();
    $p = $rsP ? $rsP->fetch_assoc() : null;
    $stP->close();

    if (!$p || (int)$p['is_active'] !== 1) {
      json_out(['ok'=>false,'error'=>"Product not active on line ".($idx+1)], 400);
    }

    $qty = f($it['qty_base'] ?? 0);
    if ($qty <= 0) json_out(['ok'=>false,'error'=>"Invalid qty on line ".($idx+1)], 400);

    $wholesale = (float)$p['wholesale_price'];
    $retail = (float)$p['retail_price'];

    $unit_price = f($it['unit_price'] ?? (($pricing_mode === 'wholesale') ? $wholesale : $retail));
    if (!$can_editprice) $unit_price = (($pricing_mode === 'wholesale') ? $wholesale : $retail);

    // price floor rule
    if ($unit_price < $wholesale) {
      json_out(['ok'=>false,'error'=>"Price below wholesale not allowed (line ".($idx+1).")"], 400);
    }

    $disc = f($it['discount_amount'] ?? 0);
    if (!$can_discount) $disc = 0;
    if ($disc < 0) $disc = 0;

    $normalized[] = [
      'product_id' => (int)$p['id'],
      'is_external' => 0,
      'sku_snapshot' => s($p['sku'] ?? ''),
      'name_snapshot' => s($p['name'] ?? ''),
      'unit_type_snapshot' => s($it['qty_unit'] ?? 'piece'),
      'pieces_per_box_snapshot' => (int)$p['pieces_per_box'],
      'qty_base' => $qty,
      'unit_price' => $unit_price,
      'discount_amount' => $disc,
      'wholesale_floor' => $wholesale,
    ];

    $needed[$product_id] = ($needed[$product_id] ?? 0) + $qty;
  }

  if (count($normalized) === 0) json_out(['ok'=>false,'error'=>'No valid items'], 400);

  // normalize payments
  $payNorm = [];
  foreach ($payments as $p) {
    if (!is_array($p)) continue;

    $method = strtolower(s($p['method'] ?? ''));
    if (!in_array($method, ['cash','mobile_money','bank'], true)) continue;

    $amount = f($p['amount'] ?? 0);
    if ($amount <= 0) continue;

    $reference = s($p['reference'] ?? '');
    $provider = s($p['provider'] ?? '');

    if ($method === 'bank' && $reference === '') {
      json_out(['ok'=>false,'error'=>'Bank payment reference is required'], 400);
    }

    $payNorm[] = [
      'method' => $method,
      'amount' => $amount,
      'reference' => $reference,
      'provider' => $provider,
    ];
  }

  // totals
  $tot = compute_totals($normalized);
  $subtotal = (float)$tot['subtotal'];
  $discount_total = (float)$tot['discount_total'];
  $grand_total = (float)$tot['grand_total'];

  $amount_paid = sum_payments($payNorm);
  $balance = round(max(0.0, $grand_total - $amount_paid), 2);
  $payment_status = ($balance <= 0.0) ? 'paid' : (($amount_paid > 0.0) ? 'partial' : 'unpaid');

  // stock check (only stocked items)
  if (!$allow_stock_override) {
    foreach ($needed as $pid => $qtyNeed) {
      $available = get_stock($db, (int)$pid, $selling_location_id);
      if ($qtyNeed > $available) {
        json_out([
          'ok'=>false,
          'error'=>"Insufficient stock for product ID $pid",
          'details'=>['needed'=>$qtyNeed,'available'=>$available]
        ], 400);
      }
    }
  }

  $created_by = (int)($_SESSION['user_id'] ?? 0);
  $created_at = now_dt();

  // -------------------- TRANSACTION --------------------
  $db->begin_transaction();

  try {
    $doc_no = next_doc_no($db, $doc_type);

    // sales table insert (customer_id nullable)
    $sqlSale = "INSERT INTO sales
      (doc_type, doc_no, selling_location_id, customer_id, pricing_mode, status, payment_status, currency,
       subtotal, discount_total, tax_total, grand_total, amount_paid, balance, notes, created_by, created_at)
      VALUES
      (?,?,?,?,?,'confirmed',?,?, ?, ?,0, ?, ?, ?, ?, ?, ?)";

    $tax_total = 0.0;

    $stSale = $db->prepare($sqlSale);
    if (!$stSale) throw new Exception("Prepare sale failed: ".$db->error);

    // bind NULL safely (mysqlnd supports NULL)
    $stSale->bind_param(
      'ssiisssddddddsis',
      $doc_type,
      $doc_no,
      $selling_location_id,
      $customer_id,
      $pricing_mode,
      $payment_status,
      $currency,
      $subtotal,
      $discount_total,
      $tax_total,
      $grand_total,
      $amount_paid,
      $balance,
      $notes,
      $created_by,
      $created_at
    );

    if (!$stSale->execute()) throw new Exception("Insert sale failed: ".$stSale->error);
    $sale_id = (int)$stSale->insert_id;
    $stSale->close();

    if ($sale_id <= 0) throw new Exception("Sale ID missing");

    // sale_items insert
    $sqlItem = "INSERT INTO sale_items
      (sale_id, product_id, is_external, sku_snapshot, name_snapshot, unit_type_snapshot, pieces_per_box_snapshot,
       qty_base, unit_price, discount_amount, line_total, external_cost, external_source, created_at)
      VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)";
    $stItem = $db->prepare($sqlItem);
    if (!$stItem) throw new Exception("Prepare sale_items failed: ".$db->error);

    foreach ($normalized as $it) {
      $pid = $it['product_id']; // may be null
      $is_external = (int)$it['is_external'];
      $sku = (string)($it['sku_snapshot'] ?? '');
      $name = (string)($it['name_snapshot'] ?? '');
      $unit_type = (string)($it['unit_type_snapshot'] ?? 'piece');
      $ppb = (int)($it['pieces_per_box_snapshot'] ?? 0);

      $qty_base = (float)$it['qty_base'];
      $unit_price = (float)$it['unit_price'];
      $disc = (float)$it['discount_amount'];

      $line_total = max(0.0, ($qty_base * $unit_price) - $disc);

      $ext_cost = $is_external ? (float)($it['external_cost'] ?? 0) : null;
      $ext_source = $is_external ? (string)($it['external_source'] ?? '') : null;

      $stItem->bind_param(
        'iiisssddddss',
        $sale_id,
        $pid,
        $is_external,
        $sku,
        $name,
        $unit_type,
        $ppb,
        $qty_base,
        $unit_price,
        $disc,
        $line_total,
        $ext_cost,
        $ext_source,
        $created_at
      );
      // ^ binding signature mismatched (types count) in some PHP builds
      // Use safe re-prepare with correct types:
      $stItem->close();
      $sqlItem2 = "INSERT INTO sale_items
        (sale_id, product_id, is_external, sku_snapshot, name_snapshot, unit_type_snapshot, pieces_per_box_snapshot,
         qty_base, unit_price, discount_amount, line_total, external_cost, external_source, created_at)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)";
      $stItem = $db->prepare($sqlItem2);
      if (!$stItem) throw new Exception("Prepare sale_items failed: ".$db->error);

      // Correct bind types: i i i s s s i d d d d d s s
      $stItem->bind_param(
        'iiisssiddaddss',
        $sale_id,
        $pid,
        $is_external,
        $sku,
        $name,
        $unit_type,
        $ppb,
        $qty_base,
        $unit_price,
        $disc,
        $line_total,
        $ext_cost,
        $ext_source,
        $created_at
      );

      if (!$stItem->execute()) throw new Exception("Insert sale_item failed: ".$stItem->error);
    }
    $stItem->close();

    // sale_payments insert
    if (count($payNorm) > 0) {
      $sqlPay = "INSERT INTO sale_payments (sale_id, method, amount, reference, provider, received_by, received_at)
                 VALUES (?,?,?,?,?,?,?)";
      $stPay = $db->prepare($sqlPay);
      if (!$stPay) throw new Exception("Prepare sale_payments failed: ".$db->error);

      foreach ($payNorm as $p) {
        $method = (string)$p['method'];
        $amount = (float)$p['amount'];
        $reference = (string)$p['reference'];
        $provider = (string)$p['provider'];
        $received_by = $created_by;
        $received_at = $created_at;

        $stPay->bind_param('isdssis', $sale_id, $method, $amount, $reference, $provider, $received_by, $received_at);
        if (!$stPay->execute()) throw new Exception("Insert payment failed: ".$stPay->error);
      }
      $stPay->close();
    }

    // stock_movements + stock_by_location update (for non-external)
    $has_location_id = table_has_column($db, 'stock_movements', 'location_id');

    foreach ($normalized as $it) {
      if (!empty($it['is_external'])) continue;
      $pid = (int)$it['product_id'];
      $qty = (float)$it['qty_base'];
      if ($qty <= 0) continue;

      $before = get_stock($db, $pid, $selling_location_id);
      $change = -abs($qty);
      $after = $before + $change;

      if (!$allow_stock_override && $after < 0) {
        throw new Exception("Negative stock prevented for product $pid");
      }

      // Build dynamic insert depending on existing columns (prevents schema mismatch crashes)
      $cols = [
        'product_id' => $pid,
        'type' => 'sale',
        'qty_before' => $before,
        'qty_change' => $change,
        'qty_after' => $after,
        'ref_type' => $doc_type,
        'ref_id' => $sale_id,
        'reference' => $doc_no,
        'notes' => "POS $doc_no",
        'user_id' => $created_by,
        'from_location_id' => $selling_location_id,
        'to_location_id' => null,
        'created_at' => $created_at,
      ];
      if (!$has_location_id) unset($cols['location_id']);
      else $cols['location_id'] = $selling_location_id;

      // Remove any keys that don't exist in your table
      foreach (array_keys($cols) as $c) {
        if (!table_has_column($db, 'stock_movements', $c)) unset($cols[$c]);
      }

      $fields = array_keys($cols);
      $placeholders = implode(',', array_fill(0, count($fields), '?'));
      $fieldList = implode(',', $fields);

      $sqlMv = "INSERT INTO stock_movements ($fieldList) VALUES ($placeholders)";
      $stMv = $db->prepare($sqlMv);
      if (!$stMv) throw new Exception("Prepare stock_movements failed: ".$db->error);

      // Build types + params
      $types = '';
      $params = [];
      foreach ($fields as $c) {
        $v = $cols[$c];
        if (is_int($v)) $types .= 'i';
        else if (is_float($v)) $types .= 'd';
        else $types .= 's'; // null also ok
        $params[] = $v;
      }

      // bind_param with unpack
      $stMv->bind_param($types, ...$params);
      if (!$stMv->execute()) throw new Exception("Insert movement failed: ".$stMv->error);
      $stMv->close();

      set_stock($db, $pid, $selling_location_id, $after);
    }

    // audit
    if (function_exists('audit_log')) {
      audit_log('pos.confirm_sale', "Confirmed $doc_type $doc_no", (string)$sale_id, json_encode([
        'sale_id' => $sale_id,
        'doc_no' => $doc_no,
        'doc_type' => $doc_type,
        'grand_total' => $grand_total,
        'amount_paid' => $amount_paid,
        'balance' => $balance
      ]));
    }

    $db->commit();

    json_out([
      'ok' => true,
      'sale_id' => $sale_id,
      'doc_no' => $doc_no,
      'doc_type' => $doc_type,
      'payment_status' => $payment_status,
      'amount_paid' => $amount_paid,
      'balance' => $balance,
      'redirect' => rtrim((string)($GLOBALS['BASE_URL'] ?? ''), '/') . "/modules/pos/pos_view.php?id=" . $sale_id
    ]);
  } catch (Throwable $e) {
    $db->rollback();
    json_out(['ok'=>false,'error'=>$e->getMessage()], 500);
  }
}

// -------------------- default --------------------
json_out(['ok'=>false,'error'=>'Unknown action'], 400);
