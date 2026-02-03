<?php
// admin/audit_trail.php
declare(strict_types=1);

require_once dirname(dirname(__DIR__)) . '/includes/bootstrap.php';
require_once dirname(dirname(__DIR__)) . '/includes/auth.php';
require_once dirname(dirname(__DIR__)) . '/includes/helpers.php';
require_once dirname(dirname(__DIR__)) . '/includes/rbac.php';

if (function_exists('require_admin_login')) require_admin_login();
require_permission('audit.view');

$db = $GLOBALS['db'] ?? null;

function table_exists(mysqli $db, string $table): bool {
  $r = $db->query("SHOW TABLES LIKE '" . $db->real_escape_string($table) . "'");
  return $r && $r->num_rows > 0;
}

$page_title = "Audit Trail";
$page_subtitle = "System activity logs";

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
            if (!table_exists($db,'audit_logs')) {
              echo '<div class="alert alert-warning"><b>audit_logs</b> table not found.</div>';
              echo '<pre class="small bg-light border rounded p-2 mb-0">'.h(
"CREATE TABLE audit_logs (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NULL,
  action VARCHAR(100) NOT NULL,
  entity VARCHAR(80) NULL,
  entity_id VARCHAR(80) NULL,
  details TEXT NULL,
  ip_address VARCHAR(45) NULL,
  user_agent VARCHAR(255) NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_audit_user_id (user_id),
  INDEX idx_audit_action (action),
  CONSTRAINT fk_audit_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;"
          ).'</pre>';
          require_once dirname(dirname(__DIR__)) . '/templates/layout/footer.php'; exit;
        }

        $q = trim((string)($_GET['q'] ?? ''));
        $from = trim((string)($_GET['from'] ?? ''));
        $to = trim((string)($_GET['to'] ?? ''));
        $page = max(1,(int)($_GET['page'] ?? 1));
        $limit = 15;
        $off = ($page-1)*$limit;

        $where="1=1";
        $params=[];
        $types="";

        if ($q!=='') {
          $where.=" AND (action LIKE ? OR entity LIKE ? OR details LIKE ? OR ip_address LIKE ?)";
          $like="%$q%";
          $params=array_merge($params,[$like,$like,$like,$like]);
          $types.="ssss";
        }
        if ($from!=='') { $where.=" AND DATE(created_at) >= ?"; $params[]=$from; $types.="s"; }
        if ($to!=='') { $where.=" AND DATE(created_at) <= ?"; $params[]=$to; $types.="s"; }

        $stc=$db->prepare("SELECT COUNT(*) cnt FROM audit_logs WHERE $where");
        if ($types!=='') $stc->bind_param($types, ...$params);
        $stc->execute();
        $total=(int)($stc->get_result()->fetch_assoc()['cnt'] ?? 0);
        $stc->close();
        $pages=max(1,(int)ceil($total/$limit));

        $st=$db->prepare("SELECT id,user_id,action,entity,entity_id,details,ip_address,created_at
                          FROM audit_logs
                          WHERE $where
                          ORDER BY id DESC
                          LIMIT ? OFFSET ?");
        $bindTypes=$types."ii";
        $bind=$params; $bind[]=$limit; $bind[]=$off;
        $st->bind_param($bindTypes, ...$bind);
        $st->execute();
        $rs=$st->get_result();
        $rows=[];
        while($r=$rs->fetch_assoc()) $rows[]=$r;
        $st->close();
      ?>

      <div class="card shadow-sm">
        <div class="card-body">

          <form class="row g-2 mb-3" method="get">
            <div class="col-md-5">
              <input class="form-control" name="q" value="<?= h($q) ?>" placeholder="Search action/entity/details/ip...">
            </div>
            <div class="col-md-3">
              <input class="form-control" type="date" name="from" value="<?= h($from) ?>">
            </div>
            <div class="col-md-3">
              <input class="form-control" type="date" name="to" value="<?= h($to) ?>">
            </div>
            <div class="col-md-1 d-grid">
              <button class="btn btn-primary">Go</button>
            </div>
          </form>

          <!-- Delete Old Logs Button -->
          <div class="card border-warning mb-3">
            <div class="card-body">
              <div class="d-flex justify-content-between align-items-center">
                <div>
                  <h6 class="mb-1 text-warning">
                    <i class="bi bi-exclamation-triangle"></i> Delete Old Logs
                  </h6>
                  <div class="small text-muted">Permanently remove audit logs within a specific date range</div>
                </div>
                <button class="btn btn-warning" data-bs-toggle="modal" data-bs-target="#deleteLogsModal">
                  <i class="bi bi-trash"></i> Delete Logs
                </button>
              </div>
            </div>
          </div>

          <div class="table-responsive">
            <table class="table table-sm table-hover align-middle">
              <thead class="table-light">
                <tr>
                  <th>#</th>
                  <th>User</th>
                  <th>Action</th>
                  <th>Entity</th>
                  <th>Entity ID</th>
                  <th>IP</th>
                  <th>Date</th>
                </tr>
              </thead>
              <tbody>
                <?php if(!$rows): ?>
                  <tr><td colspan="7" class="text-center text-muted py-4">No logs found.</td></tr>
                <?php else: foreach($rows as $r): ?>
                  <tr title="<?= h((string)($r['details'] ?? '')) ?>">
                    <td><?= (int)$r['id'] ?></td>
                    <td><?= h((string)($r['user_id'] ?? '')) ?></td>
                    <td class="fw-semibold"><?= h((string)($r['action'] ?? '')) ?></td>
                    <td><?= h((string)($r['entity'] ?? '')) ?></td>
                    <td><?= h((string)($r['entity_id'] ?? '')) ?></td>
                    <td><?= h((string)($r['ip_address'] ?? '')) ?></td>
                    <td><?= h((string)($r['created_at'] ?? '')) ?></td>
                  </tr>
                <?php endforeach; endif; ?>
              </tbody>
            </table>
          </div>

          <div class="d-flex justify-content-between align-items-center mt-3">
            <div class="small text-muted">Showing <?= count($rows) ?> of <?= $total ?></div>
            <nav>
              <ul class="pagination pagination-sm mb-0">
                <li class="page-item <?= $page<=1?'disabled':'' ?>">
                  <a class="page-link" href="?q=<?=h($q)?>&from=<?=h($from)?>&to=<?=h($to)?>&page=<?=max(1,$page-1)?>">Prev</a>
                </li>
                <?php for($p=max(1,$page-2); $p<=min($pages,$page+2); $p++): ?>
                  <li class="page-item <?= $p===$page?'active':'' ?>">
                    <a class="page-link" href="?q=<?=h($q)?>&from=<?=h($from)?>&to=<?=h($to)?>&page=<?=$p?>"><?=$p?></a>
                  </li>
                <?php endfor; ?>
                <li class="page-item <?= $page>=$pages?'disabled':'' ?>">
                  <a class="page-link" href="?q=<?=h($q)?>&from=<?=h($from)?>&to=<?=h($to)?>&page=<?=min($pages,$page+1)?>">Next</a>
                </li>
              </ul>
            </nav>
          </div>

        </div>
      </div>
    <?php endif; ?>
  </div>
</div>

<!-- Delete Logs Modal -->
<div class="modal fade" id="deleteLogsModal" tabindex="-1" aria-labelledby="deleteLogsModalLabel" aria-hidden="true">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header bg-warning text-dark">
        <h5 class="modal-title" id="deleteLogsModalLabel">
          <i class="bi bi-exclamation-triangle"></i> Delete Audit Logs
        </h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <form id="deleteLogsForm">
        <div class="modal-body">
          <div class="alert alert-warning d-flex align-items-center" role="alert">
            <i class="bi bi-exclamation-triangle-fill me-2"></i>
            <div>
              <strong>Warning:</strong> This action cannot be undone. All audit logs within the selected date range will be permanently deleted.
            </div>
          </div>
          
          <div class="mb-3">
            <label for="deleteFrom" class="form-label">Delete Logs From Date</label>
            <input type="date" class="form-control" id="deleteFrom" name="delete_from" required>
            <div class="form-text">Select the start date for logs to be deleted</div>
          </div>
          
          <div class="mb-3">
            <label for="deleteTo" class="form-label">To Date</label>
            <input type="date" class="form-control" id="deleteTo" name="delete_to" required>
            <div class="form-text">Select the end date for logs to be deleted</div>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-warning">
            <i class="bi bi-trash"></i> Delete Logs
          </button>
        </div>
      </form>
    </div>
  </div>
</div>

<script>
// Delete Logs Form Handler
document.getElementById('deleteLogsForm')?.addEventListener('submit', async (e) => {
  e.preventDefault();
  
  const formData = new FormData(e.target);
  const deleteFrom = formData.get('delete_from');
  const deleteTo = formData.get('delete_to');
  
  if (!deleteFrom || !deleteTo) {
    alert('Please select both from and to dates.');
    return;
  }
  
  if (new Date(deleteFrom) > new Date(deleteTo)) {
    alert('From date cannot be later than to date.');
    return;
  }
  
  if (!confirm(`Are you sure you want to permanently delete all audit logs from ${deleteFrom} to ${deleteTo}?\n\nThis action cannot be undone!`)) {
    return;
  }
  
  // Show loading state
  const submitBtn = e.target.querySelector('button[type="submit"]');
  const originalText = submitBtn.innerHTML;
  submitBtn.innerHTML = '<i class="bi bi-hourglass-split"></i> Deleting...';
  submitBtn.disabled = true;
  
  try {
    const response = await fetch('api/audit_logs/delete_range.php', {
      method: 'POST',
      body: formData,
      credentials: 'same-origin'
    });
    
    const result = await response.json();
    
    if (result.success) {
      // Close modal and show success message
      const modal = bootstrap.Modal.getInstance(document.getElementById('deleteLogsModal'));
      modal.hide();
      
      // Show success toast or alert
      setTimeout(() => {
        alert(`Successfully deleted ${result.deleted_count || 'unknown number of'} audit logs.`);
        // Reset form and reload page to show updated data
        e.target.reset();
        window.location.reload();
      }, 500);
    } else {
      alert(`Error: ${result.message || 'Failed to delete logs'}`);
    }
  } catch (error) {
    console.error('Delete logs error:', error);
    alert('Error: Failed to communicate with server. Please try again.');
  } finally {
    // Restore button state
    submitBtn.innerHTML = originalText;
    submitBtn.disabled = false;
  }
});

// Reset form when modal is hidden
document.getElementById('deleteLogsModal')?.addEventListener('hidden.bs.modal', () => {
  document.getElementById('deleteLogsForm')?.reset();
});
</script>

<?php require_once dirname(dirname(__DIR__)) . '/templates/layout/footer.php'; ?>