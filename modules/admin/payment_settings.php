<?php
// admin/payment_settings.php
declare(strict_types=1);

require_once dirname(dirname(__DIR__)) . '/includes/bootstrap.php';
require_once dirname(dirname(__DIR__)) . '/includes/auth.php';
require_once dirname(dirname(__DIR__)) . '/includes/helpers.php';
require_once dirname(dirname(__DIR__)) . '/includes/rbac.php';

if (function_exists('require_admin_login')) require_admin_login();
require_permission('payments.manage');

$db = $GLOBALS['db'] ?? null;

function table_exists(mysqli $db, string $table): bool {
  $r = $db->query("SHOW TABLES LIKE '" . $db->real_escape_string($table) . "'");
  return $r && $r->num_rows > 0;
}

$page_title = "Payment Settings";
$page_subtitle = "Store payment gateway keys (Flutterwave, etc.)";

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
            // store in settings table by keys:
            // flutterwave_public_key, flutterwave_secret_key, flutterwave_env, etc.
            if (!table_exists($db,'settings')) {
              echo '<div class="alert alert-warning">settings table missing. Create it (see settings.php).</div>';
              require_once dirname(dirname(__DIR__)) . '/templates/layout/footer.php'; exit;
            }

            $keys = [
              'flutterwave_public_key',
              'flutterwave_secret_key',
              'flutterwave_env', // sandbox|live
              'flutterwave_encryption_key',
            ];

            $vals = [];
            $in = "'" . implode("','", array_map([$db,'real_escape_string'],$keys)) . "'";
            $rs = $db->query("SELECT `key`, `value` FROM settings WHERE `key` IN ($in)");
            if ($rs) {
              while ($r = $rs->fetch_assoc()) $vals[$r['key']] = $r['value'];
            }
          ?>

          <div class="card shadow-sm">
            <div class="card-body">
              <form id="formPay">
                <div class="row g-3">
                  <div class="col-md-6">
                    <label class="form-label">Flutterwave Public Key</label>
                    <input class="form-control" name="flutterwave_public_key" value="<?= h($vals['flutterwave_public_key'] ?? '') ?>">
                  </div>
                  <div class="col-md-6">
                    <label class="form-label">Flutterwave Secret Key</label>
                    <input class="form-control" name="flutterwave_secret_key" value="<?= h($vals['flutterwave_secret_key'] ?? '') ?>">
                  </div>
                  <div class="col-md-6">
                    <label class="form-label">Environment</label>
                    <select class="form-select" name="flutterwave_env">
                      <?php $env = (string)($vals['flutterwave_env'] ?? 'sandbox'); ?>
                      <option value="sandbox" <?= $env==='sandbox'?'selected':'' ?>>sandbox</option>
                      <option value="live" <?= $env==='live'?'selected':'' ?>>live</option>
                    </select>
                  </div>
                  <div class="col-md-6">
                    <label class="form-label">Encryption Key (optional)</label>
                    <input class="form-control" name="flutterwave_encryption_key" value="<?= h($vals['flutterwave_encryption_key'] ?? '') ?>">
                  </div>
                </div>

                <div class="d-flex justify-content-end mt-3">
                  <button class="btn btn-primary" type="submit">Save Payment Settings</button>
                </div>

                <div class="small text-muted mt-2">
                  Stored in <b>settings</b> table. Ensure you never expose secret keys on the public UI.
                </div>
              </form>
            </div>
          </div>

        <?php endif; ?>
      </div>
    </main>
  </div>
</div>

<script>
document.getElementById('formPay')?.addEventListener('submit', async (e)=>{
  e.preventDefault();
  const fd = new FormData(e.target);
  const res = await fetch('api/payment_settings/save.php', {method:'POST', body: fd, credentials:'same-origin'});
  const j = await res.json();
  alert(j.message || (j.success ? 'Saved' : 'Failed'));
});
</script>

<?php require_once dirname(dirname(__DIR__)) . '/templates/layout/footer.php'; ?>