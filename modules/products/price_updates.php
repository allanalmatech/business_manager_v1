<?php
// modules/products/price_updates.php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/rbac.php';

require_permission('products.update');

$BASE_URL = $GLOBALS['BASE_URL'] ?? '';
$page_title = 'Price Updates';
$page_subtitle = 'Update product prices with audit trail';

$user = current_user();
$current_user_name = $user['name'] ?? $user['username'] ?? 'User';
$current_user_role = $user['role'] ?? '';

$extra_js = [
  'assets/js/price_updates.js',
];

require_once __DIR__ . '/../../templates/layout/header.php';
?>
<div class="app-shell">
  <?php require_once __DIR__ . '/../../templates/layout/sidebar.php'; ?>

  <div class="app-content">
    <?php require_once __DIR__ . '/../../templates/layout/topbar.php'; ?>

    <main class="page-wrap">

      <div class="card shadow-sm rounded-4">
        <div class="card-body">
          <h5 class="mb-3">Update Product Prices</h5>

          <form id="priceUpdateForm">
            <div class="row g-3">
              <div class="col-12 col-md-6">
                <label class="form-label">Product *</label>
                <select class="form-select" id="productId" required>
                  <option value="">-- Select Product --</option>
                </select>
                <div class="form-text">Start typing to filter</div>
              </div>

              <div class="col-12 col-md-3">
                <label class="form-label">Cost Price</label>
                <input class="form-control" type="number" step="0.01" min="0" id="costPrice" placeholder="0.00">
              </div>

              <div class="col-12 col-md-3">
                <label class="form-label">Wholesale Price</label>
                <input class="form-control" type="number" step="0.01" min="0" id="wholesalePrice" placeholder="0.00">
              </div>

              <div class="col-12 col-md-3">
                <label class="form-label">Retail Price</label>
                <input class="form-control" type="number" step="0.01" min="0" id="retailPrice" placeholder="0.00">
              </div>

              <div class="col-12 col-md-9">
                <label class="form-label">Reason *</label>
                <select class="form-select" id="reason" required>
                  <option value="">-- Select Reason --</option>
                  <option value="Supplier Price Change">Supplier Price Change</option>
                  <option value="Promotion">Promotion</option>
                  <option value="Market Adjustment">Market Adjustment</option>
                  <option value="Seasonal">Seasonal</option>
                  <option value="Correction">Correction</option>
                  <option value="Other">Other</option>
                </select>
              </div>

              <div class="col-12">
                <label class="form-label">Note</label>
                <textarea class="form-control" id="note" rows="2" placeholder="Optional details"></textarea>
              </div>

              <div class="col-12">
                <button type="submit" class="btn btn-primary" id="btnSave">Update Prices</button>
                <button type="reset" class="btn btn-outline-secondary ms-2">Reset</button>
              </div>
            </div>
          </form>

          <div class="alert alert-danger d-none mt-3" id="formError"></div>
          <div class="alert alert-success d-none mt-3" id="formSuccess"></div>
        </div>
      </div>

      <div class="card shadow-sm rounded-4 mt-4">
        <div class="card-body">
          <h6 class="mb-2">Current Prices</h6>
          <div id="currentPrices" class="small text-muted">Select a product to see current prices.</div>
        </div>
      </div>

      <script>
        window.APP = {
          BASE_URL: <?= json_encode($BASE_URL) ?>,
          CSRF: <?= json_encode($_SESSION['csrf'] ?? '') ?>,
        };
      </script>

    </main>
  </div>
</div>
<?php require_once __DIR__ . '/../../templates/layout/footer.php'; ?>