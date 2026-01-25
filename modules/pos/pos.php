<?php
// modules/pos/pos.php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/rbac.php';
require_once __DIR__ . '/../../includes/helpers.php';

require_permission('pos.create');

$db = $GLOBALS['db'] ?? null;
$BASE_URL = rtrim((string)($GLOBALS['BASE_URL'] ?? ''), '/');

$page_title = "POS";
$page_subtitle = "Fast sales • Barcode ready • Location stock aware";

if (!$db instanceof mysqli) {
  http_response_code(500);
  die("DB not available");
}

// permission helper
function has_perm(string $p): bool {
  return function_exists('user_has_permission') ? (bool)user_has_permission($p) : true;
}

// CSRF
if (session_status() === PHP_SESSION_NONE) session_start();
if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(32));
$csrf = $_SESSION['csrf'];

// Locations
$locations = [];
$res = $db->query("SELECT id, name FROM locations WHERE is_active=1 ORDER BY name ASC");
if ($res) {
  while ($row = $res->fetch_assoc()) $locations[] = $row;
  $res->free();
}
$default_location_id = $locations[0]['id'] ?? '';
foreach ($locations as $loc) {
  if (mb_strtolower((string)$loc['name']) === 'counter') {
    $default_location_id = $loc['id'];
    break;
  }
}

// Light customers list
$customers = [];
$customerLightLimit = 150;
$cq = $db->prepare("SELECT id, name, phone, category_id FROM customers WHERE is_active=1 ORDER BY name ASC LIMIT ?");
if ($cq) {
  $cq->bind_param('i', $customerLightLimit);
  $cq->execute();
  $cr = $cq->get_result();
  if ($cr) {
    while ($r = $cr->fetch_assoc()) $customers[] = $r;
    $cr->free();
  }
  $cq->close();
}

$can_discount  = has_perm('pos.apply_discount');
$can_editprice = has_perm('pos.edit_price');
$can_invoice   = has_perm('pos.invoice');
$can_dn        = has_perm('pos.delivery_note');

require_once __DIR__ . '/../../templates/layout/header.php';
?>

<div class="app-shell">
  <?php require_once __DIR__ . '/../../templates/layout/sidebar.php'; ?>

  <div class="app-content">
    <?php require_once __DIR__ . '/../../templates/layout/topbar.php'; ?>

    <main class="page-wrap">

<link rel="stylesheet" href="<?= h($BASE_URL) ?>/assets/css/pos.css?v=2">

<section class="pos-wrap">
  <div class="pos-header">
    <div class="d-flex align-items-center justify-content-between flex-wrap gap-2">
      <div>
        <h4 class="mb-0"><?= h($page_title) ?></h4>
        <div class="text-muted small"><?= h($page_subtitle) ?></div>
      </div>
      <div class="d-flex gap-2">
        <button class="btn btn-outline-secondary btn-sm" id="btnNewSale" type="button">
          <i class="bi bi-arrow-clockwise"></i> New
        </button>
        <a class="btn btn-outline-primary btn-sm" href="<?= h($BASE_URL) ?>/modules/pos/pos_list.php">
          <i class="bi bi-receipt"></i> Sales
        </a>
      </div>
    </div>
  </div>

  <div class="row g-3">
    <!-- LEFT -->
    <div class="col-12 col-xl-8">
      <div class="card shadow-sm pos-card">
        <div class="card-body">

          <!-- TOP CONTROLS -->
          <div class="row g-2 align-items-end">
            <div class="col-12 col-md-3">
              <label class="form-label small text-muted mb-1">Document</label>
              <select class="form-select" id="doc_type">
                <option value="receipt">Receipt</option>
                <?php if ($can_invoice): ?>
                  <option value="invoice">Invoice</option>
                <?php endif; ?>
                <?php if ($can_dn): ?>
                  <option value="delivery_note">Delivery Note</option>
                <?php endif; ?>
              </select>
            </div>

            <div class="col-12 col-md-3">
              <label class="form-label small text-muted mb-1">Location</label>
              <select class="form-select" id="selling_location_id">
                <?php foreach ($locations as $loc): ?>
                  <option value="<?= h($loc['id']) ?>" <?= ((string)$loc['id'] === (string)$default_location_id) ? 'selected' : '' ?>>
                    <?= h($loc['name']) ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </div>

            <div class="col-12 col-md-3">
              <label class="form-label small text-muted mb-1">Pricing</label>
              <div class="btn-group w-100" role="group">
                <input type="radio" class="btn-check" name="pricing_mode" id="pm_retail" value="retail" checked>
                <label class="btn btn-outline-primary" for="pm_retail">Retail</label>
                <input type="radio" class="btn-check" name="pricing_mode" id="pm_wholesale" value="wholesale">
                <label class="btn btn-outline-primary" for="pm_wholesale">Wholesale</label>
              </div>
            </div>

            <div class="col-12 col-md-3">
              <label class="form-label small text-muted mb-1">Notes</label>
              <input type="text" class="form-control" id="sale_notes" placeholder="Optional...">
            </div>
          </div>

          <hr class="my-3">

          <!-- SEARCH BAR (sticky feel) -->
          <div class="pos-searchbar">
            <div class="input-group input-group-lg">
              <span class="input-group-text bg-white">
                <i class="bi bi-upc-scan"></i>
              </span>
              <input type="text" class="form-control" id="product_search" placeholder="Scan barcode or type SKU / name / tag...">
              <button class="btn btn-outline-dark" type="button" id="btnAddExternal">
                <i class="bi bi-box-arrow-up-right"></i>
                <span class="d-none d-md-inline">External Item</span>
              </button>
            </div>

            <!-- Live results dropdown (pos.js fills it) -->
            <div id="searchResultsWrap" class="pos-results d-none">
              <div class="d-flex justify-content-between align-items-center px-2 pt-2">
                <div class="small text-muted">Suggestions</div>
                <button class="btn btn-sm btn-outline-secondary" type="button" id="btnHideResults">Hide</button>
              </div>
              <div id="searchResults" class="list-group list-group-flush"></div>
            </div>

            <div class="small text-muted mt-2">
              Tip: barcode scanners will auto-type and press Enter. You can also click a suggestion.
            </div>
          </div>

          <hr class="my-3">

          <!-- CUSTOMER -->
          <div class="row g-2 align-items-end">
            <div class="col-12 col-md-10">
              <label class="form-label small text-muted mb-1">Customer (optional)</label>
              <div class="input-group">
                <select class="form-select" id="customer_id">
                  <option value="">Walk-in Customer</option>
                  <?php foreach ($customers as $c): ?>
                    <option value="<?= h($c['id']) ?>"
                            data-phone="<?= h($c['phone'] ?? '') ?>"
                            data-category-id="<?= h($c['category_id'] ?? '') ?>">
                      <?= h($c['name']) ?><?= !empty($c['phone']) ? ' • ' . h($c['phone']) : '' ?>
                    </option>
                  <?php endforeach; ?>
                </select>
                <button class="btn btn-outline-secondary" type="button" id="btnCustomerSearch">
                  <i class="bi bi-search"></i>
                </button>
              </div>
            </div>

            <div class="col-12 col-md-2 d-grid">
              <label class="form-label small text-muted mb-1">&nbsp;</label>
              <button class="btn btn-outline-primary" type="button" id="btnPayments" disabled>
                <i class="bi bi-cash-coin"></i> Pay
              </button>
            </div>
          </div>

          <hr class="my-3">

          <!-- CART (modern list instead of tight table) -->
          <div class="pos-cart">
            <div class="d-flex align-items-center justify-content-between mb-2">
              <div class="fw-semibold">Cart</div>
              <div class="small text-muted">
                Edit qty, price (if allowed), discount (if allowed)
              </div>
            </div>

            <div id="cartBody" class="pos-cart-list">
              <table class="table">
                <thead>
                  <tr>
                    <th>Item</th>
                    <th class="text-end" style="width: 120px">Qty</th>
                    <th class="text-end" style="width: 140px">Price</th>
                    <th class="text-end" style="width: 120px">Discount</th>
                    <th class="text-end" style="width: 140px">Total</th>
                    <th style="width: 44px"></th>
                  </tr>
                </thead>
                <tbody>
                  <tr id="cartEmptyRow" class="pos-empty">
                    <td colspan="6">
                      <div class="text-muted">Add items to start a sale.</div>
                    </td>
                  </tr>
                </tbody>
              </table>
            </div>
          </div>

        </div>
      </div>
    </div>

    <!-- RIGHT -->
    <div class="col-12 col-xl-4">
      <div class="card shadow-sm pos-card">
        <div class="card-body">
          <div class="d-flex justify-content-between align-items-center mb-3">
            <div class="fw-bold">Checkout</div>
            <span class="badge bg-light text-dark border">Live Totals</span>
          </div>

          <div class="pos-totals">
            <div class="d-flex justify-content-between">
              <span class="text-muted">Subtotal</span>
              <span class="fw-semibold" id="t_subtotal">0</span>
            </div>
            <div class="d-flex justify-content-between">
              <span class="text-muted">Discount</span>
              <span class="fw-semibold text-danger" id="t_discount">0</span>
            </div>
            <hr>
            <div class="d-flex justify-content-between align-items-center">
              <span class="text-muted">Grand Total</span>
              <span class="fw-bold fs-4" id="t_grand">0</span>
            </div>

            <div class="mt-3">
              <div class="d-flex justify-content-between">
                <span class="text-muted">Paid</span>
                <span class="fw-semibold" id="t_paid">0</span>
              </div>
              <div class="d-flex justify-content-between">
                <span class="text-muted">Balance</span>
                <span class="fw-bold" id="t_balance">0</span>
              </div>
            </div>
          </div>

          <div class="d-grid gap-2 mt-4">
            <button class="btn btn-success btn-lg" type="button" id="btnConfirm" disabled>
              <i class="bi bi-check2-circle"></i> Confirm Sale
            </button>
            <button class="btn btn-outline-warning" type="button" id="btnHoldOrder">
              <i class="bi bi-clock"></i> Hold Order
            </button>
          </div>

          <div class="pos-shortcuts mt-4">
            <div class="small text-muted mb-2">Shortcuts</div>
            <div class="d-flex flex-wrap gap-2">
              <span class="badge bg-light text-dark border"><kbd>Enter</kbd> add first result</span>
              <span class="badge bg-light text-dark border"><kbd>Ctrl</kbd> + <kbd>P</kbd> payments</span>
              <span class="badge bg-light text-dark border"><kbd>Ctrl</kbd> + <kbd>Enter</kbd> confirm</span>
              <span class="badge bg-light text-dark border"><kbd>Esc</kbd> hide suggestions</span>
            </div>
          </div>

        </div>
      </div>
    </div>
  </div>
</section>

<!-- Payment Modal -->
<div class="modal fade" id="paymentModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-lg modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title">Add Payment</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">

        <div class="row g-2">
          <div class="col-12 col-md-4">
            <label class="form-label">Method</label>
            <select class="form-select" id="pay_method">
              <option value="cash">Cash</option>
              <option value="mobile_money">Mobile Money</option>
              <option value="bank">Bank</option>
            </select>
          </div>

          <div class="col-12 col-md-4">
            <label class="form-label">Provider (optional)</label>
            <select class="form-select" id="pay_provider">
              <option value="">—</option>
              <option value="MTN">MTN</option>
              <option value="Airtel">Airtel</option>
            </select>
          </div>

          <div class="col-12 col-md-4">
            <label class="form-label">Amount</label>
            <input type="number" class="form-control" id="pay_amount" min="0" step="0.01" placeholder="0">
          </div>

          <div class="col-12">
            <label class="form-label">Reference (Bank required)</label>
            <input type="text" class="form-control" id="pay_reference" placeholder="Transaction / Deposit reference">
            <div class="form-text">Bank payments require a reference.</div>
          </div>
        </div>

        <hr>
        <div class="table-responsive">
          <table class="table table-sm">
            <thead class="table-light">
              <tr>
                <th>Method</th>
                <th>Provider</th>
                <th>Reference</th>
                <th class="text-end">Amount</th>
                <th style="width:60px;"></th>
              </tr>
            </thead>
            <tbody id="paymentsBody">
              <tr id="paymentsEmptyRow">
                <td colspan="5" class="text-center text-muted py-3">No payments added yet.</td>
              </tr>
            </tbody>
          </table>
        </div>

      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Done</button>
        <button type="button" class="btn btn-primary" id="btnAddPaymentRow">
          <i class="bi bi-plus-circle"></i> Add Payment
        </button>
      </div>
    </div>
  </div>
</div>

<!-- External Item Modal (kept) -->
<div class="modal fade" id="externalModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-lg modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title">Add External/B2B Item</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">

        <div class="row g-2">
          <div class="col-12 col-md-6">
            <label class="form-label">Item Name</label>
            <input type="text" class="form-control" id="ext_name" placeholder="e.g., Special item sourced outside">
          </div>
          <div class="col-12 col-md-3">
            <label class="form-label">Qty</label>
            <input type="number" class="form-control" id="ext_qty" min="1" step="1" value="1">
          </div>
          <div class="col-12 col-md-3">
            <label class="form-label">Unit</label>
            <input type="text" class="form-control" id="ext_unit" placeholder="pcs / kg / unit">
          </div>

          <div class="col-12 col-md-4">
            <label class="form-label">Cost</label>
            <input type="number" class="form-control" id="ext_cost" min="0" step="0.01" placeholder="0">
          </div>
          <div class="col-12 col-md-4">
            <label class="form-label">Selling price</label>
            <input type="number" class="form-control" id="ext_price" min="0" step="0.01" placeholder="0">
          </div>
          <div class="col-12 col-md-4">
            <label class="form-label">Source (optional)</label>
            <input type="text" class="form-control" id="ext_source" placeholder="Shop name">
          </div>
        </div>

        <div class="alert alert-info mt-3 mb-0">
          External items <b>do not affect stock</b> but appear on receipts and reports separately.
        </div>

      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
        <button type="button" class="btn btn-dark" id="btnAddExternalRow">
          <i class="bi bi-plus-circle"></i> Add to Cart
        </button>
      </div>
    </div>
  </div>
</div>

<script>
  window.POS_CONFIG = {
    baseUrl: "<?= h($BASE_URL) ?>",
    apiUrl: "<?= h($BASE_URL) ?>/modules/pos/pos_api.php",
    csrf: "<?= h($csrf) ?>",
    perms: {
      discount: <?= $can_discount ? 'true' : 'false' ?>,
      editPrice: <?= $can_editprice ? 'true' : 'false' ?>
    }
  };
</script>
<script src="<?= h($BASE_URL) ?>/assets/js/pos.js?v=2"></script>

    </main>
  </div>
</div>

<?php require_once __DIR__ . '/../../templates/layout/footer.php'; ?>
