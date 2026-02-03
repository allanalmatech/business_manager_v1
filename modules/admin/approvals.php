<?php
// admin/approvals.php
declare(strict_types=1);

require_once dirname(dirname(__DIR__)) . '/includes/bootstrap.php';
require_once dirname(dirname(__DIR__)) . '/includes/auth.php';
require_once dirname(dirname(__DIR__)) . '/includes/helpers.php';
require_once dirname(dirname(__DIR__)) . '/includes/rbac.php';

if (function_exists('require_admin_login')) require_admin_login();
require_permission('approvals.view');

$db = $GLOBALS['db'] ?? null;

function table_exists(mysqli $db, string $table): bool {
  $r = $db->query("SHOW TABLES LIKE '" . $db->real_escape_string($table) . "'");
  return $r && $r->num_rows > 0;
}

$page_title = "Approvals";
$page_subtitle = "Pending approvals (payments, adjustments, etc.)";

require_once dirname(dirname(__DIR__)) . '/templates/layout/header.php';
?>
<div class="app-shell">
  <?php require_once dirname(dirname(__DIR__)) . '/templates/layout/sidebar.php'; ?>

  <div class="app-content">
    <?php require_once dirname(dirname(__DIR__)) . '/templates/layout/topbar.php'; ?>

    <main class="page-wrap">
      <div class="container-fluid py-3">
    <!--    <div class="mb-3">
          <h4 class="mb-1"><?= h($page_title) ?></h4>
          <div class="text-muted small"><?= h($page_subtitle) ?></div>
        </div> -->

        <?php if (!$db instanceof mysqli): ?>
          <div class="alert alert-danger">Database not available.</div>
        <?php else: ?>
          <?php
            if (!table_exists($db,'approvals')) {
              echo '<div class="alert alert-warning"><b>approvals</b> table not found.</div>';
              echo '<pre class="small bg-light border rounded p-2 mb-0">'.h(
"CREATE TABLE approvals (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  approval_type VARCHAR(80) NOT NULL,
  reference_table VARCHAR(80) NULL,
  reference_id VARCHAR(80) NULL,
  status VARCHAR(20) NOT NULL DEFAULT 'pending',
  requested_by INT NULL,
  approved_by INT NULL,
  notes VARCHAR(255) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NULL,
  KEY idx_status (status),
  KEY idx_type (approval_type)
);"
          ).'</pre>';
          require_once dirname(dirname(__DIR__)) . '/templates/layout/footer.php'; exit;
        }

        $status = trim((string)($_GET['status'] ?? 'pending'));
        $rs = $db->prepare("SELECT * FROM approvals WHERE status = ? ORDER BY id DESC LIMIT 200");
        $rs->bind_param('s', $status);
        $rs->execute();
        $rows = $rs->get_result()->fetch_all(MYSQLI_ASSOC);
        $rs->close();
      ?>

      <div class="card shadow-sm">
        <div class="card-body">
          <form class="row g-2 mb-3" method="get">
            <div class="col-md-3">
              <select class="form-select" name="status" onchange="this.form.submit()">
                <?php foreach (['pending','approved','rejected','cancelled'] as $s): ?>
                  <option value="<?=h($s)?>" <?= $s===$status?'selected':'' ?>><?=h($s)?></option>
                <?php endforeach; ?>
              </select>
            </div>
          </form>

          <div class="table-responsive">
            <table class="table table-sm table-hover align-middle">
              <thead class="table-light">
                <tr>
                  <th>#</th>
                  <th>Type</th>
                  <th>Ref</th>
                  <th>Status</th>
                  <th>Requested By</th>
                  <th>Approved By</th>
                  <th>Date</th>
                  <th class="text-end">Actions</th>
                </tr>
              </thead>
              <tbody>
                <?php if(!$rows): ?>
                  <tr><td colspan="8" class="text-center text-muted py-4">No approvals.</td></tr>
                <?php else: foreach($rows as $r): ?>
                  <tr>
                    <td><?= (int)$r['id'] ?></td>
                    <td class="fw-semibold"><?= h($r['approval_type'] ?? '') ?></td>
                    <td><?= h(($r['reference_table'] ?? '').' #'.($r['reference_id'] ?? '')) ?></td>
                    <td><?= h($r['status'] ?? '') ?></td>
                    <td><?= h($r['requested_by'] ?? '') ?></td>
                    <td><?= h($r['approved_by'] ?? '') ?></td>
                    <td><?= h($r['created_at'] ?? '') ?></td>
                    <td class="text-end">
                      <?php if (($r['status'] ?? '') === 'pending'): ?>
                        <button class="btn btn-sm btn-success btnApprove" data-id="<?= (int)$r['id'] ?>">Approve</button>
                        <button class="btn btn-sm btn-danger btnReject" data-id="<?= (int)$r['id'] ?>">Reject</button>
                      <?php else: ?>
                        <span class="text-muted small">—</span>
                      <?php endif; ?>
                    </td>
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

<script>
async function post(url, id){
  const fd = new FormData();
  fd.append('id', id);
  const res = await fetch(url, {method:'POST', body: fd, credentials:'same-origin'});
  return await res.json();
}
document.querySelectorAll('.btnApprove').forEach(b=>{
  b.addEventListener('click', async ()=>{
    const j = await post('api/approvals/approve.php', b.dataset.id);
    alert(j.message || (j.success?'Approved':'Failed'));
    if (j.success) location.reload();
  });
});
document.querySelectorAll('.btnReject').forEach(b=>{
  b.addEventListener('click', async ()=>{
    const j = await post('api/approvals/reject.php', b.dataset.id);
    alert(j.message || (j.success?'Rejected':'Failed'));
    if (j.success) location.reload();
  });
});
</script>

<?php require_once dirname(dirname(__DIR__)) . '/templates/layout/footer.php'; ?>