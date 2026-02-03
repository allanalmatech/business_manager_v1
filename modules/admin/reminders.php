<?php
// admin/reminders.php
declare(strict_types=1);

require_once dirname(dirname(__DIR__)) . '/includes/bootstrap.php';
require_once dirname(dirname(__DIR__)) . '/includes/auth.php';
require_once dirname(dirname(__DIR__)) . '/includes/helpers.php';
require_once dirname(dirname(__DIR__)) . '/includes/rbac.php';

if (function_exists('require_admin_login')) require_admin_login();
require_permission('reminders.view');

$db = $GLOBALS['db'] ?? null;

function table_exists(mysqli $db, string $table): bool {
  $r = $db->query("SHOW TABLES LIKE '" . $db->real_escape_string($table) . "'");
  return $r && $r->num_rows > 0;
}

$page_title = "Reminders";
$page_subtitle = "Scheduled reminders / notifications";

require_once dirname(dirname(__DIR__)) . '/templates/layout/header.php';
?>
<div class="app-shell">
  <?php require_once dirname(dirname(__DIR__)) . '/templates/layout/sidebar.php'; ?>

  <div class="app-content">
    <?php require_once dirname(dirname(__DIR__)) . '/templates/layout/topbar.php'; ?>

    <main class="page-wrap">
      <div class="container-fluid py-3">
       <!-- <div class="mb-3">
          <h4 class="mb-1"><?= h($page_title) ?></h4>
          <div class="text-muted small"><?= h($page_subtitle) ?></div>
        </div> -->

        <?php if (!$db instanceof mysqli): ?>
          <div class="alert alert-danger">Database not available.</div>
        <?php else: ?>
          <?php
            if (!table_exists($db,'reminders')) {
              echo '<div class="alert alert-warning"><b>reminders</b> table not found.</div>';
              echo '<pre class="small bg-light border rounded p-2 mb-0">'.h(
"CREATE TABLE reminders (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  title VARCHAR(150) NOT NULL,
  remind_at DATETIME NOT NULL,
  channel VARCHAR(20) NOT NULL DEFAULT 'email',
  target VARCHAR(150) NULL,
  status VARCHAR(20) NOT NULL DEFAULT 'pending',
  created_by INT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_remind_at (remind_at),
  KEY idx_status (status)
);"
          ).'</pre>';
          require_once dirname(dirname(__DIR__)) . '/templates/layout/footer.php'; exit;
        }

        $rs = $db->query("SELECT * FROM reminders ORDER BY remind_at DESC LIMIT 200");
        $rows = $rs ? $rs->fetch_all(MYSQLI_ASSOC) : [];
      ?>

      <div class="card shadow-sm">
        <div class="card-body">
          <div class="table-responsive">
            <table class="table table-sm table-hover align-middle">
              <thead class="table-light">
                <tr>
                  <th>#</th>
                  <th>Title</th>
                  <th>When</th>
                  <th>Channel</th>
                  <th>Target</th>
                  <th>Status</th>
                </tr>
              </thead>
              <tbody>
                <?php if(!$rows): ?>
                  <tr><td colspan="6" class="text-center text-muted py-4">No reminders.</td></tr>
                <?php else: foreach($rows as $r): ?>
                  <tr>
                    <td><?= (int)$r['id'] ?></td>
                    <td class="fw-semibold"><?= h($r['title'] ?? '') ?></td>
                    <td><?= h($r['remind_at'] ?? '') ?></td>
                    <td><?= h($r['channel'] ?? '') ?></td>
                    <td><?= h($r['target'] ?? '') ?></td>
                    <td><?= h($r['status'] ?? '') ?></td>
                  </tr>
                <?php endforeach; endif; ?>
              </tbody>
            </table>
          </div>
        </div>
      </div>

    <?php endif; ?>
  </div>
</div>
<?php require_once dirname(dirname(__DIR__)) . '/templates/layout/footer.php'; ?>