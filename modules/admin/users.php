<?php
// admin/users.php
declare(strict_types=1);

require_once dirname(dirname(__DIR__)) . '/includes/bootstrap.php';
require_once dirname(dirname(__DIR__)) . '/includes/auth.php';
require_once dirname(dirname(__DIR__)) . '/includes/helpers.php';
require_once dirname(dirname(__DIR__)) . '/includes/rbac.php';

if (function_exists('require_admin_login')) require_admin_login();
require_permission('users.view');

$db = $GLOBALS['db'] ?? null;

function table_exists(mysqli $db, string $table): bool {
  $r = $db->query("SHOW TABLES LIKE '" . $db->real_escape_string($table) . "'");
  return $r && $r->num_rows > 0;
}

$page_title = "Users";
$page_subtitle = "Manage system users";

require_once dirname(dirname(__DIR__)) . '/templates/layout/header.php';
?>
<div class="app-shell">
  <?php require_once dirname(dirname(__DIR__)) . '/templates/layout/sidebar.php'; ?>

  <div class="app-content">
    <?php require_once dirname(dirname(__DIR__)) . '/templates/layout/topbar.php'; ?>

    <main class="page-wrap">
      <div class="container-fluid py-3">

       <!-- <div class="d-flex align-items-start justify-content-between flex-wrap gap-2 mb-3">
          <div>
            <h4 class="mb-1"><?= h($page_title) ?></h4>
            <div class="text-muted small"><?= h($page_subtitle) ?></div>
          </div> -->

          <?php if ($db instanceof mysqli): ?>
            <div class="d-flex gap-2">
              <?php if (function_exists('require_permission')): ?>
                <?php /* Keep button visible; enforce in API */ ?>
              <?php endif; ?>
              <button class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#modalAddUser">
                <i class="bi bi-plus-lg me-1"></i> Add User
              </button>
            </div>
          <?php endif; ?>
        </div>

        <?php if (!$db instanceof mysqli): ?>
          <div class="alert alert-danger mb-0">Database not available.</div>
        <?php else: ?>

          <?php
            if (!table_exists($db, 'users')) {
              echo '<div class="alert alert-warning"><b>users</b> table not found.</div>';
              require_once dirname(dirname(__DIR__)) . '/templates/layout/footer.php';
              exit;
            }

            // filters
            $q = trim((string)($_GET['q'] ?? ''));
            $page = max(1, (int)($_GET['page'] ?? 1));
            $limit = 15;
            $off = ($page - 1) * $limit;

            $where = "1=1";
            $params = [];
            $types = "";

            if ($q !== '') {
              $where .= " AND (name LIKE ? OR email LIKE ? OR phone LIKE ?)";
              $like = "%{$q}%";
              $params = [$like, $like, $like];
              $types = "sss";
            }

            // count
            $stc = $db->prepare("SELECT COUNT(*) AS cnt FROM users WHERE $where");
            if ($types !== '') $stc->bind_param($types, ...$params);
            $stc->execute();
            $total = (int)($stc->get_result()->fetch_assoc()['cnt'] ?? 0);
            $stc->close();

            $pages = max(1, (int)ceil($total / $limit));

            // list
            $sql = "SELECT id, full_name, email, phone, role_id, is_active, created_at
                    FROM users
                    WHERE $where
                    ORDER BY id DESC
                    LIMIT ? OFFSET ?";
            $st = $db->prepare($sql);
            $bindTypes = $types . "ii";
            $bind = $params;
            $bind[] = $limit;
            $bind[] = $off;
            $st->bind_param($bindTypes, ...$bind);
            $st->execute();
            $rs = $st->get_result();

            $rows = [];
            while ($r = $rs->fetch_assoc()) $rows[] = $r;
            $st->close();
          ?>

          <div class="card shadow-sm">
            <div class="card-body">

              <form class="row g-2 mb-3" method="get">
                <div class="col-md-6">
                  <input class="form-control" name="q" value="<?= h($q) ?>" placeholder="Search name/email/phone">
                </div>
                <div class="col-md-2 d-grid">
                  <button class="btn btn-outline-primary">Search</button>
                </div>
                <div class="col-md-2 d-grid">
                  <a class="btn btn-outline-secondary" href="?">Reset</a>
                </div>
              </form>

              <div class="table-responsive">
                <table class="table table-sm table-hover align-middle">
                  <thead class="table-light">
                    <tr>
                      <th>#</th>
                      <th>Name</th>
                      <th>Email</th>
                      <th>Phone</th>
                      <th>Role ID</th>
                      <th>Status</th>
                      <th>Created</th>
                      <th class="text-end">Actions</th>
                    </tr>
                  </thead>
                  <tbody>
                    <?php if (!$rows): ?>
                      <tr><td colspan="8" class="text-center text-muted py-4">No users found.</td></tr>
                    <?php else: foreach ($rows as $r): ?>
                      <tr>
                        <td><?= (int)$r['id'] ?></td>
                        <td class="fw-semibold"><?= h($r['full_name'] ?? '') ?></td>
                        <td><?= h($r['email'] ?? '') ?></td>
                        <td><?= h($r['phone'] ?? '') ?></td>
                        <td><?= h($r['role_id'] ?? '') ?></td>
                        <td><?= h($r['is_active'] ? 'Active' : 'Inactive') ?></td>
                        <td><?= h($r['created_at'] ?? '') ?></td>
                        <td class="text-end">
                          <button class="btn btn-sm btn-outline-secondary btnEdit"
                                  data-id="<?= (int)$r['id'] ?>"
                                  data-name="<?= h($r['full_name'] ?? '') ?>"
                                  data-email="<?= h($r['email'] ?? '') ?>"
                                  data-phone="<?= h($r['phone'] ?? '') ?>"
                                  data-role_id="<?= h($r['role_id'] ?? '') ?>"
                                  data-status="<?= h($r['is_active'] ?? '') ?>"
                                  title="Edit User">
                            <i class="bi bi-pencil"></i>
                          </button>
                          <button class="btn btn-sm btn-outline-danger btnDelete"
                                  data-id="<?= (int)$r['id'] ?>"
                                  title="Delete User">
                            <i class="bi bi-trash"></i>
                          </button>
                        </td>
                      </tr>
                    <?php endforeach; endif; ?>
                  </tbody>
                </table>
              </div>

              <div class="d-flex justify-content-between align-items-center mt-3">
                <div class="small text-muted">Showing <?= count($rows) ?> of <?= $total ?></div>
                <nav>
                  <ul class="pagination pagination-sm mb-0">
                    <li class="page-item <?= $page <= 1 ? 'disabled' : '' ?>">
                      <a class="page-link" href="?q=<?= h($q) ?>&page=<?= max(1, $page-1) ?>">Prev</a>
                    </li>
                    <?php
                      $start = max(1, $page-2);
                      $end = min($pages, $page+2);
                      for ($p=$start; $p<=$end; $p++):
                    ?>
                    <li class="page-item <?= $p===$page?'active':'' ?>">
                      <a class="page-link" href="?q=<?= h($q) ?>&page=<?= $p ?>"><?= $p ?></a>
                    </li>
                    <?php endfor; ?>
                    <li class="page-item <?= $page >= $pages ? 'disabled' : '' ?>">
                      <a class="page-link" href="?q=<?= h($q) ?>&page=<?= min($pages, $page+1) ?>">Next</a>
                    </li>
                  </ul>
                </nav>
              </div>

            </div>
          </div>

        <?php endif; ?>
      </div>
    </main>
  </div>
</div>

<?php require_once dirname(dirname(__DIR__)) . '/templates/layout/footer.php'; ?>

<!-- Add User Modal -->
<div class="modal fade" id="modalAddUser" tabindex="-1">
  <div class="modal-dialog">
    <form class="modal-content" id="formAddUser">
      <div class="modal-header">
        <h5 class="modal-title">Add User</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <div class="mb-2">
          <label class="form-label">Name</label>
          <input class="form-control" name="name" required>
        </div>
        <div class="mb-2">
          <label class="form-label">Email</label>
          <input class="form-control" name="email" type="email" required>
        </div>
        <div class="mb-2">
          <label class="form-label">Phone</label>
          <input class="form-control" name="phone">
        </div>
        <div class="mb-2">
          <label class="form-label">Role ID</label>
          <input class="form-control" name="role_id" type="number" min="1">
        </div>
        <div class="mb-2">
          <label class="form-label">Status</label>
          <select class="form-select" name="is_active">
            <option value="1">Active</option>
            <option value="0">Inactive</option>
          </select>
        </div>
        <div class="mb-2">
          <label class="form-label">Password</label>
          <input class="form-control" name="password" type="password" required>
        </div>
      </div>
      <div class="modal-footer">
        <button class="btn btn-outline-secondary" type="button" data-bs-dismiss="modal">Cancel</button>
        <button class="btn btn-primary" type="submit">Save</button>
      </div>
    </form>
  </div>
</div>

<!-- Edit User Modal -->
<div class="modal fade" id="modalEditUser" tabindex="-1">
  <div class="modal-dialog">
    <form class="modal-content" id="formEditUser">
      <div class="modal-header">
        <h5 class="modal-title">Edit User</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <input type="hidden" name="id" id="edit_id">
        <div class="mb-2">
          <label class="form-label">Name</label>
          <input class="form-control" name="name" id="edit_name" required>
        </div>
        <div class="mb-2">
          <label class="form-label">Email</label>
          <input class="form-control" name="email" id="edit_email" type="email" required>
        </div>
        <div class="mb-2">
          <label class="form-label">Phone</label>
          <input class="form-control" name="phone" id="edit_phone">
        </div>
        <div class="mb-2">
          <label class="form-label">Role ID</label>
          <input class="form-control" name="role_id" id="edit_role_id" type="number" min="1">
        </div>
        <div class="mb-2">
          <label class="form-label">Status</label>
          <select class="form-select" name="is_active" id="edit_is_active">
            <option value="1">Active</option>
            <option value="0">Inactive</option>
          </select>
        </div>
        <div class="mb-2">
          <label class="form-label">New Password (optional)</label>
          <input class="form-control" name="password" type="password" placeholder="Leave blank to keep current">
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
    el.role = 'alert';
    el.innerHTML = `<div class="d-flex"><div class="toast-body">${msg}</div>
      <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button></div>`;
    document.body.appendChild(el);
    const t = new bootstrap.Toast(el, {delay: 2200});
    t.show();
    el.addEventListener('hidden.bs.toast', ()=>el.remove());
  };

  const postForm = async (url, form) => {
    const fd = new FormData(form);
    const res = await fetch(url, {method:'POST', body: fd, credentials:'same-origin'});
    return await res.json();
  };

  // Add
  const addForm = document.getElementById('formAddUser');
  addForm?.addEventListener('submit', async (e)=>{
    e.preventDefault();
    const j = await postForm('api/users/create.php', addForm);
    if (j.success) { toast(j.message||'Saved'); location.reload(); }
    else toast(j.message||'Failed', false);
  });

  // Edit open
  document.querySelectorAll('.btnEdit').forEach(btn=>{
    btn.addEventListener('click', ()=>{
      document.getElementById('edit_id').value = btn.dataset.id||'';
      document.getElementById('edit_name').value = btn.dataset.name||'';
      document.getElementById('edit_email').value = btn.dataset.email||'';
      document.getElementById('edit_phone').value = btn.dataset.phone||'';
      document.getElementById('edit_role_id').value = btn.dataset.role_id||'';
      document.getElementById('edit_is_active').value = btn.dataset.status||'1';
      new bootstrap.Modal(document.getElementById('modalEditUser')).show();
    });
  });

  // Edit submit
  const editForm = document.getElementById('formEditUser');
  editForm?.addEventListener('submit', async (e)=>{
    e.preventDefault();
    const j = await postForm('api/users/update.php', editForm);
    if (j.success) { toast(j.message||'Updated'); location.reload(); }
    else toast(j.message||'Failed', false);
  });

  // Delete
  document.querySelectorAll('.btnDelete').forEach(btn=>{
    btn.addEventListener('click', async ()=>{
      if (!confirm('Delete this user?')) return;
      const fd = new FormData();
      fd.append('id', btn.dataset.id||'');
      const res = await fetch('api/users/delete.php', {method:'POST', body: fd, credentials:'same-origin'});
      const j = await res.json();
      if (j.success) { toast(j.message||'Deleted'); location.reload(); }
      else toast(j.message||'Failed', false);
    });
  });
})();
</script>

