<?php
$BASE_URL = $GLOBALS['BASE_URL'] ?? '';
// templates/layout/topbar.php
// Expected vars (optional): $current_user_name, $current_user_role
?>
<header class="app-topbar border-bottom">
  <div class="container-fluid d-flex align-items-center gap-2 py-2">

    <!-- Mobile sidebar toggle -->
    <button class="btn btn-outline-secondary btn-sm d-lg-none" id="btnSidebarOpen" type="button">
      <i class="bi bi-list"></i>
    </button>

    <!-- Desktop collapse toggle -->
    <button class="btn btn-outline-secondary btn-sm d-none d-lg-inline-flex" id="btnSidebarToggle" type="button" title="Collapse sidebar">
      <i class="bi bi-layout-sidebar-inset"></i>
    </button>

    <div class="flex-grow-1">
      <div class="fw-semibold"><?= htmlspecialchars($page_title ?? 'Dashboard') ?></div>
      <div class="text-muted small d-none d-md-block"><?= htmlspecialchars($page_subtitle ?? '') ?></div>
    </div>

    <!-- Global search (compact on mobile) -->
    <form class="d-none d-md-flex" role="search" action="/modules/search.php" method="get">
      <input class="form-control form-control-sm" name="q" placeholder="Search products, contacts, invoices…" />
    </form>

    <!-- Notifications -->
    <a class="btn btn-outline-secondary btn-sm position-relative" href="/modules/notifications.php" title="Notifications">
      <i class="bi bi-bell"></i>
    <?php if (!empty($notif_badge) && (int)$notif_badge > 0): ?>
        <span class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger">
          <?= (int)$notif_badge ?>
        </span>
      <?php endif; ?>
    </a>

    <!-- Quick actions -->
    <div class="dropdown">
  <button
    class="btn btn-outline-secondary btn-sm dropdown-toggle d-flex align-items-center gap-2"
    data-bs-toggle="dropdown"
    aria-expanded="false"
  >
    <div class="avatar-mini d-flex align-items-center justify-content-center bg-primary text-white">
      <i class="bi bi-person"></i>
    </div>
    <span class="d-none d-md-inline">
      <?= htmlspecialchars($current_user_name ?? 'User') ?>
    </span>
  </button>

  <ul class="dropdown-menu dropdown-menu-end">
    <li>
      <div class="dropdown-item-text small text-muted">
        <?= htmlspecialchars($current_user_role ?? '') ?>
      </div>
    </li>

    <li><hr class="dropdown-divider"></li>

    <li>
      <a class="dropdown-item" href="<?= htmlspecialchars($BASE_URL) ?>/modules/profile/my_profile.php">
        My Profile
      </a>
    </li>

    <li>
      <a class="dropdown-item" href="<?= htmlspecialchars($BASE_URL) ?>/modules/profile/change_password.php">
        Change Password
      </a>
    </li>

    <li><hr class="dropdown-divider"></li>

    <li>
      <a class="dropdown-item text-danger" href="<?= htmlspecialchars($BASE_URL) ?>/logout.php">
        Logout
      </a>
    </li>
  </ul>
</div>

  </div>
</header>
