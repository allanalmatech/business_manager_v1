<?php
// admin/permissions.php
declare(strict_types=1);

require_once dirname(dirname(__DIR__)) . '/includes/bootstrap.php';
require_once dirname(dirname(__DIR__)) . '/includes/auth.php';
require_once dirname(dirname(__DIR__)) . '/includes/helpers.php';
require_once dirname(dirname(__DIR__)) . '/includes/rbac.php';

if (function_exists('require_admin_login')) require_admin_login();
require_permission('permissions.manage');

$db = $GLOBALS['db'] ?? null;

function table_exists(mysqli $db, string $table): bool {
  $r = $db->query("SHOW TABLES LIKE '" . $db->real_escape_string($table) . "'");
  return $r && $r->num_rows > 0;
}

$page_title = "Permissions";
$page_subtitle = "Assign permissions to roles";

require_once dirname(dirname(__DIR__)) . '/templates/layout/header.php';
?>
<div class="app-shell">
  <?php require_once dirname(dirname(__DIR__)) . '/templates/layout/sidebar.php'; ?>

  <div class="app-content">
    <?php require_once dirname(dirname(__DIR__)) . '/templates/layout/topbar.php'; ?>

    <main class="page-wrap">
      <div class="container-fluid py-3">
        <div class="mb-3">
          <h4 class="mb-1"><?= h($page_title) ?></h4>
          <div class="text-muted small"><?= h($page_subtitle) ?></div>
        </div>

        <?php if (!$db instanceof mysqli): ?>
          <div class="alert alert-danger">Database not available.</div>
        <?php else: ?>
          <?php
            if (!table_exists($db,'roles')) {
              echo '<div class="alert alert-warning"><b>roles</b> table not found.</div>';
              require_once dirname(dirname(__DIR__)) . '/templates/layout/footer.php'; exit;
            }

            if (!table_exists($db,'permissions')) {
              echo '<div class="alert alert-warning"><b>permissions</b> table not found.</div>';
              echo '<div class="small text-muted">Create permissions first, then map to roles.</div>';
              require_once dirname(dirname(__DIR__)) . '/templates/layout/footer.php'; exit;
            }

            // mapping table name flexibility
            $mapTable = table_exists($db,'role_privileges') ? 'role_privileges' : (table_exists($db,'role_permissions') ? 'role_permissions' : '');
            if ($mapTable === '') {
              echo '<div class="alert alert-warning">Mapping table not found. Expected <b>role_privileges</b> or <b>role_permissions</b>.</div>';
              require_once dirname(dirname(__DIR__)) . '/templates/layout/footer.php'; exit;
            }

            $roles = $db->query("SELECT id, name FROM roles ORDER BY name ASC")?->fetch_all(MYSQLI_ASSOC) ?? [];
            $perms = $db->query("SELECT id, perm_key, description FROM permissions ORDER BY perm_key ASC")?->fetch_all(MYSQLI_ASSOC) ?? [];

            $selectedRole = (int)($_GET['role_id'] ?? ($roles[0]['id'] ?? 0));

            $assigned = [];
            if ($selectedRole > 0) {
              // Try both schema styles:
              // role_privileges(role_id, privilege_id) OR role_permissions(role_id, permission_id)
              $colPermId = ($mapTable === 'role_privileges') ? 'privilege_id' : 'permission_id';
              $st = $db->prepare("SELECT $colPermId AS pid FROM $mapTable WHERE role_id = ?");
              $st->bind_param('i', $selectedRole);
              $st->execute();
              $rs = $st->get_result();
              while ($r = $rs->fetch_assoc()) $assigned[(int)$r['pid']] = true;
              $st->close();
            }
          ?>

      <div class="card shadow-sm mb-3">
        <div class="card-body">
          <form class="row g-2 align-items-end" method="get">
            <div class="col-md-4">
              <label class="form-label">Role</label>
              <select class="form-select" name="role_id" onchange="this.form.submit()">
                <?php foreach ($roles as $r): ?>
                  <option value="<?= (int)$r['id'] ?>" <?= (int)$r['id']===$selectedRole?'selected':'' ?>>
                    <?= h($r['name']) ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-8 text-muted small">
              Tick permissions then click <b>Save Changes</b>.
            </div>
          </form>
        </div>
      </div>

      <div class="card shadow-sm">
        <div class="card-body">
          <?php if ($selectedRole <= 0): ?>
            <div class="alert alert-warning mb-0">No role selected.</div>
          <?php else: ?>
            <form id="formPerms">
              <input type="hidden" name="role_id" value="<?= (int)$selectedRole ?>">
              <div class="table-responsive">
                <table class="table table-sm table-hover align-middle">
                  <thead class="table-light">
                    <tr>
                      <th style="width:60px;">Allow</th>
                      <th>Permission</th>
                      <th>Description</th>
                    </tr>
                  </thead>
                  <tbody>
                    <?php foreach ($perms as $p): ?>
                      <?php $pid = (int)$p['id']; ?>
                      <tr>
                        <td>
                          <input class="form-check-input" type="checkbox" name="perm_ids[]"
                                 value="<?= $pid ?>" <?= isset($assigned[$pid])?'checked':'' ?>>
                        </td>
                        <td class="fw-semibold"><?= h($p['perm_key'] ?? '') ?></td>
                        <td class="text-muted"><?= h($p['description'] ?? '') ?></td>
                      </tr>
                    <?php endforeach; ?>
                  </tbody>
                </table>
              </div>

              <div class="d-flex justify-content-end">
                <button class="btn btn-primary" type="submit">Save Changes</button>
              </div>
            </form>
          <?php endif; ?>
        </div>
      </div>

        <?php endif; ?>
      </div>
    </main>
  </div>
</div>

<script>
document.getElementById('formPerms')?.addEventListener('submit', async (e)=>{
  e.preventDefault();
  const fd = new FormData(e.target);
  const res = await fetch('api/permissions/save_role_permissions.php', {
    method:'POST', body: fd, credentials:'same-origin'
  });
  const j = await res.json();
  alert(j.message || (j.success ? 'Saved' : 'Failed'));
  if (j.success) location.reload();
});
</script>

<?php require_once dirname(dirname(__DIR__)) . '/templates/layout/footer.php'; ?>
