<div class="row g-4">
  <div class="col-12 col-md-6 col-lg-3">
    <div class="card border-0 shadow-sm rounded-4 overflow-hidden">
      <div class="card-body p-4">
        <div class="d-flex align-items-center justify-content-between mb-3">
          <div class="bg-primary bg-opacity-10 p-3 rounded-4">
            <i class="bi bi-cash-stack text-primary fs-4"></i>
          </div>
          <span class="badge bg-success bg-opacity-10 text-success rounded-pill">+12%</span>
        </div>
        <div class="text-muted small fw-medium mb-1">Today's Sales</div>
        <div class="fs-3 fw-bold">UGX 0</div>
      </div>
    </div>
  </div>
  <div class="col-12 col-md-6 col-lg-3">
    <div class="card border-0 shadow-sm rounded-4 overflow-hidden">
      <div class="card-body p-4">
        <div class="d-flex align-items-center justify-content-between mb-3">
          <div class="bg-warning bg-opacity-10 p-3 rounded-4">
            <i class="bi bi-hourglass-split text-warning fs-4"></i>
          </div>
          <span class="badge bg-danger bg-opacity-10 text-danger rounded-pill">High</span>
        </div>
        <div class="text-muted small fw-medium mb-1">Unpaid Pending</div>
        <div class="fs-3 fw-bold">0</div>
      </div>
    </div>
  </div>
  <div class="col-12 col-md-6 col-lg-3">
    <div class="card border-0 shadow-sm rounded-4 overflow-hidden">
      <div class="card-body p-4">
        <div class="d-flex align-items-center justify-content-between mb-3">
          <div class="bg-danger bg-opacity-10 p-3 rounded-4">
            <i class="bi bi-calendar-x text-danger fs-4"></i>
          </div>
          <span class="badge bg-secondary bg-opacity-10 text-secondary rounded-pill">Static</span>
        </div>
        <div class="text-muted small fw-medium mb-1">Overdue Installments</div>
        <div class="fs-3 fw-bold">0</div>
      </div>
    </div>
  </div>
  <div class="col-12 col-md-6 col-lg-3">
    <div class="card border-0 shadow-sm rounded-4 overflow-hidden">
      <div class="card-body p-4">
        <div class="d-flex align-items-center justify-content-between mb-3">
          <div class="bg-info bg-opacity-10 p-3 rounded-4">
            <i class="bi bi-box-seam text-info fs-4"></i>
          </div>
          <span class="badge bg-info bg-opacity-10 text-info rounded-pill">Check</span>
        </div>
        <div class="text-muted small fw-medium mb-1">Low Stock Alerts</div>
        <div class="fs-3 fw-bold">0</div>
      </div>
    </div>
  </div>

  <div class="col-12 col-lg-8">
    <div class="card border-0 shadow-sm rounded-4">
      <div class="card-body p-4">
        <div class="d-flex align-items-center justify-content-between mb-4">
          <h5 class="fw-bold mb-0">Quick Actions</h5>
          <i class="bi bi-lightning-charge text-primary"></i>
        </div>
        <div class="row g-3">
          <div class="col-6 col-md-3">
            <a class="d-flex flex-column align-items-center gap-2 p-3 rounded-4 border text-decoration-none hover-bg-light" href="/modules/pos/pos.php">
              <i class="bi bi-printer fs-4 text-primary"></i>
              <span class="small fw-bold text-dark">Open POS</span>
            </a>
          </div>
          <div class="col-6 col-md-3">
            <a class="d-flex flex-column align-items-center gap-2 p-3 rounded-4 border text-decoration-none hover-bg-light" href="/modules/admin/settings.php">
              <i class="bi bi-gear fs-4 text-primary"></i>
              <span class="small fw-bold text-dark">Settings</span>
            </a>
          </div>
          <div class="col-6 col-md-3">
            <a class="d-flex flex-column align-items-center gap-2 p-3 rounded-4 border text-decoration-none hover-bg-light" href="/modules/admin/users.php">
              <i class="bi bi-people fs-4 text-primary"></i>
              <span class="small fw-bold text-dark">Users</span>
            </a>
          </div>
          <div class="col-6 col-md-3">
            <a class="d-flex flex-column align-items-center gap-2 p-3 rounded-4 border text-decoration-none hover-bg-light" href="/modules/procurement/suggested_list.php">
              <i class="bi bi-stars fs-4 text-primary"></i>
              <span class="small fw-bold text-dark">Shopping</span>
            </a>
          </div>
        </div>
      </div>
    </div>
  </div>

  <div class="col-12 col-lg-4">
    <div class="card border-0 shadow-sm rounded-4">
      <div class="card-body p-4">
        <div class="d-flex align-items-center justify-content-between mb-4">
          <h5 class="fw-bold mb-0">System Alerts</h5>
          <span class="badge bg-light text-dark">0 New</span>
        </div>
        <div class="text-center py-4">
          <div class="bg-light rounded-circle d-inline-flex p-3 mb-3">
            <i class="bi bi-check-circle fs-3 text-success"></i>
          </div>
          <p class="text-muted small mb-0">System is running smoothly. No urgent alerts at the moment.</p>
        </div>
      </div>
    </div>
  </div>
</div>

<style>
.hover-bg-light:hover {
  background-color: var(--light-color);
  border-color: var(--primary-color) !important;
  transition: all 0.2s ease;
}
</style>
