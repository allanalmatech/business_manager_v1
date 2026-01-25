<?php
// index.php
declare(strict_types=1);

// Bootstrap: DB, sessions (DB-backed), BASE_URL
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/rbac.php';

// ---- Require authentication ----
require_login();

// ---- Page meta ----
$page_title    = 'Dashboard';
$page_subtitle = 'Overview & shortcuts';

// ---- Current user (from session) ----
$user = current_user();

$current_user_name = $user['name'] ?? $user['username'] ?? 'User';
$current_user_role = $user['role'] ?? '';
$notif_badge       = 0; // later: compute from reminders/unpaid/installments

// ---- Optional: enforce dashboard access permission ----
require_permission('dashboard.view');

// ---- Load UI shell ----
require_once __DIR__ . '/templates/layout/header.php';
?>
<div class="app-shell">

  <?php require_once __DIR__ . '/templates/layout/sidebar.php'; ?>

  <div class="app-content">
    <?php require_once __DIR__ . '/templates/layout/topbar.php'; ?>

    <main class="page-wrap">

      <?php
      // ---- Role-based dashboard routing ----
      switch ($current_user_role) {
          case 'cashier':
              require __DIR__ . '/dashboards/cashier.php';
              break;

          case 'accountant':
              require __DIR__ . '/dashboards/accountant.php';
              break;

          case 'super_admin':
          default:
              require __DIR__ . '/dashboards/super_admin.php';
              break;
      }
      ?>

    </main>
  </div>
</div>

<?php
require_once __DIR__ . '/templates/layout/footer.php';
