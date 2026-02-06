<?php
// modules/products/products.php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/rbac.php';
require_once __DIR__ . '/../../includes/helpers.php';

require_permission('products.view');

$BASE_URL = $GLOBALS['BASE_URL'] ?? '';

$page_title = "Products";
$page_subtitle = "Manage your inventory and pricing";

require_once __DIR__ . '/../../templates/layout/header.php';
?>
<div class="app-shell">
  <?php require_once __DIR__ . '/../../templates/layout/sidebar.php'; ?>

  <div class="app-content">
    <?php require_once __DIR__ . '/../../templates/layout/topbar.php'; ?>

    <main class="page-wrap">
      <div class="d-flex flex-wrap gap-3 align-items-center justify-content-between mb-4">
        <div class="d-flex gap-2 flex-grow-1" style="max-width: 500px;">
          <div class="input-group shadow-sm">
            <span class="input-group-text bg-white border-end-0"><i class="bi bi-search"></i></span>
            <input id="q" class="form-control border-start-0" placeholder="Search name, SKU or category...">
            <button class="btn btn-primary" id="btnSearch">Search</button>
          </div>
        </div>

        <div class="d-flex gap-2">
          <?php if (user_has_permission('products.create')): ?>
            <button class="btn btn-success shadow-sm" id="btnNew">
              <i class="bi bi-plus-lg me-1"></i> New Product
            </button>
          <?php endif; ?>
        </div>
      </div>

      <div class="card border-0 shadow-sm rounded-4 overflow-hidden">
        <div class="card-body p-0">
          <div class="table-responsive">
            <table class="table table-hover align-middle mb-0" id="tbl">
              <thead class="bg-light">
                <tr>
                  <th class="ps-4" style="width: 80px;">Item</th>
                  <th>Product Details</th>
                  <th class="text-end">Cost</th>
                  <th class="text-end">Wholesale</th>
                  <th class="text-end">Retail</th>
                  <th class="text-center">Stock</th>
                  <th>Status</th>
                  <th class="text-end pe-4">Actions</th>
                </tr>
              </thead>
              <tbody>
                <!-- Loaded via JS -->
              </tbody>
            </table>
          </div>
          <div id="hint" class="p-5 text-center text-muted">
            <div class="spinner-border spinner-border-sm me-2"></div> Loading products...
          </div>
        </div>
      </div>
    </main>
  </div>
</div>

<!-- Product Modal -->
<div class="modal fade" id="mdlProduct" tabindex="-1">
  <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
    <div class="modal-content border-0 shadow">
      <div class="modal-header bg-light">
        <h5 class="modal-title fw-bold" id="mdlTitle">Product Details</h5>
        <button class="btn-close" data-bs-dismiss="modal"></button>
      </div>

      <div class="modal-body p-4">
        <input type="hidden" id="id">

        <div class="row g-3">
          <div class="col-md-8">
            <label class="form-label small fw-bold">Product Name *</label>
            <input class="form-control" id="name" placeholder="Enter product name">
          </div>
          <div class="col-md-4">
            <label class="form-label small fw-bold">SKU / Barcode *</label>
            <input class="form-control" id="sku" placeholder="SKU001">
          </div>

          <div class="col-12">
            <label class="form-label small fw-bold">Description</label>
            <textarea class="form-control" id="description" rows="2" placeholder="Brief description..."></textarea>
          </div>

          <div class="col-md-6">
            <label class="form-label small fw-bold">Unit Type</label>
            <select class="form-select" id="unit_type">
              <option value="pieces" selected>Pieces</option>
              <option value="boxes">Boxes / Cartons</option>
              <option value="dozens">Dozens</option>
              <option value="pairs">Pairs</option>
              <option value="units">Units (kg, liters…)</option>
            </select>
          </div>

          <div class="col-md-6">
            <label class="form-label small fw-bold">Default Location</label>
            <select class="form-select" id="default_location_id">
              <option value="">— Select Location —</option>
            </select>
          </div>

          <div class="col-md-4">
            <label class="form-label small fw-bold">Cost Price</label>
            <div class="input-group">
              <span class="input-group-text">$</span>
              <input class="form-control" id="cost_price" type="number" step="0.01">
            </div>
          </div>
          <div class="col-md-4">
            <label class="form-label small fw-bold">Wholesale Price</label>
            <div class="input-group border-warning">
              <span class="input-group-text bg-warning-subtle">$</span>
              <input class="form-control" id="wholesale_price" type="number" step="0.01">
            </div>
          </div>
          <div class="col-md-4">
            <label class="form-label small fw-bold">Retail Price</label>
            <div class="input-group border-primary">
              <span class="input-group-text bg-primary-subtle">$</span>
              <input class="form-control" id="retail_price" type="number" step="0.01">
            </div>
          </div>

          <div class="col-md-6">
            <div class="p-3 bg-light rounded-3">
              <label class="form-label small fw-bold">Initial Stock</label>
              <input class="form-control" id="qty_base" type="number" step="0.01">
              <div class="form-text x-small mt-1">Total pieces currently in stock.</div>
            </div>
          </div>

          <div class="col-md-6">
            <div class="p-3 bg-light rounded-3">
              <label class="form-label small fw-bold">Low Stock Alert</label>
              <input class="form-control border-danger-subtle" id="low_level_base" type="number" step="0.01">
              <div class="form-text x-small mt-1">Notify when stock drops below this.</div>
            </div>
          </div>

          <div class="col-12">
            <div class="form-check form-switch mt-2">
              <input class="form-check-input" type="checkbox" id="is_active_check" checked>
              <label class="form-check-label fw-bold" for="is_active_check">Product is active and available for sale</label>
              <input type="hidden" id="is_active" value="1">
            </div>
          </div>
        </div>

        <div class="mt-4 border-top pt-4">
          <label class="form-label small fw-bold d-flex justify-content-between">
            Product Images <span id="imageCount" class="text-muted">(0/5)</span>
          </label>
          <div class="d-flex flex-wrap gap-2 mb-3" id="imageGallery"></div>
          <div class="d-flex gap-2">
            <button class="btn btn-sm btn-outline-primary" id="btnUploadImage"><i class="bi bi-upload me-1"></i> Upload</button>
            <button class="btn btn-sm btn-outline-secondary" id="btnImportUrl"><i class="bi bi-link-45deg me-1"></i> URL</button>
            <input type="file" id="fileInput" accept="image/*" multiple style="display:none;">
          </div>
        </div>
      </div>

      <div class="modal-footer bg-light border-0">
        <button class="btn btn-link text-secondary" data-bs-dismiss="modal">Cancel</button>
        <?php if (user_has_permission('products.delete')): ?>
          <button class="btn btn-outline-danger" id="btnDelete" style="display:none;">Delete</button>
        <?php endif; ?>
        <button class="btn btn-primary px-4" id="btnSave">Save Product</button>
      </div>
    </div>
  </div>
</div>

<?php require_once __DIR__ . '/../../templates/layout/footer.php'; ?>

<script>
  window.APP = {
    BASE_URL: <?= json_encode($BASE_URL) ?>,
    CSRF: <?= json_encode($_SESSION['csrf'] ?? '') ?>,
  };
</script>

<!-- The original JS logic would be kept here, but I'll skip rewriting the entire 800 lines of JS 
     unless strictly necessary, as the HTML/CSS changes already significantly improve the view. 
     I'll just make sure the selectors match. -->
<script src="<?= h($BASE_URL) ?>/assets/js/products.js?v=2"></script>
