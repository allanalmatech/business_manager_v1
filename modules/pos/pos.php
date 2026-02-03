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
$page_subtitle = "Fast sales • Live search • Quick items • Payments • Print";

if (!$db instanceof mysqli) {
  http_response_code(500);
  die("DB not available");
}

function has_perm(string $p): bool {
  return function_exists('user_has_permission') ? (bool) user_has_permission($p) : true;
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

// Light customers
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

// Permissions
$can_discount  = has_perm('pos.apply_discount');
$can_editprice = has_perm('pos.edit_price');
$can_invoice   = has_perm('pos.invoice');
$can_dn        = has_perm('pos.delivery_note');
$can_debt      = has_perm('pos.allow_debt');

require_once __DIR__ . '/../../templates/layout/header.php';
?>

<div class="app-shell">
  <?php require_once __DIR__ . '/../../templates/layout/sidebar.php'; ?>

  <div class="app-content">
    <?php require_once __DIR__ . '/../../templates/layout/topbar.php'; ?>

    <main class="page-wrap">

<link rel="stylesheet" href="<?= h($BASE_URL) ?>/assets/css/pos.css?v=2">

<div class="pos-shell">

  <!-- LEFT -->
  <section class="pos-left">

    <div class="pos-topbar">
      <div class="pos-tabs" id="posCategories">
        <button type="button" class="pos-tab active" data-cat="">Popular</button>
        <button type="button" class="pos-tab" data-cat="fashion">Fashion</button>
        <button type="button" class="pos-tab" data-cat="food">Food</button>
        <button type="button" class="pos-tab" data-cat="all">All</button>
      </div>

      <div class="pos-search">
        <i class="bi bi-search"></i>
        <input type="text" id="product_search" placeholder="Search SKU, name, barcode, tag…" autocomplete="off">
      </div>

      <div class="pos-meta">
        <select class="form-select form-select-sm" id="selling_location_id">
          <?php foreach ($locations as $loc): ?>
            <option value="<?= h($loc['id']) ?>" <?= ((string)$loc['id'] === (string)$default_location_id) ? 'selected' : '' ?>>
              <?= h($loc['name']) ?>
            </option>
          <?php endforeach; ?>
        </select>

        <div class="btn-group" role="group">
          <input type="radio" class="btn-check" name="pricing_mode" id="pm_retail" value="retail" checked>
          <label class="btn btn-outline-primary btn-sm" for="pm_retail">Retail</label>

          <input type="radio" class="btn-check" name="pricing_mode" id="pm_wholesale" value="wholesale">
          <label class="btn btn-outline-primary btn-sm" for="pm_wholesale">Wholesale</label>
        </div>
      </div>
    </div>

    <div id="searchResultsWrap" class="pos-suggest d-none">
      <div class="pos-suggest-head">
        <div class="fw-semibold">Suggestions</div>
        <button class="btn btn-sm btn-light" type="button" id="btnHideResults">Hide</button>
      </div>
      <div id="searchResults" class="list-group"></div>
    </div>

    <div class="pos-products">
      <div class="pos-products-head">
        <div>
          <div class="pos-h1">All Products</div>
          <div class="pos-sub">Click item to add to cart</div>
        </div>

        <div class="pos-actions">
          <button class="btn btn-outline-dark btn-sm" type="button" id="btnAddExternal">
            <i class="bi bi-box-arrow-up-right"></i> External/B2B
          </button>

          <button class="btn btn-outline-secondary btn-sm" id="btnNewSale" type="button">
            <i class="bi bi-arrow-clockwise"></i> New
          </button>
        </div>
      </div>

      <div id="quickItems" class="pos-grid"></div>
    </div>

  </section>

  <!-- RIGHT -->
  <aside class="pos-right">

    <div class="pos-cart-head">
      <div>
        <div class="pos-h2">Cart Details</div>
        <div class="pos-sub">Edit qty / price / discount (if allowed)</div>
      </div>

      <select class="form-select form-select-sm" id="doc_type">
        <option value="receipt">Receipt</option>
        <?php if ($can_invoice): ?><option value="invoice">Invoice</option><?php endif; ?>
        <?php if ($can_dn): ?><option value="delivery_note">Delivery Note</option><?php endif; ?>
      </select>
    </div>

    <div id="cartPanel" class="pos-cart-list">
      <div id="cartEmptyRow" class="pos-empty">Add items to start a sale.</div>
    </div>

    <div class="pos-summary">
      <div class="pos-row">
        <span class="muted">Subtotal</span>
        <span class="fw-semibold" id="t_subtotal">0</span>
      </div>
      <div class="pos-row">
        <span class="muted">Discount</span>
        <span class="fw-semibold" id="t_discount">0</span>
      </div>
      <div class="pos-divider"></div>
      <div class="pos-row">
        <span class="muted">Grand Total</span>
        <span class="pos-grand" id="t_grand">0</span>
      </div>

      <div class="pos-divider"></div>

      <div class="mb-2">
        <label class="form-label small mb-1">Customer (optional)</label>
        <div class="input-group input-group-sm">
          <select class="form-select" id="customer_id">
            <option value="">Walk-in Customer</option>
            <?php foreach ($customers as $c): ?>
              <option value="<?= h($c['id']) ?>"><?= h($c['name']) ?></option>
            <?php endforeach; ?>
          </select>
          <button class="btn btn-outline-secondary" type="button" id="btnCustomerSearch">
            <i class="bi bi-search"></i>
          </button>
        </div>
      </div>

      <div class="mb-2">
        <label class="form-label small mb-1">Notes</label>
        <input type="text" class="form-control form-control-sm" id="sale_notes" placeholder="Optional notes...">
      </div>

      <div class="pos-paybox">
        <div class="fw-semibold mb-2">Payments</div>

        <div class="row g-2 align-items-end">
          <div class="col-4">
            <label class="form-label small mb-1">Method</label>
            <select class="form-select form-select-sm" id="pay_method">
              <option value="cash">Cash</option>
              <option value="mobile_money">Mobile Money</option>
              <option value="bank">Bank</option>
            </select>
          </div>
          <div class="col-4">
            <label class="form-label small mb-1">Provider</label>
            <select class="form-select form-select-sm" id="pay_provider">
              <option value="">—</option>
              <option value="MTN">MTN</option>
              <option value="Airtel">Airtel</option>
            </select>
          </div>
          <div class="col-4">
            <label class="form-label small mb-1">Amount</label>
            <input type="number" class="form-control form-control-sm" id="pay_amount" min="0" step="0.01">
          </div>

          <div class="col-12">
            <label class="form-label small mb-1">Reference (Bank required)</label>
            <input type="text" class="form-control form-control-sm" id="pay_reference" placeholder="TXN / slip no">
          </div>

          <div class="col-12 d-grid">
            <button class="btn btn-outline-primary btn-sm" type="button" id="btnAddPaymentRow">
              <i class="bi bi-plus-circle"></i> Add Payment
            </button>
          </div>
        </div>

        <div class="pos-paytable mt-2">
          <table class="table table-sm mb-0">
            <thead class="table-light">
              <tr>
                <th>Method</th>
                <th>Provider</th>
                <th>Reference</th>
                <th class="text-end">Amount</th>
                <th style="width:50px;"></th>
              </tr>
            </thead>
            <tbody id="paymentsBody">
              <tr id="paymentsEmptyRow">
                <td colspan="5" class="text-center text-muted py-2">No payments yet.</td>
              </tr>
            </tbody>
          </table>
        </div>

        <div class="pos-row mt-2">
          <span class="muted">Paid</span>
          <span class="fw-semibold" id="t_paid">0</span>
        </div>
        <div class="pos-row">
          <span class="muted">Balance</span>
          <span class="fw-bold" id="t_balance">0</span>
        </div>
      </div>

      <div class="pos-cta">
        <button class="btn btn-success w-100" type="button" id="btnConfirm">
          <i class="bi bi-check2-circle"></i> Checkout / Preview
        </button>
        <div class="pos-sub mt-2">Preview → edit if needed → finalize → print.</div>
      </div>

    </div>
  </aside>

</div>

<script>
  window.POS_CONFIG = {
    baseUrl: "<?= h($BASE_URL) ?>",
    apiUrl: "<?= h($BASE_URL) ?>/modules/pos/pos_api.php",
    csrf: "<?= h($csrf) ?>",
    perms: {
      discount: <?= $can_discount ? 'true' : 'false' ?>,
      editPrice: <?= $can_editprice ? 'true' : 'false' ?>,
      debt: <?= $can_debt ? 'true' : 'false' ?>
    }
  };
</script>

<!-- Preview Modal -->
<div class="modal fade" id="previewModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-xl modal-dialog-scrollable">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title">Checkout Preview</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>

      <div class="modal-body" id="previewModalBody">
        <div class="text-muted small">Loading preview…</div>
      </div>

      <div class="modal-footer">
        <button type="button" class="btn btn-light" data-bs-dismiss="modal">Back</button>
        <button type="button" class="btn btn-success" id="btnConfirmFromPreview">
          <i class="bi bi-check2-circle"></i> Finalize Sale
        </button>
      </div>
    </div>
  </div>
</div>

<script src="<?= h($BASE_URL) ?>/assets/js/pos.js?v=2"></script>

    </main>
  </div>
</div>

<?php require_once __DIR__ . '/../../templates/layout/footer.php'; ?>