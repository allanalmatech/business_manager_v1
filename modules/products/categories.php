<?php
// modules/products/categories.php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/rbac.php';

require_permission('products.view');

$BASE_URL = $GLOBALS['BASE_URL'] ?? '';
$page_title = 'Product Categories';
$page_subtitle = 'Organize products into groups';

$user = current_user();
$current_user_name = $user['name'] ?? $user['username'] ?? 'User';
$current_user_role = $user['role'] ?? '';

$canUpdate = user_has_permission('products.update');
$canDelete = user_has_permission('products.delete');

$extra_js = [
  'assets/js/categories.js',
];

require_once __DIR__ . '/../../templates/layout/header.php';
?>
<div class="app-shell">
  <?php require_once __DIR__ . '/../../templates/layout/sidebar.php'; ?>

  <div class="app-content">
    <?php require_once __DIR__ . '/../../templates/layout/topbar.php'; ?>

    <main class="page-wrap">

      <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-3">
        <div class="d-flex gap-2">
          <input id="q" class="form-control form-control-sm" style="min-width:220px;" placeholder="Search by name">
          <select id="activeFilter" class="form-select form-select-sm" style="min-width:140px;">
            <option value="">All</option>
            <option value="1">Active</option>
            <option value="0">Disabled</option>
          </select>
          <button id="btnSearch" class="btn btn-outline-secondary btn-sm">Search</button>
        </div>

        <div class="d-flex gap-2">
          <?php if ($canUpdate): ?>
            <button class="btn btn-primary btn-sm" id="btnAdd">+ Add Category</button>
          <?php endif; ?>
        </div>
      </div>

      <div class="card shadow-sm rounded-4">
        <div class="card-body">
          <div class="table-responsive">
            <table class="table table-sm align-middle" id="categoriesTable">
              <thead>
                <tr>
                  <th style="width:90px;">ID</th>
                  <th>Name</th>
                  <th style="width:140px;">Status</th>
                  <th class="text-end" style="width:220px;">Actions</th>
                </tr>
              </thead>
              <tbody></tbody>
            </table>
          </div>

          <div class="d-flex justify-content-between align-items-center mt-2">
            <div class="small text-muted" id="resultInfo">—</div>
          </div>
        </div>
      </div>

      <div class="modal fade" id="categoryModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog">
          <div class="modal-content">
            <div class="modal-header">
              <h5 class="modal-title" id="categoryModalTitle">Category</h5>
              <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
              <input type="hidden" id="categoryId">

              <div class="mb-2">
                <label class="form-label">Name *</label>
                <input class="form-control" id="name" placeholder="e.g. Cement">
              </div>

              <div class="form-check">
                <input class="form-check-input" type="checkbox" id="is_active" checked>
                <label class="form-check-label" for="is_active">Active</label>
              </div>

              <div class="alert alert-danger d-none mt-3" id="modalError"></div>
            </div>
            <div class="modal-footer">
              <button class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
              <?php if ($canUpdate): ?>
                <button class="btn btn-primary" id="btnSave">Save</button>
              <?php endif; ?>
            </div>
          </div>
        </div>
      </div>

      <script>
        window.APP = {
          BASE_URL: <?= json_encode($BASE_URL) ?>,
          CSRF: <?= json_encode($_SESSION['csrf'] ?? '') ?>,
          CAN: {
            update: <?= $canUpdate ? 'true' : 'false' ?>,
            delete: <?= $canDelete ? 'true' : 'false' ?>,
          }
        };
      </script>

    </main>
  </div>
</div>
<?php require_once __DIR__ . '/../../templates/layout/footer.php'; ?>
