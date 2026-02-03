<?php
// admin/update_history.php
declare(strict_types=1);

require_once dirname(dirname(__DIR__)) . '/includes/bootstrap.php';
require_once dirname(dirname(__DIR__)) . '/includes/auth.php';
require_once dirname(dirname(__DIR__)) . '/includes/helpers.php';
require_once dirname(dirname(__DIR__)) . '/includes/rbac.php';

require_permission('updates.view');

$db = $GLOBALS['db'] ?? null;

function table_exists(mysqli $db, string $table): bool {
  $r = $db->query("SHOW TABLES LIKE '" . $db->real_escape_string($table) . "'");
  return $r && $r->num_rows > 0;
}

$page_title = "Update History";
$page_subtitle = "Records of applied updates";

require_once dirname(dirname(__DIR__)) . '/templates/layout/header.php';
?>
<div class="app-shell">
  <?php require_once dirname(dirname(__DIR__)) . '/templates/layout/sidebar.php'; ?>

  <div class="app-content">
    <?php require_once dirname(dirname(__DIR__)) . '/templates/layout/topbar.php'; ?>

    <main class="page-wrap">
      <div class="container-fluid py-3">
        <?php if (!$db instanceof mysqli): ?>
          <div class="alert alert-danger">Database not available.</div>
        <?php else: ?>
          <?php
            if (!table_exists($db,'update_history')) {
              echo '<div class="alert alert-warning"><b>update_history</b> table not found.</div>';
              echo '<pre class="small bg-light border rounded p-2 mb-0">'.h(
"CREATE TABLE update_history (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  version_from VARCHAR(40) NULL,
  version_to VARCHAR(40) NULL,
  status VARCHAR(20) NOT NULL DEFAULT 'success',
  notes LONGTEXT NULL,
  applied_by INT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_created_at (created_at)
);"
          ).'</pre>';
          require_once dirname(dirname(__DIR__)) . '/templates/layout/footer.php'; exit;
        }

        $rs = $db->query("SELECT * FROM update_history ORDER BY id DESC LIMIT 200");
        $rows = $rs ? $rs->fetch_all(MYSQLI_ASSOC) : [];
      ?>

      <div class="card shadow-sm">
        <div class="card-body">
          <div class="table-responsive">
            <table class="table table-sm table-hover align-middle">
              <thead class="table-light">
                <tr>
                  <th>#</th>
                  <th>From</th>
                  <th>To</th>
                  <th>Status</th>
                  <th>Applied By</th>
                  <th>Date</th>
                </tr>
              </thead>
              <tbody>
                <?php if(!$rows): ?>
                  <tr><td colspan="6" class="text-center text-muted py-4">No updates yet.</td></tr>
                <?php else: foreach($rows as $r): ?>
                  <tr title="<?= h((string)($r['notes'] ?? '')) ?>">
                    <td><?= (int)$r['id'] ?></td>
                    <td><?= h($r['version_from'] ?? '') ?></td>
                    <td class="fw-semibold"><?= h($r['version_to'] ?? '') ?></td>
                    <td><?= h($r['status'] ?? '') ?></td>
                    <td><?= h($r['applied_by'] ?? '') ?></td>
                    <td><?= h($r['created_at'] ?? '') ?></td>
                  </tr>
                <?php endforeach; endif; ?>
              </tbody>
            </table>
          </div>
        </div>
      </div>
    <?php endif; ?>
      </div>
    </main>
  </div>
</div>
<?php require_once dirname(dirname(__DIR__)) . '/templates/layout/footer.php'; ?>