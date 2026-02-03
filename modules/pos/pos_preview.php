<?php
// modules/pos/pos_preview.php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/rbac.php';
require_once __DIR__ . '/../../includes/helpers.php';

// --------- session (match pos_api.php style) ----------
if (session_status() === PHP_SESSION_NONE) {
  $sessionId = $_COOKIE['BMSESSID'] ?? $_COOKIE['PHPSESSID'] ?? null;
  if ($sessionId) session_id($sessionId);
  session_start();
}

if (empty($_SESSION['user']['id'])) {
  http_response_code(401);
  echo "<div class='alert alert-danger'>Authentication required</div>";
  exit;
}

$db = $GLOBALS['db'] ?? null;
if (!$db instanceof mysqli) {
  http_response_code(500);
  echo "<div class='alert alert-danger'>DB not available</div>";
  exit;
}

if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(32));
$csrf = $_SESSION['csrf'];

function h2(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }

function out_html(string $html, int $code = 200): void {
  http_response_code($code);
  echo $html;
  exit;
}

function read_json(): array {
  $raw = file_get_contents('php://input');
  $j = json_decode($raw, true);
  return is_array($j) ? $j : [];
}

function csrf_check(string $token): void {
  if (empty($_SESSION['csrf']) || !hash_equals($_SESSION['csrf'], $token)) {
    out_html("<div class='alert alert-danger'>CSRF failed (refresh page)</div>", 403);
  }
}

function num($v): float {
  $x = (float)$v;
  return is_finite($x) ? $x : 0.0;
}

function money($n): string {
  $x = (float)$n;
  return number_format($x, 0, '.', ','); // UGX style
}

function get_customer_name(mysqli $db, ?int $customer_id): string {
  if (!$customer_id) return 'Walk-in Customer';
  $st = $db->prepare("SELECT name FROM customers WHERE id=? LIMIT 1");
  if (!$st) return 'Customer';
  $st->bind_param('i', $customer_id);
  $st->execute();
  $row = $st->get_result()->fetch_assoc();
  $st->close();
  return $row && !empty($row['name']) ? (string)$row['name'] : 'Customer';
}

$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');

// ======================================================
// 1) GET MODE: load confirmed sale by id for printing
//    /modules/pos/pos_preview.php?id=17
// ======================================================
if ($method === 'GET') {
  $sale_id = (int)($_GET['id'] ?? 0);
  if ($sale_id <= 0) out_html("<div class='alert alert-warning'>Missing sale id.</div>", 400);

  // sale header
  $stS = $db->prepare("SELECT * FROM sales WHERE id=? LIMIT 1");
  if (!$stS) out_html("<div class='alert alert-danger'>DB error: ".h2($db->error)."</div>", 500);
  $stS->bind_param('i', $sale_id);
  $stS->execute();
  $sale = $stS->get_result()->fetch_assoc();
  $stS->close();

  if (!$sale) out_html("<div class='alert alert-warning'>Sale not found.</div>", 404);

  // items
  $items = [];
  $stI = $db->prepare("SELECT * FROM sale_items WHERE sale_id=? ORDER BY id ASC");
  if ($stI) {
    $stI->bind_param('i', $sale_id);
    $stI->execute();
    $rs = $stI->get_result();
    while ($r = $rs->fetch_assoc()) {
      $items[] = $r;
    }
    $stI->close();
  }

  // payments (optional table)
  $payments = [];
  $has_pay = $db->query("SHOW TABLES LIKE 'sale_payments'")?->num_rows ?? 0;
  if ($has_pay) {
    $stP = $db->prepare("SELECT method, provider, reference, amount FROM sale_payments WHERE sale_id=? ORDER BY id ASC");
    if ($stP) {
      $stP->bind_param('i', $sale_id);
      $stP->execute();
      $rs = $stP->get_result();
      while ($r = $rs->fetch_assoc()) $payments[] = $r;
      $stP->close();
    }
  }

  $customer_id = isset($sale['customer_id']) && (int)$sale['customer_id'] > 0 ? (int)$sale['customer_id'] : null;
  $customer_name = get_customer_name($db, $customer_id);

  // Calculate balance for existing sales
  $grand_total = (float)($sale['grand_total'] ?? 0);
  $amount_paid = (float)($sale['amount_paid'] ?? 0);
  $balance = max(0.0, $grand_total - $amount_paid);
  

  // Get receipt settings from database (assuming settings table exists)
  $receipt_settings = [];
  $settings_query = $db->query("SELECT `key`, `value` FROM settings WHERE `key` IN ('receipt_header', 'receipt_footer', 'business_name', 'business_address', 'business_phone', 'business_email') LIMIT 6");
  if ($settings_query) {
    while ($row = $settings_query->fetch_assoc()) {
      $receipt_settings[$row['key']] = $row['value'];
    }
    $settings_query->free();
  }

  // Render printable receipt view
  ?>
  <!DOCTYPE html>
  <html>
  <head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Receipt #<?= h2($sale['doc_no']) ?></title>
    <style>
      @media print {
        .no-print { display: none !important; }
        .receipt { 
          width: 80mm !important; 
          margin: 0 !important;
          padding: 5mm !important;
          font-size: 12px !important;
        }
        .receipt-header { text-align: center; margin-bottom: 15px; }
        .receipt-footer { text-align: center; margin-top: 20px; font-size: 10px; }
        .divider { border-top: 1px dashed #000; margin: 10px 0; }
        .cashier-copy { page-break-after: always; }
      }
      .receipt { 
        width: 80mm; 
        margin: 0 auto; 
        padding: 10px; 
        font-family: 'Courier New', monospace;
        background: white;
        min-height: 200mm;
      }
      .receipt-header { text-align: center; margin-bottom: 20px; }
      .receipt-header h2 { margin: 0; font-size: 18px; }
      .receipt-header .business-info { font-size: 11px; margin: 5px 0; }
      .receipt-info { margin-bottom: 15px; }
      .receipt-info .row { display: flex; justify-content: space-between; margin: 3px 0; }
      .items-table { width: 100%; margin: 15px 0; }
      .items-table th { text-align: left; border-bottom: 1px solid #000; padding: 5px 0; }
      .items-table td { padding: 3px 0; }
      .totals { margin: 15px 0; }
      .totals .row { display: flex; justify-content: space-between; margin: 5px 0; }
      .totals .grand-total { font-weight: bold; font-size: 14px; border-top: 1px solid #000; padding-top: 5px; }
      .payments { margin: 15px 0; }
      .payments .row { display: flex; justify-content: space-between; margin: 3px 0; }
      .receipt-footer { text-align: center; margin-top: 30px; font-size: 11px; }
      .divider { border-top: 1px dashed #ccc; margin: 15px 0; }
      .no-print { padding: 20px; text-align: center; background: #f8f9fa; margin-bottom: 20px; }
      .btn { padding: 10px 20px; margin: 5px; border: none; border-radius: 4px; cursor: pointer; font-size: 14px; }
      .btn-primary { background: #007bff; color: white; }
      .btn-success { background: #28a745; color: white; }
      .btn-secondary { background: #6c757d; color: white; }
    </style>
  </head>
  <body>
    <div class="no-print">
      <h3>Receipt #<?= h2($sale['doc_no']) ?></h3>
      <button class="btn btn-primary" onclick="printReceipt('customer')">
        <i class="bi bi-printer"></i> Print Customer Copy
      </button>
      <button class="btn btn-success" onclick="printReceipt('cashier')">
        <i class="bi bi-printer"></i> Print Cashier Copy
      </button>
      <button class="btn btn-secondary" onclick="openCashDrawer()">
        <i class="bi bi-box-arrow-up"></i> Open Cash Drawer
      </button>
      <button class="btn btn-secondary" onclick="window.close()">
        <i class="bi bi-x-circle"></i> Close
      </button>
    </div>

    <!-- Customer Copy -->
    <div class="receipt" id="customerCopy">
      <div class="receipt-header">
        <h2><?= h2($receipt_settings['business_name'] ?? 'Business Manager') ?></h2>
        <div class="business-info">
          <?= h2($receipt_settings['business_address'] ?? '') ?><br>
          <?= h2($receipt_settings['business_phone'] ?? '') ?><br>
          <?= h2($receipt_settings['business_email'] ?? '') ?>
        </div>
        <div class="divider"></div>
        <strong>CUSTOMER COPY</strong>
      </div>

      <div class="receipt-info">
        <div class="row">
          <span>Receipt No:</span>
          <span><?= h2((string)$sale['doc_no']) ?></span>
        </div>
        <div class="row">
          <span>Date:</span>
          <span><?= date('Y-m-d H:i:s', strtotime($sale['created_at'])) ?></span>
        </div>
        <div class="row">
          <span>Cashier:</span>
          <span><?= h2((string)($_SESSION['user']['name'] ?? 'System')) ?></span>
        </div>
        <div class="row">
          <span>Customer:</span>
          <span><?= h2($customer_name) ?></span>
        </div>
        <?php if (!empty($sale['notes'])): ?>
        <div class="row">
          <span>Notes:</span>
          <span><?= h2((string)$sale['notes']) ?></span>
        </div>
        <?php endif; ?>
      </div>

      <table class="items-table">
        <thead>
          <tr>
            <th>Item</th>
            <th style="text-align: right;">Qty</th>
            <th style="text-align: right;">Price</th>
            <th style="text-align: right;">Total</th>
          </tr>
        </thead>
        <tbody>
        <?php if (!$items): ?>
          <tr><td colspan="4" style="text-align: center;">No items</td></tr>
        <?php else: foreach ($items as $it): ?>
          <tr>
            <td>
              <?= h2((string)($it['name_snapshot'] ?? 'Item')) ?><br>
              <small><?= h2((string)($it['sku_snapshot'] ?? '')) ?></small>
            </td>
            <td style="text-align: right;"><?= number_format($it['qty_base'] ?? 0, 0) ?></td>
            <td style="text-align: right;"><?= money($it['unit_price'] ?? 0) ?></td>
            <td style="text-align: right;"><?= money($it['line_total'] ?? 0) ?></td>
          </tr>
        <?php endforeach; endif; ?>
        </tbody>
      </table>

      <div class="totals">
        <div class="row">
          <span>Subtotal:</span>
          <span><?= money($sale['subtotal'] ?? 0) ?></span>
        </div>
        <div class="row">
          <span>Discount:</span>
          <span>-<?= money($sale['discount_total'] ?? 0) ?></span>
        </div>
        <div class="row">
          <span>Tax:</span>
          <span><?= money($sale['tax_total'] ?? 0) ?></span>
        </div>
        <div class="row grand-total">
          <span>Grand Total:</span>
          <span><?= money($sale['grand_total'] ?? 0) ?></span>
        </div>
      </div>

      <?php if ($payments): ?>
      <div class="payments">
        <div class="divider"></div>
        <strong>Payments:</strong>
        <?php foreach ($payments as $p): ?>
        <div class="row">
          <span><?= h2((string)$p['method']) ?></span>
          <span><?= money($p['amount'] ?? 0) ?></span>
        </div>
        <?php endforeach; ?>
        <div class="row" style="font-weight: bold;">
          <span>Amount Tendered:</span>
          <span><?= money($sale['amount_paid'] ?? 0) ?></span>
        </div>
        <?php 
        $change_amount = ($sale['amount_paid'] ?? 0) - ($sale['grand_total'] ?? 0);
        if ($change_amount > 0): 
        ?>
        <div class="row" style="font-weight: bold; color: #28a745;">
          <span>Change:</span>
          <span><?= money($change_amount) ?></span>
        </div>
        <?php endif; ?>
        <div class="row" style="font-weight: bold;">
          <span>Balance:</span>
          <span><?= money($balance) ?></span>
        </div>
        <?php if ($balance > 0): ?>
        <div class=\"row\" style=\"font-weight: bold; color: #dc3545; margin-top: 5px;\">
          <span style=\"text-transform: uppercase; font-size: 11px;\">*** UNPAID ***</span>
          <span style=\"font-size: 11px;\">Balance Due</span>
        </div>
        <?php endif; ?>
      </div>
      <?php endif; ?>

      <!-- Balance section - always show -->
      <div class="balance-section">
        <div class="divider"></div>
        <div class="row" style="font-weight: bold;">
          <span>Balance:</span>
          <span><?= money($balance) ?></span>
        </div>
        <?php if ($balance > 0): ?>
        <div class="row" style="font-weight: bold; color: #dc3545; margin-top: 5px;">
          <span style="text-transform: uppercase; font-size: 11px;">*** UNPAID ***</span>
          <span style="font-size: 11px;">Balance Due</span>
        </div>
        <?php endif; ?>
      </div>

      <div class="receipt-footer">
        <div class="divider"></div>
        <div><?= nl2br(h2($receipt_settings['receipt_footer'] ?? 'Thank you for your business!')) ?></div>
        <div style="margin-top: 10px; font-size: 10px;">
          <?= date('Y-m-d H:i:s') ?> | System Generated
        </div>
      </div>
    </div>

    <!-- Cashier Copy -->
    <div class="receipt cashier-copy" id="cashierCopy">
      <div class="receipt-header">
        <h2><?= h2($receipt_settings['business_name'] ?? 'Business Manager') ?></h2>
        <div class="business-info">
          <?= h2($receipt_settings['business_address'] ?? '') ?><br>
          <?= h2($receipt_settings['business_phone'] ?? '') ?><br>
          <?= h2($receipt_settings['business_email'] ?? '') ?>
        </div>
        <div class="divider"></div>
        <strong>CASHIER COPY</strong>
      </div>

      <div class="receipt-info">
        <div class="row">
          <span>Receipt No:</span>
          <span><?= h2((string)$sale['doc_no']) ?></span>
        </div>
        <div class="row">
          <span>Date:</span>
          <span><?= date('Y-m-d H:i:s', strtotime($sale['created_at'])) ?></span>
        </div>
        <div class="row">
          <span>Cashier:</span>
          <span><?= h2((string)($_SESSION['user']['name'] ?? 'System')) ?></span>
        </div>
        <div class="row">
          <span>Customer:</span>
          <span><?= h2($customer_name) ?></span>
        </div>
        <div class="row">
          <span>Payment Methods:</span>
          <span><?= implode(', ', array_column($payments, 'method')) ?></span>
        </div>
      </div>

      <table class="items-table">
        <thead>
          <tr>
            <th>Item</th>
            <th style="text-align: right;">Qty</th>
            <th style="text-align: right;">Price</th>
            <th style="text-align: right;">Total</th>
          </tr>
        </thead>
        <tbody>
        <?php foreach ($items as $it): ?>
          <tr>
            <td>
              <?= h2((string)($it['name_snapshot'] ?? 'Item')) ?><br>
              <small><?= h2((string)($it['sku_snapshot'] ?? '')) ?></small>
            </td>
            <td style="text-align: right;"><?= number_format($it['qty_base'] ?? 0, 0) ?></td>
            <td style="text-align: right;"><?= money($it['unit_price'] ?? 0) ?></td>
            <td style="text-align: right;"><?= money($it['line_total'] ?? 0) ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>

      <div class="totals">
        <div class="row">
          <span>Subtotal:</span>
          <span><?= money($sale['subtotal'] ?? 0) ?></span>
        </div>
        <div class="row">
          <span>Discount:</span>
          <span>-<?= money($sale['discount_total'] ?? 0) ?></span>
        </div>
        <div class="row">
          <span>Tax:</span>
          <span><?= money($sale['tax_total'] ?? 0) ?></span>
        </div>
        <div class="row grand-total">
          <span>Grand Total:</span>
          <span><?= money($sale['grand_total'] ?? 0) ?></span>
        </div>
      </div>

      <?php if ($payments): ?>
      <div class="payments">
        <div class="divider"></div>
        <strong>Payments:</strong>
        <?php foreach ($payments as $p): ?>
        <div class="row">
          <span><?= h2((string)$p['method']) ?></span>
          <span><?= money($p['amount'] ?? 0) ?></span>
        </div>
        <?php endforeach; ?>
        <div class="row" style="font-weight: bold;">
          <span>Amount Tendered:</span>
          <span><?= money($sale['amount_paid'] ?? 0) ?></span>
        </div>
        <?php 
        $change_amount = ($sale['amount_paid'] ?? 0) - ($sale['grand_total'] ?? 0);
        if ($change_amount > 0): 
        ?>
        <div class="row" style="font-weight: bold; color: #28a745;">
          <span>Change Given:</span>
          <span><?= money($change_amount) ?></span>
        </div>
        <?php endif; ?>
        <div class="row" style="font-weight: bold;">
          <span>Balance:</span>
          <span><?= money($balance) ?></span>
        </div>
        <?php if ($balance > 0): ?>
        <div class=\"row\" style=\"font-weight: bold; color: #dc3545; margin-top: 5px;\">
          <span style=\"text-transform: uppercase; font-size: 11px;\">*** UNPAID ***</span>
          <span style=\"font-size: 11px;\">Balance Due</span>
        </div>
        <?php endif; ?>
      </div>
      <?php endif; ?>

      <!-- Balance section - always show -->
      <div class="balance-section">
        <div class="divider"></div>
        <div class="row" style="font-weight: bold;">
          <span>Balance:</span>
          <span><?= money($balance) ?></span>
        </div>
        <?php if ($balance > 0): ?>
        <div class="row" style="font-weight: bold; color: #dc3545; margin-top: 5px;">
          <span style="text-transform: uppercase; font-size: 11px;">*** UNPAID ***</span>
          <span style="font-size: 11px;">Balance Due</span>
        </div>
        <?php endif; ?>
      </div>

      <div class="receipt-footer">
        <div class="divider"></div>
        <div><?= nl2br(h2($receipt_settings['receipt_footer'] ?? 'Thank you for your business!')) ?></div>
        <div style="margin-top: 10px; font-size: 10px;">
          <?= date('Y-m-d H:i:s') ?> | Cashier Copy | System Generated
        </div>
      </div>
    </div>

    <script>
      function printReceipt(type) {
        if (type === 'customer') {
          // Hide cashier copy for customer printing
          document.getElementById('cashierCopy').style.display = 'none';
          document.getElementById('customerCopy').style.display = 'block';
        } else {
          // Hide customer copy for cashier printing
          document.getElementById('customerCopy').style.display = 'none';
          document.getElementById('cashierCopy').style.display = 'block';
        }
        
        window.print();
        
        // Show both copies again after printing
        setTimeout(() => {
          document.getElementById('customerCopy').style.display = 'block';
          document.getElementById('cashierCopy').style.display = 'block';
        }, 100);
      }
      
      function openCashDrawer() {
        // Send command to open cash drawer (ESC/POS command)
        const printWindow = window.open('', '_blank');
        printWindow.document.write('<html><body><script>');
        printWindow.document.write('window.onload = function() {');
        printWindow.document.write('  window.print();');
        printWindow.document.write('  window.close();');
        printWindow.document.write('};');
        printWindow.document.write('<\\/script><\\/body><\\/html>');
        printWindow.document.close();
      }
    </script>
  </body>
  </html>
  <?php
  exit;
}

// ======================================================
// 2) POST MODE: build preview from JSON payload (modal)
// ======================================================
if ($method !== 'POST') {
  out_html("<div class='alert alert-danger'>Method not allowed</div>", 405);
}

$in = read_json();
csrf_check((string)($in['csrf'] ?? ''));

$items = $in['items'] ?? [];
$payments = $in['payments'] ?? [];
$doc_type = (string)($in['doc_type'] ?? 'receipt');
$pricing_mode = (string)($in['pricing_mode'] ?? 'retail');
$selling_location_id = (int)($in['selling_location_id'] ?? 0);
$customer_id = $in['customer_id'] ?? null;
$notes = trim((string)($in['notes'] ?? ''));

$customer_id = (is_numeric($customer_id) && (int)$customer_id > 0) ? (int)$customer_id : null;

if (!is_array($items) || empty($items)) out_html("<div class='alert alert-warning'>Cart is empty.</div>", 400);
if (!is_array($payments)) $payments = [];

$normalized = [];
$subtotal = 0.0;
$discount_total = 0.0;

foreach ($items as $idx => $it) {
  $qty = num($it['qty'] ?? 0);
  $price = num($it['unit_price'] ?? 0);
  $disc = num($it['discount'] ?? 0);
  $is_external = !empty($it['is_external']);

  if ($qty <= 0) out_html("<div class='alert alert-danger'>Invalid qty on line ".($idx+1)."</div>", 400);
  if ($price < 0) out_html("<div class='alert alert-danger'>Invalid price on line ".($idx+1)."</div>", 400);
  if ($disc < 0) $disc = 0.0;

  if ($is_external) {
    $name = trim((string)($it['name'] ?? 'External Item'));
    if ($name === '') $name = 'External Item';
    $line_total = max(0.0, ($qty * $price) - $disc);

    $normalized[] = [
      'product_id' => null,
      'sku' => 'EXTERNAL',
      'name' => $name,
      'qty' => $qty,
      'price' => $price,
      'disc' => $disc,
      'line_total' => $line_total,
      'is_external' => 1
    ];

    $subtotal += $qty * $price;
    $discount_total += $disc;
    continue;
  }

  $pid = (int)($it['product_id'] ?? 0);
  if ($pid <= 0) out_html("<div class='alert alert-danger'>Invalid product on line ".($idx+1)."</div>", 400);

  $st = $db->prepare("SELECT sku, name, wholesale_price FROM products WHERE id=? AND is_active=1 LIMIT 1");
  if (!$st) out_html("<div class='alert alert-danger'>DB error: ".h2($db->error)."</div>", 500);
  $st->bind_param('i', $pid);
  $st->execute();
  $p = $st->get_result()->fetch_assoc();
  $st->close();

  if (!$p) out_html("<div class='alert alert-danger'>Product not found (line ".($idx+1).")</div>", 400);

  // wholesale floor
  $min_price = (float)$p['wholesale_price'];
  if ($price < $min_price) out_html("<div class='alert alert-danger'>Price below wholesale not allowed (line ".($idx+1).")</div>", 400);

  $line_total = max(0.0, ($qty * $price) - $disc);

  $normalized[] = [
    'product_id' => $pid,
    'sku' => (string)$p['sku'],
    'name' => (string)$p['name'],
    'qty' => $qty,
    'price' => $price,
    'disc' => $disc,
    'line_total' => $line_total,
    'is_external' => 0
  ];

  $subtotal += $qty * $price;
  $discount_total += $disc;
}

$grand_total = max(0.0, $subtotal - $discount_total);

$amount_paid = 0.0;
$payments_norm = [];
foreach ($payments as $p) {
  $method = trim((string)($p['method'] ?? 'cash'));
  $provider = trim((string)($p['provider'] ?? ''));
  $reference = trim((string)($p['reference'] ?? ''));
  $amt = num($p['amount'] ?? 0);
  if ($amt <= 0) continue;

  if ($method === 'bank' && $reference === '') out_html("<div class='alert alert-danger'>Bank payments require a reference.</div>", 400);

  $payments_norm[] = [
    'method' => $method,
    'provider' => $provider,
    'reference' => $reference,
    'amount' => $amt
  ];
  $amount_paid += $amt;
}

$balance = max(0.0, $grand_total - $amount_paid);
$customer_name = get_customer_name($db, $customer_id);


// Calculate change (if overpaid)
$change = 0.0;
if ($amount_paid > $grand_total) {
  $change = $amount_paid - $grand_total;
}

// Check debt permission
$can_debt = function_exists('user_has_permission') ? (bool) user_has_permission('pos.allow_debt') : false;
if ($balance > 0 && !$can_debt) {
  out_html("<div class='alert alert-danger m-3'><i class='bi bi-exclamation-triangle me-2'></i>Insufficient payment! Balance: " . money($balance) . "<br><small>You don't have permission to allow debt. Please add more payment or reduce the sale amount.</small></div>", 403);
}

?>
<div class="container-fluid p-0">
  <div class="row g-0">
    <div class="col-lg-8">
      <div class="card border-0 shadow-sm">
        <div class="card-header bg-light py-3">
          <div class="d-flex justify-content-between align-items-center">
            <div>
              <h6 class="mb-1">Sale Details</h6>
              <div class="text-muted small">
                <span class="badge bg-primary me-2"><?= h2($doc_type) ?></span>
                <span class="badge bg-info me-2"><?= h2($pricing_mode) ?></span>
                <span class="badge bg-secondary">Location: <?= (int)$selling_location_id ?></span>
              </div>
            </div>
            <div class="text-end">
              <h6 class="mb-1">Customer</h6>
              <div class="text-muted small"><?= h2($customer_name) ?></div>
            </div>
          </div>
        </div>
        <div class="card-body">
          <div class="table-responsive">
            <table class="table table-hover">
              <thead class="table-light">
                <tr>
                  <th width="40%">Item</th>
                  <th width="15%" class="text-end">Qty</th>
                  <th width="15%" class="text-end">Price</th>
                  <th width="15%" class="text-end">Discount</th>
                  <th width="15%" class="text-end">Total</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($normalized as $it): ?>
                  <tr>
                    <td>
                      <div class="fw-semibold"><?= h2($it['name']) ?></div>
                      <div class="text-muted small"><?= h2($it['sku']) ?></div>
                    </td>
                    <td class="text-end">
                      <span class="badge bg-light text-dark"><?= money($it['qty']) ?></span>
                    </td>
                    <td class="text-end"><?= money($it['price']) ?></td>
                    <td class="text-end">
                      <?php if ($it['disc'] > 0): ?>
                        <span class="text-danger">-<?= money($it['disc']) ?></span>
                      <?php else: ?>
                        <span class="text-muted">-</span>
                      <?php endif; ?>
                    </td>
                    <td class="text-end fw-bold"><?= money($it['line_total']) ?></td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>

          <?php if ($notes !== ''): ?>
            <div class="alert alert-light mt-3 mb-0">
              <div class="d-flex align-items-start">
                <i class="bi bi-chat-left-text me-2 text-muted"></i>
                <div>
                  <small class="text-muted">Notes</small>
                  <div class="small"><?= h2($notes) ?></div>
                </div>
              </div>
            </div>
          <?php endif; ?>
        </div>
      </div>
    </div>

    <div class="col-lg-4">
      <div class="card border-0 shadow-sm">
        <div class="card-header bg-light py-3">
          <h6 class="mb-0">Summary</h6>
        </div>
        <div class="card-body">
          <div class="mb-4">
            <div class="d-flex justify-content-between mb-2">
              <span class="text-muted">Subtotal</span>
              <span><?= money($subtotal) ?></span>
            </div>
            <div class="d-flex justify-content-between mb-2">
              <span class="text-muted">Discount</span>
              <span class="text-danger">-<?= money($discount_total) ?></span>
            </div>
            <hr>
            <div class="d-flex justify-content-between mb-3">
              <span class="fw-bold">Grand Total</span>
              <span class="h4 mb-0 text-primary"><?= money($grand_total) ?></span>
            </div>
          </div>

          <div class="mb-4">
            <h6 class="mb-3">Payments</h6>
            <?php if (!$payments_norm): ?>
              <div class="alert alert-warning py-2">
                <small class="text-muted">No payments added</small>
              </div>
            <?php else: ?>
              <?php foreach ($payments_norm as $p): ?>
                <div class="d-flex justify-content-between align-items-center mb-2">
                  <div>
                    <div class="small fw-semibold"><?= h2($p['method']) ?></div>
                    <?php if ($p['provider']): ?>
                      <div class="text-muted small"><?= h2($p['provider']) ?></div>
                    <?php endif; ?>
                    <?php if ($p['reference']): ?>
                      <div class="text-muted small"><?= h2($p['reference']) ?></div>
                    <?php endif; ?>
                  </div>
                  <span class="fw-bold"><?= money($p['amount']) ?></span>
                </div>
              <?php endforeach; ?>
            <?php endif; ?>
          </div>

          <div class="border-top pt-3">
            <div class="d-flex justify-content-between mb-2">
              <span class="text-muted">Amount Paid</span>
              <span class="fw-semibold"><?= money($amount_paid) ?></span>
            </div>
            <div class="d-flex justify-content-between mb-2">
              <span class="text-muted">Balance</span>
              <span class="h5 mb-0 <?= $balance > 0 ? 'text-warning' : 'text-success' ?>">
                <?= money($balance) ?>
              </span>
            </div>
            <?php if ($change > 0): ?>
              <div class="d-flex justify-content-between mb-2">
                <span class="text-success">Change</span>
                <span class="h5 mb-0 text-success">
                  <?= money($change) ?>
                </span>
              </div>
            <?php endif; ?>
          </div>

          <hr>

        </div>
      </div>
    </div>
  </div>
</div>
