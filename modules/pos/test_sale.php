<?php
// modules/pos/test_sale.php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/rbac.php';
require_once __DIR__ . '/../../includes/helpers.php';

header('Content-Type: text/plain; charset=utf-8');

echo "=== SALE CREATION TEST ===\n\n";

/* ---------------- DB CHECK ---------------- */
$db = $GLOBALS['db'] ?? null;
if (!$db instanceof mysqli) {
  die("❌ ERROR: Database not connected\n");
}
echo "✅ Database connection: OK\n";

/* ---------------- SESSION CHECK ---------------- */
if (session_status() === PHP_SESSION_NONE) session_start();
$uid = (int)($_SESSION['user']['id'] ?? 0);
if ($uid <= 0) {
  die("❌ ERROR: User not logged in\n");
}
echo "✅ User session: ID = {$uid}\n";

/* ---------------- TABLE STRUCTURE ---------------- */
echo "\n=== SALES TABLE STRUCTURE ===\n";
$res = $db->query("DESCRIBE sales");
$cols = [];
while ($r = $res->fetch_assoc()) {
  $cols[] = $r['Field'];
  echo "- {$r['Field']} ({$r['Type']}) "
     . ($r['Null'] === 'NO' ? 'NOT NULL' : 'NULL')
     . " Default: " . ($r['Default'] ?? 'NONE') . "\n";
}
echo "\nTotal columns: " . count($cols) . "\n";

/* ---------------- TEST DATA ---------------- */
echo "\n=== TESTING POS INSERT ===\n";

$doc_type   = 'receipt';
$doc_no     = 'TEST-' . date('Ymd-His');
$location   = 1;
$customer   = null;              // MUST allow NULL
$pricing    = 'retail';
$status     = 'confirmed';
$pay_status = 'unpaid';
$currency   = 'UGX';

$subtotal   = 100.00;
$discount   = 0.00;
$tax        = 0.00;
$grand      = 100.00;
$paid       = 0.00;
$balance    = 100.00;

$notes      = 'POS API Test Sale';

/* ---------------- SQL (SAFE VERSION) ---------------- */
$sql = "
INSERT INTO sales
(doc_type, doc_no, selling_location_id, customer_id, pricing_mode,
 status, payment_status, currency,
 subtotal, discount_total, tax_total, grand_total,
 amount_paid, balance, notes, created_by)
VALUES
(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
";

echo "SQL:\n$sql\n";

/* ---------------- PREPARE ---------------- */
$stmt = $db->prepare($sql);
if (!$stmt) {
  die("❌ Prepare failed: {$db->error}\n");
}

/* ---------------- BIND ---------------- */
/*
Type map:
s = string
i = integer
d = decimal
*/

$types = "ssiissssddddddsi";

echo "Bind types: {$types}\n";
echo "Type length: " . strlen($types) . "\n";
echo "Expected params: 16\n";

$stmt->bind_param(
  $types,
  $doc_type,     // s
  $doc_no,       // s
  $location,     // i
  $customer,     // i (NULL OK)
  $pricing,      // s
  $status,       // s
  $pay_status,   // s
  $currency,     // s
  $subtotal,     // d
  $discount,     // d
  $tax,          // d
  $grand,        // d
  $paid,         // d
  $balance,      // d
  $notes,        // s
  $uid            // i
);

/* ---------------- EXECUTE ---------------- */
if (!$stmt->execute()) {
  die("❌ Execute failed: {$stmt->error}\n");
}

$sale_id = (int)$stmt->insert_id;
$stmt->close();

echo "✅ INSERT SUCCESS! Sale ID: {$sale_id}\n";

/* ---------------- CLEANUP ---------------- */
$db->query("DELETE FROM sales WHERE id = {$sale_id}");
echo "✅ Test record cleaned up\n";

echo "\n=== TEST COMPLETE ===\n";