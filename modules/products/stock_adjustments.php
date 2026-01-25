<?php
// modules/products/stock_adjustments.php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/rbac.php';
require_once __DIR__ . '/../../includes/helpers.php';

require_permission('products.update');

$BASE_URL = $GLOBALS['BASE_URL'] ?? '';
$page_title = 'Stock Adjustments';
$page_subtitle = 'Increase or decrease stock quantities manually';

// Pre-select product if product_id is in query
$preselectedProductId = (int)($_GET['product_id'] ?? 0);

$user = current_user();
$current_user_name = $user['name'] ?? $user['username'] ?? 'User';
$current_user_role = $user['role'] ?? '';

$extra_js = [
  'assets/js/stock_adjustments.js',
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
          <h5 class="mb-3">Record Stock Adjustment</h5>

          <form id="stockAdjustForm">
            <div class="row g-3">
              <div class="col-12 col-md-6">
                <label class="form-label">Product *</label>
                <select class="form-select" id="productId" required>
                  <option value="">-- Select Product --</option>
                </select>
                <div class="form-text">Start typing to filter</div>
              </div>

              <div class="col-12 col-md-3">
                <label class="form-label">Location *</label>
                <select class="form-select" id="locationId" required>
                  <option value="">-- Select Location --</option>
                </select>
              </div>

              <div class="col-12 col-md-3">
                <label class="form-label">Adjustment (+/-) *</label>
                <input class="form-control" type="number" step="0.01" id="qtyChange" placeholder="e.g. 5 or -3" required>
                <div class="form-text">Positive to add, negative to remove</div>
              </div>

              <div class="col-12 col-md-3">
                <label class="form-label">Reason *</label>
                <select class="form-select" id="reason" required>
                  <option value="">-- Select Reason --</option>
                  <option value="Damage">Damage</option>
                  <option value="Theft">Theft</option>
                  <option value="Return">Return</option>
                  <option value="Correction">Correction</option>
                  <option value="Transfer">Transfer</option>
                  <option value="Other">Other</option>
                </select>
              </div>

              <div class="col-12">
                <label class="form-label">Note</label>
                <textarea class="form-control" id="note" rows="2" placeholder="Optional details"></textarea>
              </div>

              <div class="col-12">
                <button type="submit" class="btn btn-primary" id="btnSave">Record Adjustment</button>
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
          preselectedProductId: <?= json_encode($preselectedProductId) ?>
        };
      </script>

    </main>
  </div>
</div>
<?php require_once __DIR__ . '/../../templates/layout/footer.php'; ?>