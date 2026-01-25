<?php
// api/sales.php
declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/rbac.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/audit.php';

header('Content-Type: application/json; charset=utf-8');

require_login();
require_permission('sales.create');

$db = $GLOBALS['db'];

$action = $_GET['action'] ?? 'list';

function json_ok($data=[]){ echo json_encode(['ok'=>true,'data'=>$data]); exit; }
function json_err($msg, $code=400){ http_response_code($code); echo json_encode(['ok'=>false,'error'=>$msg]); exit; }

if (!$db instanceof mysqli) json_err('DB not available', 500);

if ($action === 'create') {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_err('Method not allowed', 405);
    $raw = json_decode((string)file_get_contents('php://input'), true);
    if (!is_array($raw)) json_err('Invalid JSON');

    $items = $raw['items'] ?? [];
    $paymentMethod = trim((string)($raw['payment_method'] ?? ''));
    $customerName = trim((string)($raw['customer_name'] ?? ''));
    $subtotal = (float)($raw['subtotal'] ?? 0);
    $tax = (float)($raw['tax'] ?? 0);
    $total = (float)($raw['total'] ?? 0);
    $csrf = $raw['csrf'] ?? '';

    if (!hash_equals($_SESSION['csrf'] ?? '', (string)$csrf)) json_err('Invalid CSRF token', 403);
    if (empty($items) || !is_array($items)) json_err('Items required');
    if ($paymentMethod === '') json_err('Payment method required');

    $uid = (int)($_SESSION['user']['id'] ?? 0);

    $db->begin_transaction();
    try {
        // Insert sale
        $stmt = $db->prepare("
          INSERT INTO sales (customer_name, payment_method, subtotal, tax, total, status, created_by)
          VALUES (?, ?, ?, ?, ?, 'completed', ?)
        ");
        $stmt->bind_param("ssdddi", $customerName, $paymentMethod, $subtotal, $tax, $total, $uid);
        $stmt->execute();
        $saleId = (int)$stmt->insert_id;
        $stmt->close();

        // Insert sale items and update stock
        foreach ($items as $it) {
            $pid = (int)($it['id'] ?? 0);
            $qty = (float)($it['qty'] ?? 0);
            $unitPrice = (float)($it['unit_price'] ?? 0);
            if ($pid <= 0 || $qty <= 0) continue;

            // Insert sale item
            $stmt = $db->prepare("
              INSERT INTO sale_items (sale_id, product_id, quantity, unit_price, total_price)
              VALUES (?, ?, ?, ?, ?)
            ");
            $totalPrice = $qty * $unitPrice;
            $stmt->bind_param("idddd", $saleId, $pid, $qty, $unitPrice, $totalPrice);
            $stmt->execute();
            $stmt->close();

            // Decrease stock from default location (or first location with stock)
            // Simplified: use default_location_id from product or fallback to 1 (Store)
            $locStmt = $db->prepare("SELECT default_location_id FROM products WHERE id=? LIMIT 1");
            $locStmt->bind_param("i", $pid);
            $locStmt->execute();
            $locRes = $locStmt->get_result()->fetch_assoc();
            $locId = (int)($locRes['default_location_id'] ?? 1);
            $locStmt->close();

            // Lock and fetch current stock
            $stmt = $db->prepare("SELECT qty_base FROM stock_by_location WHERE product_id=? AND location_id=? FOR UPDATE");
            $stmt->bind_param("ii", $pid, $locId);
            $stmt->execute();
            $before = (float)($stmt->get_result()->fetch_assoc()['qty_base'] ?? 0);
            $stmt->close();

            $after = $before - $qty;
            if ($after < 0) {
                throw new Exception("Insufficient stock for product ID $pid at location $locId");
            }

            // Update stock
            $stmt = $db->prepare("UPDATE stock_by_location SET qty_base=? WHERE product_id=? AND location_id=?");
            $stmt->bind_param("dii", $after, $pid, $locId);
            $stmt->execute();
            $stmt->close();

            // Insert stock movement
            $stmt = $db->prepare("
              INSERT INTO stock_movements
              (product_id, from_location_id, to_location_id, movement_type, qty_change, qty_before, qty_after,
               reference_type, reference_id, note, created_by)
              VALUES (?, ?, ?, 'sale', ?, ?, ?, 'sale', ?, ?, ?)
            ");
            $note = "Sale #$saleId";
            $qtyChange = -$qty;
            $stmt->bind_param("iiddddsi", $pid, $locId, $locId, $qtyChange, $before, $after, $saleId, $note, $uid);
            $stmt->execute();
            $stmt->close();
        }

        audit_log('sales.create', 'sale', (string)$saleId, "Sale completed: $paymentMethod $total");
        $db->commit();
        json_ok(['id' => $saleId, 'items' => $items, 'payment_method' => $paymentMethod, 'customer_name' => $customerName, 'subtotal' => $subtotal, 'tax' => $tax, 'total' => $total]);
    } catch (Throwable $e) {
        $db->rollback();
        json_err($e->getMessage(), 400);
    }
}

json_err('Unknown action', 400);
