<?php
// modules/pos/sale_view.php
require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/rbac.php';

require_permission('pos.view');

header('Content-Type: text/html; charset=utf-8');

$db = $GLOBALS['db'] ?? null;
if (!$db instanceof mysqli) {
    die('Database not available');
}

$sale_id = (int)($_GET['id'] ?? 0);
if ($sale_id <= 0) {
    die('Sale ID required');
}

// Fetch sale details
$sql = "SELECT s.*, l.name as location_name, u.full_name as created_by_name
        FROM sales s
        LEFT JOIN locations l ON s.selling_location_id = l.id
        LEFT JOIN users u ON s.created_by = u.id
        WHERE s.id = ? LIMIT 1";
$stmt = $db->prepare($sql);
$stmt->bind_param('i', $sale_id);
$stmt->execute();
$result = $stmt->get_result();
$sale = $result->fetch_assoc();
$stmt->close();

if (!$sale) {
    die('Sale not found');
}

// Fetch sale items
$sql = "SELECT si.*, p.name as current_product_name
        FROM sale_items si
        LEFT JOIN products p ON si.product_id = p.id
        WHERE si.sale_id = ?
        ORDER BY si.id";
$stmt = $db->prepare($sql);
$stmt->bind_param('i', $sale_id);
$stmt->execute();
$result = $stmt->get_result();
$items = $result->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Fetch sale payments
$sql = "SELECT * FROM sale_payments WHERE sale_id = ? ORDER BY id";
$stmt = $db->prepare($sql);
$stmt->bind_param('i', $sale_id);
$stmt->execute();
$result = $stmt->get_result();
$payments = $result->fetch_all(MYSQLI_ASSOC);
$stmt->close();

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sale Receipt #<?= htmlspecialchars($sale['doc_no']) ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        @media print {
            .no-print { display: none !important; }
            body { font-size: 12px; }
        }
        .receipt-header {
            text-align: center;
            border-bottom: 2px solid #000;
            padding-bottom: 20px;
            margin-bottom: 20px;
        }
        .receipt-footer {
            text-align: center;
            border-top: 2px solid #000;
            padding-top: 20px;
            margin-top: 30px;
        }
    </style>
</head>
<body>
    <div class="container mt-4">
        <div class="row">
            <div class="col-md-8 mx-auto">
                <div class="card">
                    <div class="card-body">
                        <!-- Receipt Header -->
                        <div class="receipt-header">
                            <h3><?= htmlspecialchars($sale['doc_type'] === 'receipt' ? 'RECEIPT' : strtoupper($sale['doc_type'])) ?></h3>
                            <h5>#<?= htmlspecialchars($sale['doc_no']) ?></h5>
                            <p class="mb-1"><?= date('F j, Y, g:i A', strtotime($sale['created_at'])) ?></p>
                        </div>

                        <!-- Sale Info -->
                        <div class="row mb-3">
                            <div class="col-6">
                                <strong>Location:</strong><br>
                                <?= htmlspecialchars($sale['location_name'] ?? 'N/A') ?>
                            </div>
                            <div class="col-6">
                                <strong>Staff:</strong><br>
                                <?= htmlspecialchars($sale['created_by_name'] ?? 'N/A') ?>
                            </div>
                        </div>

                        <?php if (!empty($sale['customer_id'])): ?>
                        <div class="row mb-3">
                            <div class="col-12">
                                <strong>Customer ID:</strong> <?= $sale['customer_id'] ?>
                            </div>
                        </div>
                        <?php endif; ?>

                        <!-- Items Table -->
                        <table class="table table-sm">
                            <thead>
                                <tr>
                                    <th>Item</th>
                                    <th class="text-end">Qty</th>
                                    <th class="text-end">Price</th>
                                    <th class="text-end">Discount</th>
                                    <th class="text-end">Total</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($items as $item): ?>
                                <tr>
                                    <td>
                                        <?= htmlspecialchars($item['name_snapshot']) ?>
                                        <?php if (!empty($item['sku_snapshot'])): ?>
                                            <br><small class="text-muted">SKU: <?= htmlspecialchars($item['sku_snapshot']) ?></small>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-end"><?= number_format($item['qty_base']) ?></td>
                                    <td class="text-end"><?= number_format($item['unit_price'], 2) ?></td>
                                    <td class="text-end"><?= number_format($item['discount_amount'], 2) ?></td>
                                    <td class="text-end"><?= number_format($item['line_total'], 2) ?></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                            <tfoot>
                                <tr>
                                    <th colspan="4">Subtotal:</th>
                                    <th class="text-end"><?= number_format($sale['subtotal'], 2) ?></th>
                                </tr>
                                <tr>
                                    <th colspan="4">Discount:</th>
                                    <th class="text-end"><?= number_format($sale['discount_total'], 2) ?></th>
                                </tr>
                                <tr>
                                    <th colspan="4">Tax:</th>
                                    <th class="text-end"><?= number_format($sale['tax_total'], 2) ?></th>
                                </tr>
                                <tr class="table-primary">
                                    <th colspan="4">Grand Total:</th>
                                    <th class="text-end"><?= number_format($sale['grand_total'], 2) ?></th>
                                </tr>
                            </tfoot>
                        </table>

                        <!-- Payments -->
                        <h6>Payments</h6>
                        <table class="table table-sm">
                            <thead>
                                <tr>
                                    <th>Method</th>
                                    <th>Reference</th>
                                    <th class="text-end">Amount</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($payments as $payment): ?>
                                <tr>
                                    <td><?= ucfirst(htmlspecialchars($payment['method'])) ?></td>
                                    <td><?= htmlspecialchars($payment['reference'] ?? '-') ?></td>
                                    <td class="text-end"><?= number_format($payment['amount'], 2) ?></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                            <tfoot>
                                <tr>
                                    <th colspan="2">Amount Paid:</th>
                                    <th class="text-end"><?= number_format($sale['amount_paid'], 2) ?></th>
                                </tr>
                                <tr>
                                    <th colspan="2">Balance:</th>
                                    <th class="text-end"><?= number_format($sale['balance'], 2) ?></th>
                                </tr>
                            </tfoot>
                        </table>

                        <?php if (!empty($sale['notes'])): ?>
                        <div class="mb-3">
                            <strong>Notes:</strong><br>
                            <?= nl2br(htmlspecialchars($sale['notes'])) ?>
                        </div>
                        <?php endif; ?>

                        <!-- Status Badges -->
                        <div class="mb-3">
                            <span class="badge bg-<?= $sale['payment_status'] === 'paid' ? 'success' : ($sale['payment_status'] === 'partial' ? 'warning' : 'danger') ?>">
                                <?= ucfirst($sale['payment_status']) ?>
                            </span>
                            <span class="badge bg-info"><?= ucfirst($sale['pricing_mode']) ?></span>
                        </div>

                        <!-- Receipt Footer -->
                        <div class="receipt-footer">
                            <p class="mb-1">Thank you for your business!</p>
                            <small>This is a computer-generated receipt</small>
                        </div>

                        <!-- Action Buttons -->
                        <div class="no-print text-center mt-4">
                            <button onclick="window.print()" class="btn btn-primary me-2">
                                <i class="fas fa-print"></i> Print Receipt
                            </button>
                            <?php if ($sale['status'] !== 'confirmed'): ?>
                            <button onclick="editSale()" class="btn btn-warning me-2">
                                <i class="fas fa-edit"></i> Edit Sale
                            </button>
                            <?php endif; ?>
                            <a href="pos.php" class="btn btn-secondary">
                                <i class="fas fa-arrow-left"></i> Back to POS
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://kit.fontawesome.com/a076d05399.js" crossorigin="anonymous"></script>
    
    <script>
    function editSale() {
        // Store sale data in sessionStorage for the POS form to retrieve
        const editData = {
            sale_id: <?= $sale_id ?>,
            doc_type: '<?= htmlspecialchars($sale['doc_type']) ?>',
            selling_location_id: <?= $sale['selling_location_id'] ?>,
            customer_id: <?= $sale['customer_id'] ?: 'null' ?>,
            pricing_mode: '<?= htmlspecialchars($sale['pricing_mode']) ?>',
            notes: '<?= htmlspecialchars($sale['notes'] ?? '') ?>',
            currency: '<?= htmlspecialchars($sale['currency']) ?>',
            items: <?= json_encode(array_map(function($item) {
                return [
                    'product_id' => $item['product_id'],
                    'name' => $item['name_snapshot'],
                    'sku' => $item['sku_snapshot'],
                    'qty_base' => $item['qty_base'],
                    'unit_price' => $item['unit_price'],
                    'discount_amount' => $item['discount_amount'],
                    'is_external' => $item['is_external'],
                    'external_cost' => $item['external_cost'],
                    'external_source' => $item['external_source'],
                    'qty_unit' => $item['unit_type_snapshot']
                ];
            }, $items)) ?>,
            payments: <?= json_encode(array_map(function($payment) {
                return [
                    'method' => $payment['method'],
                    'amount' => $payment['amount'],
                    'reference' => $payment['reference'] ?? '',
                    'provider' => $payment['provider'] ?? ''
                ];
            }, $payments)) ?>
        };
        
        sessionStorage.setItem('posEditData', JSON.stringify(editData));
        
        // Redirect to POS with edit flag
        window.location.href = 'pos.php?edit=<?= $sale_id ?>';
    }
    </script>
</body>
</html>