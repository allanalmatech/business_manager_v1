<?php
// admin/roles.php
declare(strict_types=1);

require_once dirname(dirname(__DIR__)) . '/includes/bootstrap.php';
require_once dirname(dirname(__DIR__)) . '/includes/auth.php';
require_once dirname(dirname(__DIR__)) . '/includes/helpers.php';
require_once dirname(dirname(__DIR__)) . '/includes/rbac.php';

if (function_exists('require_admin_login')) require_admin_login();
require_permission('roles.view');

$db = $GLOBALS['db'] ?? null;

function table_exists(mysqli $db, string $table): bool {
  $r = $db->query("SHOW TABLES LIKE '" . $db->real_escape_string($table) . "'");
  return $r && $r->num_rows > 0;
}

$page_title = "Roles";
$page_subtitle = "Create and manage roles";

require_once dirname(dirname(__DIR__)) . '/templates/layout/header.php';
?>
<div class="app-shell">
  <?php require_once dirname(dirname(__DIR__)) . '/templates/layout/sidebar.php'; ?>

  <div class="app-content">
    <?php require_once dirname(dirname(__DIR__)) . '/templates/layout/topbar.php'; ?>

    <main class="page-wrap">
      <div class="container-fluid py-3">
        <div class="d-flex align-items-start justify-content-between flex-wrap gap-2 mb-3">
       <!--   <div>
            <h4 class="mb-1"><?= h($page_title) ?></h4>
            <div class="text-muted small"><?= h($page_subtitle) ?></div>
          </div> -->
          <button class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#modalAddRole">
            <i class="bi bi-plus-lg me-1"></i> Add Role
          </button>
        </div>

        <?php if (!$db instanceof mysqli): ?>
          <div class="alert alert-danger mb-0">Database not available.</div>
        <?php else: ?>
          <?php
            if (!table_exists($db,'roles')) {
              echo '<div class="alert alert-warning"><b>roles</b> table not found.</div>';
              require_once dirname(dirname(__DIR__)) . '/templates/layout/footer.php'; exit;
            }

            $rs = $db->query("SELECT id, name, description, created_at FROM roles ORDER BY id DESC");
            $rows = $rs ? $rs->fetch_all(MYSQLI_ASSOC) : [];
          ?>

          <div class="card shadow-sm">
            <div class="card-body">
              <div class="table-responsive">
                <table class="table table-sm table-hover align-middle">
                  <thead class="table-light">
                    <tr>
                      <th>#</th>
                      <th>Name</th>
                      <th>Description</th>
                      <th>Created</th>
                      <th class="text-end">Actions</th>
                    </tr>
                  </thead>
                  <tbody>
                    <?php if (!$rows): ?>
                      <tr><td colspan="5" class="text-center text-muted py-4">No roles yet.</td></tr>
                    <?php else: foreach ($rows as $r): ?>
                      <tr>
                        <td><?= (int)$r['id'] ?></td>
                        <td class="fw-semibold"><?= h($r['name'] ?? '') ?></td>
                        <td><?= h($r['description'] ?? '') ?></td>
                        <td><?= h($r['created_at'] ?? '') ?></td>
                        <td class="text-end">
                          <button class="btn btn-sm btn-outline-secondary btnEdit"
                                  data-id="<?= (int)$r['id'] ?>"
                                  data-name="<?= h($r['name'] ?? '') ?>"
                                  data-description="<?= h($r['description'] ?? '') ?>">Edit</button>
                          <button class="btn btn-sm btn-outline-danger btnDelete"
                                  data-id="<?= (int)$r['id'] ?>">Delete</button>
                        </td>
                      </tr>
                    <?php endforeach; endif; ?>
                  </tbody>
                </table>
              </div>
              <div class="small text-muted">Tip: assign permissions in <b>permissions.php</b>.</div>
            </div>
          </div>
        <?php endif; ?>
      </div>
    </main>
  </div>
</div>

<div class="modal fade" id="modalAddRole" tabindex="-1">
  <div class="modal-dialog">
    <form class="modal-content" id="formAddRole">
      <div class="modal-header">
        <h5 class="modal-title">Add Role</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <div class="mb-2">
          <label class="form-label">Role Name</label>
          <input class="form-control" name="name" required>
        </div>
        <div class="mb-2">
          <label class="form-label">Description</label>
          <textarea class="form-control" name="description" rows="3"></textarea>
        </div>
      </div>
      <div class="modal-footer">
        <button class="btn btn-outline-secondary" type="button" data-bs-dismiss="modal">Cancel</button>
        <button class="btn btn-primary" type="submit">Save</button>
      </div>
    </form>
  </div>
</div>

<div class="modal fade" id="modalEditRole" tabindex="-1">
  <div class="modal-dialog">
    <form class="modal-content" id="formEditRole">
      <div class="modal-header">
        <h5 class="modal-title">Edit Role</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <input type="hidden" name="id" id="edit_id">
        <div class="mb-2">
          <label class="form-label">Role Name</label>
          <input class="form-control" name="name" id="edit_name" required>
        </div>
        <div class="mb-2">
          <label class="form-label">Description</label>
          <textarea class="form-control" name="description" id="edit_description" rows="3"></textarea>
        </div>
      </div>
      <div class="modal-footer">
        <button class="btn btn-outline-secondary" type="button" data-bs-dismiss="modal">Cancel</button>
        <button class="btn btn-primary" type="submit">Update</button>
      </div>
    </form>
  </div>
</div>

<script>
(async function(){
  const toast = (msg, ok=true) => {
    const el = document.createElement('div');
    el.className = 'toast align-items-center text-bg-' + (ok?'success':'danger') + ' border-0 position-fixed bottom-0 end-0 m-3';
    el.innerHTML = `<div class="d-flex"><div class="toast-body">${msg}</div>
      <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button></div>`;
    document.body.appendChild(el);
    const t = new bootstrap.Toast(el, {delay: 2000});
    t.show();
    el.addEventListener('hidden.bs.toast', ()=>el.remove());
  };

  const postForm = async (url, form) => {
    const fd = new FormData(form);
    const res = await fetch(url, {method:'POST', body: fd, credentials:'same-origin'});
    return await res.json();
  };

  document.getElementById('formAddRole')?.addEventListener('submit', async (e)=>{
    e.preventDefault();
    const j = await postForm('api/roles/create.php', e.target);
    if (j.success) { toast(j.message||'Saved'); location.reload(); }
    else toast(j.message||'Failed', false);
  });

  document.querySelectorAll('.btnEdit').forEach(btn=>{
    btn.addEventListener('click', ()=>{
      edit_id.value = btn.dataset.id||'';
      edit_name.value = btn.dataset.name||'';
      edit_description.value = btn.dataset.description||'';
      new bootstrap.Modal(document.getElementById('modalEditRole')).show();
    });
  });

  document.getElementById('formEditRole')?.addEventListener('submit', async (e)=>{
    e.preventDefault();
    const j = await postForm('api/roles/update.php', e.target);
    if (j.success) { toast(j.message||'Updated'); location.reload(); }
    else toast(j.message||'Failed', false);
  });

  document.querySelectorAll('.btnDelete').forEach(btn=>{
    btn.addEventListener('click', async ()=>{
      if (!confirm('Delete this role?')) return;
      const fd = new FormData();
      fd.append('id', btn.dataset.id||'');
      const res = await fetch('api/roles/delete.php', {method:'POST', body: fd, credentials:'same-origin'});
      const j = await res.json();
      if (j.success) { toast(j.message||'Deleted'); location.reload(); }
      else toast(j.message||'Failed', false);
    });
  });
})();
</script>

<?php require_once dirname(dirname(__DIR__)) . '/templates/layout/footer.php'; ?>