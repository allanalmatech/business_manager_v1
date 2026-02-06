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

<div class="app-shell" id="posAppShell">
  <?php require_once __DIR__ . '/../../templates/layout/sidebar.php'; ?>

  <div class="app-content">
    <?php require_once __DIR__ . '/../../templates/layout/topbar.php'; ?>

    <main class="page-wrap">

<link rel="stylesheet" href="<?= h($BASE_URL) ?>/assets/css/pos.css?v=3">

<div class="pos-shell">

  <!-- LEFT -->
  <section class="pos-left">

    <div class="pos-topbar">
      <div class="pos-tabs-modern" id="posCategories">
        <button type="button" class="pos-tab-modern active" data-cat="">All</button>
        <button type="button" class="pos-tab-modern" data-cat="popular">Popular</button>
        <button type="button" class="pos-tab-modern" data-cat="fashion">Fashion</button>
        <button type="button" class="pos-tab-modern" data-cat="food">Food</button>
      </div>

      <div class="pos-search">
        <i class="bi bi-search"></i>
        <input type="text" id="product_search" placeholder="Search SKU, name, barcode…" autocomplete="off">
      </div>

      <div class="pos-header-actions">
        <select class="form-select form-select-sm" id="selling_location_id" style="width: auto;">
          <?php foreach ($locations as $loc): ?>
            <option value="<?= h($loc['id']) ?>" <?= ((string)$loc['id'] === (string)$default_location_id) ? 'selected' : '' ?>>
              <?= h($loc['name']) ?>
            </option>
          <?php endforeach; ?>
        </select>
        
        <button type="button" class="fullscreen-btn" id="btnToggleFullscreen" title="Toggle Fullscreen">
          <i class="bi bi-arrows-fullscreen"></i>
        </button>
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
          <div class="pos-h1">Products</div>
          <div class="pos-sub">Tap items to add to cart</div>
        </div>

        <div class="pos-actions">
          <div class="btn-group btn-group-sm" role="group">
            <input type="radio" class="btn-check" name="pricing_mode" id="pm_retail" value="retail" checked>
            <label class="btn btn-outline-primary" for="pm_retail">Retail</label>

            <input type="radio" class="btn-check" name="pricing_mode" id="pm_wholesale" value="wholesale">
            <label class="btn btn-outline-primary" for="pm_wholesale">Wholesale</label>
          </div>

          <button class="btn btn-outline-secondary btn-sm" id="btnNewSale" type="button">
            <i class="bi bi-arrow-clockwise"></i> Clear
          </button>
        </div>
      </div>

      <div id="quickItems" class="pos-grid touch-grid"></div>
    </div>

  </section>

  <!-- RIGHT -->
  <aside class="pos-right">

    <div class="pos-cart-head">
      <div>
        <div class="pos-h2">Current Sale</div>
        <div id="cartCount" class="pos-sub">0 items</div>
      </div>

      <select class="form-select form-select-sm" id="doc_type" style="width: auto;">
        <option value="receipt">Receipt</option>
        <?php if ($can_invoice): ?><option value="invoice">Invoice</option><?php endif; ?>
        <?php if ($can_dn): ?><option value="delivery_note">Delivery Note</option><?php endif; ?>
      </select>
    </div>

    <div id="cartPanel" class="pos-cart-list">
      <div id="cartEmptyRow" class="pos-empty">
        <i class="bi bi-cart-x d-block mb-2" style="font-size: 2rem; opacity: 0.3;"></i>
        Cart is empty
      </div>
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
        <span class="pos-grand text-primary" id="t_grand">0</span>
      </div>

      <div class="pos-divider"></div>

      <div class="row g-2 mb-3">
        <div class="col-12">
          <div class="input-group input-group-sm">
            <span class="input-group-text bg-white"><i class="bi bi-person"></i></span>
            <select class="form-select" id="customer_id">
              <option value="">Walk-in Customer</option>
              <?php foreach ($customers as $c): ?>
                <option value="<?= h($c['id']) ?>"><?= h($c['name']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
        </div>
      </div>

      <div class="pos-paybox">
        <div class="d-flex justify-content-between align-items-center mb-2">
          <div class="fw-bold">Payment</div>
          <div class="small text-primary fw-bold" id="t_balance_display">Balance: 0</div>
        </div>

        <div class="row g-2 mb-2">
          <div class="col-6">
            <select class="form-select form-select-sm" id="pay_method">
              <option value="cash">Cash</option>
              <option value="mobile_money">Mobile Money</option>
              <option value="bank">Bank Transfer</option>
            </select>
          </div>
          <div class="col-6">
            <input type="number" class="form-control form-control-sm fw-bold text-end" id="pay_amount" placeholder="0.00">
          </div>
        </div>

        <div class="payment-shortcuts">
          <button type="button" class="btn btn-outline-secondary btn-shortcut" data-amt="exact">EXACT</button>
          <button type="button" class="btn btn-outline-secondary btn-shortcut" data-amt="10">+10</button>
          <button type="button" class="btn btn-outline-secondary btn-shortcut" data-amt="50">+50</button>
          <button type="button" class="btn btn-outline-secondary btn-shortcut" data-amt="100">+100</button>
          <button type="button" class="btn btn-outline-secondary btn-shortcut" data-amt="500">+500</button>
          <button type="button" class="btn btn-primary btn-shortcut" id="btnAddPaymentRow">ADD</button>
        </div>

        <div class="pos-paytable mt-3" style="max-height: 100px;">
          <table class="table table-sm table-borderless mb-0">
            <tbody id="paymentsBody">
              <tr id="paymentsEmptyRow">
                <td colspan="3" class="text-center text-muted small py-2">No payments</td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>

      <div class="pos-cta mt-3">
        <button class="btn btn-success btn-lg w-100 py-3 fw-bold shadow-sm" type="button" id="btnConfirm">
          <i class="bi bi-check2-circle me-2"></i> COMPLETE SALE
        </button>
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
  <div class="modal-dialog modal-lg modal-dialog-centered">
    <div class="modal-content border-0 shadow">
      <div class="modal-header bg-light">
        <h5 class="modal-title fw-bold">Checkout Summary</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>

      <div class="modal-body" id="previewModalBody">
        <div class="text-center py-4">
          <div class="spinner-border text-primary" role="status"></div>
          <div class="mt-2 text-muted">Preparing receipt...</div>
        </div>
      </div>

      <div class="modal-footer border-0">
        <button type="button" class="btn btn-light px-4" data-bs-dismiss="modal">Edit Sale</button>
        <button type="button" class="btn btn-success px-5 fw-bold" id="btnConfirmFromPreview">
          FINALIZE & PRINT
        </button>
      </div>
    </div>
  </div>
</div>

<script src="<?= h($BASE_URL) ?>/assets/js/pos.js?v=3"></script>

    </main>
  </div>
</div>

<?php require_once __DIR__ . '/../../templates/layout/footer.php'; ?>
