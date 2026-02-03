<?php
// api/products.php
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

$action = $_GET['action'] ?? 'list';

function json_ok($data=[]){ echo json_encode(['ok'=>true,'data'=>$data]); exit; }
function json_err($msg, $code=400){ http_response_code($code); echo json_encode(['ok'=>false,'error'=>$msg]); exit; }

if (!$db instanceof mysqli) json_err('DB not available', 500);

if ($action === 'price_update') {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_err('Method not allowed', 405);
    $csrf = $_POST['csrf'] ?? '';
    if (!hash_equals($_SESSION['csrf'] ?? '', (string)$csrf)) json_err('Invalid CSRF token', 403);
    require_permission('products.update');

    $pid = (int)($_POST['product_id'] ?? 0);
    $cost = (float)($_POST['cost_price'] ?? 0);
    $wholesale = (float)($_POST['wholesale_price'] ?? 0);
    $retail = (float)($_POST['retail_price'] ?? 0);
    $reason = trim((string)($_POST['reason'] ?? ''));
    $note = trim((string)($_POST['note'] ?? ''));

    if ($pid <= 0) json_err('Invalid product');
    if ($reason === '') json_err('Reason is required');

    $db->begin_transaction();
    try {
        // Fetch old prices for audit
        $stmt = $db->prepare("SELECT cost_price, wholesale_price, retail_price FROM products WHERE id=? LIMIT 1");
        $stmt->bind_param("i", $pid);
        $stmt->execute();
        $old = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        // Update prices
        $stmt = $db->prepare("UPDATE products SET cost_price=?, wholesale_price=?, retail_price=? WHERE id=?");
        $stmt->bind_param("dddi", $cost, $wholesale, $retail, $pid);
        $stmt->execute();
        $stmt->close();

        // Audit log
        audit_log('products.price_update', 'product', (string)$pid, "Price update: $reason");

        $db->commit();
        json_ok(['message' => 'Prices updated successfully']);
    } catch (Throwable $e) {
        $db->rollback();
        json_err($e->getMessage(), 400);
    }
}

if ($action === 'list') {
    $q = trim((string)($_GET['q'] ?? ''));
    $where = "1=1";
    $params = [];
    $types = "";

    if ($q !== '') {
        $where .= " AND (p.name LIKE ? OR p.sku LIKE ?)";
        $like = "%$q%";
        $params[] = $like; $params[] = $like;
        $types .= "ss";
    }

    $sql = "SELECT p.*, c.name AS category_name
            FROM products p
            LEFT JOIN product_categories c ON c.id = p.category_id
            WHERE $where
            ORDER BY p.id DESC
            LIMIT 300";

    $stmt = $db->prepare($sql);
    if ($types !== '') $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $res = $stmt->get_result();

    $rows = [];
    while ($r = $res->fetch_assoc()) {
        $r['stock_display'] = format_stock($r);
        $rows[] = $r;
    }
    $stmt->close();
    json_ok($rows);
}

if ($action === 'categories') {
    $rows = [];
    $res = $db->query("SELECT id, name FROM product_categories WHERE is_active=1 ORDER BY name ASC");
    if (!$res) {
        json_err('Categories table not found. Create product_categories first.', 500);
    }
    while ($r = $res->fetch_assoc()) $rows[] = $r;
    json_ok(['categories'=>$rows]);
}

if ($action === 'categories_admin_list') {
    $q = trim((string)($_GET['q'] ?? ''));
    $active = (string)($_GET['active'] ?? '');

    $where = " WHERE 1=1 ";
    $params = [];
    $types = "";

    if ($q !== '') {
        $where .= " AND name LIKE ? ";
        $like = "%{$q}%";
        $params[] = $like;
        $types .= "s";
    }
    if ($active === '0' || $active === '1') {
        $where .= " AND is_active = ? ";
        $params[] = (int)$active;
        $types .= "i";
    }

    $sql = "SELECT id, name, is_active FROM product_categories {$where} ORDER BY name ASC";
    $stmt = $db->prepare($sql);
    if (!$stmt) json_err('Categories table not found. Create product_categories first.', 500);
    if ($types !== '') $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $res = $stmt->get_result();
    $rows = [];
    while ($r = $res->fetch_assoc()) $rows[] = $r;
    $stmt->close();

    json_ok(['items' => $rows]);
}

if ($action === 'get') {
    $id = (int)($_GET['id'] ?? 0);
    if ($id <= 0) json_err('Invalid ID');

    $stmt = $db->prepare("SELECT * FROM products WHERE id=? LIMIT 1");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$row) json_err('Not found', 404);
    $row['stock_display'] = format_stock($row);
    json_ok($row);
}

if ($action === 'create' || $action === 'update') {
    $needPerm = $action === 'create' ? 'products.create' : 'products.update';
    require_permission($needPerm);

    $raw = json_decode((string)file_get_contents('php://input'), true);
    if (!is_array($raw)) json_err('Invalid JSON');

    $id = (int)($raw['id'] ?? 0);

    $category_id = !empty($raw['category_id']) ? (int)$raw['category_id'] : null;
    $name        = trim((string)($raw['name'] ?? ''));
    $sku         = trim((string)($raw['sku'] ?? ''));
    $desc        = trim((string)($raw['description'] ?? ''));
    $source      = trim((string)($raw['source'] ?? ''));

    $unit_type   = (string)($raw['unit_type'] ?? 'pieces'); // boxes/dozens/pairs/pieces/units
    $unit_name   = trim((string)($raw['unit_name'] ?? ''));
    $ppb         = (int)($raw['pieces_per_box'] ?? 0);

    $cost        = (float)($raw['cost_price'] ?? 0);
    $wholesale   = (float)($raw['wholesale_price'] ?? 0);
    $retail      = (float)($raw['retail_price'] ?? 0);

    $qty_base    = (float)($raw['qty_base'] ?? 0);
    $low_base    = (float)($raw['low_level_base'] ?? 0);
    $default_location_id = !empty($raw['default_location_id']) ? (int)$raw['default_location_id'] : null;
    $is_active   = isset($raw['is_active']) ? (int)(!!$raw['is_active']) : 1;
    $images      = $raw['images'] ?? null;

    if ($name === '') json_err('Product name is required');
    if ($sku === '') json_err('SKU is required');
    if (strlen($sku) > 50) json_err('SKU must be 50 characters or less');
    if (strlen($name) > 200) json_err('Product name must be 200 characters or less');
    if (strlen($source) > 255) json_err('Source must be 255 characters or less');

    $valid = ['boxes','dozens','pairs','pieces','units'];
    if (!in_array($unit_type, $valid, true)) json_err('Invalid unit_type');

    if ($unit_type === 'units' && $unit_name === '') json_err('Unit name required for units (e.g. kg)');
    if ($unit_type === 'boxes' && $ppb <= 0) json_err('pieces_per_box required for boxes');
    if ($cost < 0 || $wholesale < 0 || $retail < 0) json_err('Prices cannot be negative');
    if ($qty_base < 0 || $low_base < 0) json_err('Stock quantities cannot be negative');

    if ($unit_type === 'units' && $unit_name === '') json_err('Unit name required for units (e.g. kg)');
    if ($unit_type === 'boxes' && $ppb <= 0) json_err('pieces_per_box required for boxes');

    // SKU uniqueness
    if ($action === 'create') {
        $stmt = $db->prepare("SELECT 1 FROM products WHERE sku=? LIMIT 1");
        $stmt->bind_param("s", $sku);
        $stmt->execute();
        $exists = $stmt->get_result()->fetch_row();
        $stmt->close();
        if ($exists) json_err('SKU already exists');
    } else {
        if ($id <= 0) json_err('Invalid ID');
        $stmt = $db->prepare("SELECT 1 FROM products WHERE sku=? AND id<>? LIMIT 1");
        $stmt->bind_param("si", $sku, $id);
        $stmt->execute();
        $exists = $stmt->get_result()->fetch_row();
        $stmt->close();
        if ($exists) json_err('SKU already exists');
    }

    if ($action === 'create') {
        $stmt = $db->prepare("INSERT INTO products
          (category_id, name, sku, description, source, unit_type, unit_name, pieces_per_box,
           cost_price, wholesale_price, retail_price, qty_base, low_level_base, default_location_id, is_active)
          VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
        $stmt->bind_param(
          "isssssssddddiii",
          $category_id, $name, $sku, $desc, $source, $unit_type, $unit_name, $ppb,
          $cost, $wholesale, $retail, $qty_base, $low_base, $default_location_id, $is_active
        );
        $ok = $stmt->execute();
        $newId = (int)$stmt->insert_id;
        $stmt->close();
        if (!$ok) json_err('Create failed', 500);

        // Insert into stock_by_location at default location if qty > 0 and location set
        if ($qty_base > 0 && $default_location_id) {
          $stmt2 = $db->prepare("INSERT INTO stock_by_location (product_id, location_id, qty_base, low_level_base) VALUES (?,?,?,?)");
          $stmt2->bind_param("iidd", $newId, $default_location_id, $qty_base, $low_base);
          $stmt2->execute();
          $stmt2->close();
        }

        audit_log('products.create', 'product', (string)$newId, "Created: $sku");
        json_ok(['id'=>$newId]);
    }

    // update
    $stmt = $db->prepare("UPDATE products SET
        category_id=?, name=?, sku=?, description=?, source=?,
        unit_type=?, unit_name=?, pieces_per_box=?,
        cost_price=?, wholesale_price=?, retail_price=?,
        qty_base=?, low_level_base=?, default_location_id=?, is_active=?
      WHERE id=?");
    $stmt->bind_param(
      "isssssssdddddiii",
      $category_id, $name, $sku, $desc, $source,
      $unit_type, $unit_name, $ppb,
      $cost, $wholesale, $retail,
      $qty_base, $low_base, $default_location_id, $is_active, $id
    );
    $ok = $stmt->execute();
    $stmt->close();
    if (!$ok) json_err('Update failed', 500);

    // Sync stock_by_location: ensure row exists at default_location_id and update qty/low
    if ($default_location_id) {
      $db->query("INSERT IGNORE INTO stock_by_location (product_id, location_id, qty_base, low_level_base)
                  VALUES ($id, $default_location_id, 0, 0)");
      $stmt2 = $db->prepare("UPDATE stock_by_location SET qty_base=?, low_level_base=? WHERE product_id=? AND location_id=?");
      $stmt2->bind_param("ddii", $qty_base, $low_base, $id, $default_location_id);
      $stmt2->execute();
      $stmt2->close();
    }

    audit_log('products.update', 'product', (string)$id, "Updated: $sku");
    json_ok(['id'=>$id]);
}

if ($action === 'delete') {
    require_permission('products.delete');
    $id = (int)($_GET['id'] ?? 0);
    if ($id <= 0) json_err('Invalid ID');

    $stmt = $db->prepare("DELETE FROM products WHERE id=?");
    $stmt->bind_param("i", $id);
    $ok = $stmt->execute();
    $stmt->close();
    if (!$ok) json_err('Delete failed', 500);

    audit_log('products.delete', 'product', (string)$id, "Deleted");
    json_ok(['id'=>$id]);
}

if ($action === 'stock_adjustment') {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_err('Method not allowed', 405);
    $csrf = $_POST['csrf'] ?? '';
    if (!hash_equals($_SESSION['csrf'] ?? '', (string)$csrf)) json_err('Invalid CSRF token', 403);
    require_permission('products.update');

    $productId = (int)($_POST['product_id'] ?? 0);
    $locationId = (int)($_POST['location_id'] ?? 0);
    $qtyChange = (float)($_POST['qty_change'] ?? 0);
    $adjReason = trim((string)($_POST['reason'] ?? ''));
    $note = trim((string)($_POST['note'] ?? ''));

    if ($productId <= 0) json_err('Invalid product');
    if ($locationId <= 0) json_err('Invalid location');
    if ($qtyChange == 0) json_err('Adjustment cannot be zero');
    if ($adjReason === '') json_err('Reason is required');

    $uid = (int)($_SESSION['user']['id'] ?? 0);

    $db->begin_transaction();
    try {
        // Ensure row exists
        $db->query("INSERT IGNORE INTO stock_by_location (product_id, location_id, qty_base, low_level_base)
                    VALUES ($productId, $locationId, 0, 0)");

        // Lock and fetch current quantity
        $stmt = $db->prepare("SELECT qty_base FROM stock_by_location WHERE product_id=? AND location_id=? FOR UPDATE");
        $stmt->bind_param("ii", $productId, $locationId);
        $stmt->execute();
        $before = (float)($stmt->get_result()->fetch_assoc()['qty_base'] ?? 0);
        $stmt->close();

        $after = $before + $qtyChange;

        // Update stock
        $stmt = $db->prepare("UPDATE stock_by_location SET qty_base=? WHERE product_id=? AND location_id=?");
        $stmt->bind_param("dii", $after, $productId, $locationId);
        $stmt->execute();
        $stmt->close();

        // Insert movement
        $stmt = $db->prepare("
          INSERT INTO stock_movements
          (product_id, from_location_id, to_location_id, movement_type, qty_change, qty_before, qty_after,
           reference_type, reference_id, note, created_by)
          VALUES (?, ?, ?, 'adjustment', ?, ?, ?, 'adjustment', NULL, ?, ?)
        ");
        $stmt->bind_param("iiddddsi", $productId, $locationId, $locationId, $qtyChange, $before, $after, $note, $uid);
        $stmt->execute();
        $stmt->close();

        audit_log('products.stock_adjustment', 'product', (string)$productId, "Adjustment: $qtyChange at location $locationId ($adjReason)");
        $db->commit();
        json_ok(['new_qty' => $after]);
    } catch (Throwable $e) {
        $db->rollback();
        json_err($e->getMessage(), 400);
    }
}

// Stock In (Receive Stock)
if ($action === 'stock_in_record') {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_err('Method not allowed', 405);
    $csrf = $_POST['csrf'] ?? '';
    if (!hash_equals($_SESSION['csrf'] ?? '', (string)$csrf)) json_err('Invalid CSRF token', 403);
    require_permission('products.update');

    $productId = (int)($_POST['product_id'] ?? 0);
    $locationId = (int)($_POST['location_id'] ?? 0);
    $qtyChange = (float)($_POST['qty_change'] ?? 0);
    $unitPrice = (float)($_POST['unit_price'] ?? 0);
    $note = trim((string)($_POST['note'] ?? ''));

    if ($productId <= 0) json_err('Invalid product');
    if ($locationId <= 0) json_err('Invalid location');
    if ($qtyChange <= 0) json_err('Quantity must be greater than 0');

    $uid = (int)($_SESSION['user']['id'] ?? 0);

    $db->begin_transaction();
    try {
        // Ensure stock row exists
        $db->query("INSERT IGNORE INTO stock_by_location (product_id, location_id, qty_base, low_level_base)
                    VALUES ($productId, $locationId, 0, 0)");

        // Get current stock
        $stmt = $db->prepare("SELECT qty_base FROM stock_by_location WHERE product_id=? AND location_id=? FOR UPDATE");
        $stmt->bind_param("ii", $productId, $locationId);
        $stmt->execute();
        $before = (float)($stmt->get_result()->fetch_assoc()['qty_base'] ?? 0);
        $stmt->close();

        $after = $before + $qtyChange;

        // Update stock
        $stmt = $db->prepare("UPDATE stock_by_location SET qty_base=? WHERE product_id=? AND location_id=?");
        $stmt->bind_param("dii", $after, $productId, $locationId);
        $stmt->execute();
        $stmt->close();

        // Record movement
        $stmt = $db->prepare("
            INSERT INTO stock_movements
            (product_id, from_location_id, to_location_id, movement_type, qty_change, qty_before, qty_after,
             reference_type, reference_id, note, created_by)
            VALUES (?, ?, ?, 'stock_in', ?, ?, ?, 'stock_in', NULL, ?, ?)
        ");
        $stmt->bind_param("iiddddsi", $productId, $locationId, $locationId, $qtyChange, $before, $after, $note, $uid);
        $stmt->execute();
        $stmt->close();

        audit_log('products.stock_in', 'product', (string)$productId, "Stock in: $qtyChange units");
        
        $db->commit();
        json_ok(['message' => 'Stock added successfully', 'new_qty' => $after]);
    } catch (Throwable $e) {
        $db->rollback();
        json_err($e->getMessage(), 400);
    }
}
