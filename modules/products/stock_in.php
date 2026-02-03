<?php
// modules/products/stock_in.php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/rbac.php';

require_permission('products.update');

$BASE_URL = $GLOBALS['BASE_URL'] ?? '';
$page_title = 'Stock In';
$page_subtitle = 'Add stock to products';

$user = current_user();
$current_user_name = $user['name'] ?? $user['username'] ?? 'User';
$current_user_role = $user['role'] ?? '';

$extra_js = [
  'assets/js/stock_in.js',
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
          <h5 class="mb-3">Record Stock In</h5>

          <form id="stockInForm">
            <div class="row g-3">
              <div class="col-12 col-md-4">
                <label class="form-label">Store/Location *</label>
                <select class="form-select" id="locationId" required>
                  <option value="">-- Select Location --</option>
                </select>
                <div class="form-text">Select the store location</div>
              </div>

              <div class="col-12 col-md-4">
                <label class="form-label">Product *</label>
                <select class="form-select" id="productId" required>
                  <option value="">-- Select Product --</option>
                </select>
                <div class="form-text">Start typing to filter</div>
              </div>

              <div class="col-12 col-md-2">
                <label class="form-label">Unit Type *</label>
                <select class="form-select" id="unitType" required>
                  <option value="units">Units</option>
                  <option value="boxes">Boxes</option>
                  <option value="pieces">Pieces</option>
                </select>
                <div class="form-text">Choose measurement unit</div>
              </div>

              <div class="col-12 col-md-2">
                <label class="form-label">Quantity *</label>
                <input class="form-control" type="number" step="0.01" min="0.01" id="qtyChange" required>
                <div class="form-text" id="unitHint">Specify quantity</div>
              </div>

              <div class="col-12 col-md-3">
                <label class="form-label">Unit Price (optional)</label>
                <input class="form-control" type="number" step="0.01" min="0" id="unitPrice" placeholder="0.00">
              </div>

              <div class="col-12">
                <label class="form-label">Note</label>
                <textarea class="form-control" id="note" rows="2" placeholder="e.g. New shipment, purchase order #123"></textarea>
              </div>

              <div class="col-12">
                <button type="submit" class="btn btn-primary" id="btnSave">Add Stock</button>
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
          <h6 class="mb-2">Current Stock Levels</h6>
          <div id="currentStock" class="small text-muted">Select a product to see current quantity.</div>
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