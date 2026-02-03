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
$sql = "SELECT si.*, p.name as current_product_name, p.cost_price as product_cost_price, si.external_cost, si.is_external
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
                            <div class="mt-2">
                                <span id="view-indicator" class="badge bg-info">
                                    <i class="bi bi-eye"></i> <span id="view-count">Loading...</span>
                                </span>
                            </div>
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
                                    <th class="text-end">Cost</th>
                                    <th class="text-end">Discount</th>
                                    <th class="text-end">Total</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php 
                                $total_cost = 0;
                                foreach ($items as $item): 
                                    // Determine the cost based on product type
                                    if ($item['is_external'] == 1) {
                                        // External product - use external_cost
                                        $product_cost = $item['external_cost'] ?? 0;
                                        $cost_source = "External";
                                    } else {
                                        // Regular product - use product cost_price
                                        $product_cost = $item['product_cost_price'] ?? 0;
                                        $cost_source = "Product";
                                    }
                                    
                                    // Use the same quantity logic as profit report: qty_base if not zero, otherwise qty_input
                                    $quantity = ($item['qty_base'] != 0) ? $item['qty_base'] : $item['qty_input'];
                                    $item_cost = $quantity * $product_cost;
                                    $total_cost += $item_cost;
                                ?>
                                <tr>
                                    <td>
                                        <?= htmlspecialchars($item['name_snapshot']) ?>
                                        <?php if (!empty($item['sku_snapshot'])): ?>
                                            <br><small class="text-muted">SKU: <?= htmlspecialchars($item['sku_snapshot']) ?></small>
                                        <?php endif; ?>
                                        <?php if ($item['is_external'] == 1): ?>
                                            <br><small class="text-info">📦 External Product</small>
                                        <?php endif; ?>
                                        <?php if ($product_cost == 0): ?>
                                            <br><small class="text-warning">⚠️ No cost price set (<?= $cost_source ?>)</small>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-end"><?= number_format($quantity) ?></td>
                                    <td class="text-end"><?= number_format($item['unit_price'], 2) ?></td>
                                    <td class="text-end">
                                        <?php if ($product_cost == 0): ?>
                                            <span class="text-danger"><?= number_format($product_cost, 2) ?></span>
                                        <?php else: ?>
                                            <?= number_format($product_cost, 2) ?>
                                        <?php endif; ?>
                                        <br><small class="text-muted"><?= $cost_source ?></small>
                                    </td>
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

                        <!-- Profit Summary -->
                        <div class="mt-4 p-3 bg-light rounded">
                            <h6 class="mb-3">Profit Analysis</h6>
                            <div class="row">
                                <div class="col-md-4">
                                    <small class="text-muted">Total Revenue</small>
                                    <div class="fw-bold text-success"><?= number_format($sale['grand_total'], 2) ?></div>
                                </div>
                                <div class="col-md-4">
                                    <small class="text-muted">Cost of Goods (COGS)</small>
                                    <div class="fw-bold text-danger"><?= number_format($total_cost, 2) ?></div>
                                </div>
                                <div class="col-md-4">
                                    <small class="text-muted">Gross Profit</small>
                                    <div class="fw-bold text-primary"><?= number_format($sale['grand_total'] - $total_cost, 2) ?></div>
                                </div>
                            </div>
                            <div class="mt-2">
                                <small class="text-muted">Profit Margin: </small>
                                <span class="fw-bold"><?= number_format((($sale['grand_total'] - $total_cost) / $sale['grand_total']) * 100, 1) ?>%</span>
                            </div>
                        </div>

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
    // Track view and update display
    document.addEventListener('DOMContentLoaded', function() {
        const saleId = <?= $sale_id ?>;
        const viewedSales = JSON.parse(localStorage.getItem('viewedSales') || '{}');
        
        // Get current view data or initialize
        let viewData = viewedSales[saleId];
        if (!viewData) {
            viewData = {
                count: 0,
                firstViewed: new Date().toISOString(),
                lastViewed: new Date().toISOString()
            };
        } else {
            // Parse existing data
            if (typeof viewData === 'string') {
                // Old format - just a timestamp
                viewData = {
                    count: 1,
                    firstViewed: viewData,
                    lastViewed: new Date().toISOString()
                };
            } else {
                // New format - increment count and update last viewed
                viewData.count = (viewData.count || 1) + 1;
                viewData.lastViewed = new Date().toISOString();
            }
        }
        
        // Save updated data
        viewedSales[saleId] = viewData;
        localStorage.setItem('viewedSales', JSON.stringify(viewedSales));
        
        // Update display
        updateViewDisplay(viewData);
        
        // Notify parent window (if opened from sales report)
        if (window.opener) {
            try {
                window.opener.markAsViewed(saleId);
            } catch (e) {
                // Cross-origin or other security restrictions
                console.log('Could not notify parent window');
            }
        }
    });
    
    function updateViewDisplay(viewData) {
        const viewCount = document.getElementById('view-count');
        const viewIndicator = document.getElementById('view-indicator');
        
        if (viewCount && viewIndicator) {
            const count = viewData.count || 1;
            const lastViewed = new Date(viewData.lastViewed);
            const timeAgo = getTimeAgo(lastViewed);
            
            if (count === 1) {
                viewCount.textContent = 'First view';
                viewIndicator.className = 'badge bg-primary';
            } else {
                viewCount.textContent = `Viewed ${count} times • ${timeAgo}`;
                viewIndicator.className = 'badge bg-success';
            }
        }
    }
    
    function getTimeAgo(date) {
        const seconds = Math.floor((new Date() - date) / 1000);
        
        let interval = seconds / 31536000;
        if (interval > 1) return Math.floor(interval) + " year" + (Math.floor(interval) > 1 ? "s" : "") + " ago";
        
        interval = seconds / 2592000;
        if (interval > 1) return Math.floor(interval) + " month" + (Math.floor(interval) > 1 ? "s" : "") + " ago";
        
        interval = seconds / 86400;
        if (interval > 1) return Math.floor(interval) + " day" + (Math.floor(interval) > 1 ? "s" : "") + " ago";
        
        interval = seconds / 3600;
        if (interval > 1) return Math.floor(interval) + " hour" + (Math.floor(interval) > 1 ? "s" : "") + " ago";
        
        interval = seconds / 60;
        if (interval > 1) return Math.floor(interval) + " minute" + (Math.floor(interval) > 1 ? "s" : "") + " ago";
        
        return "Just now";
    }
    
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