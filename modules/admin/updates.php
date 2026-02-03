<?php
// admin/updates.php
declare(strict_types=1);

require_once dirname(dirname(__DIR__)) . '/includes/bootstrap.php';
require_once dirname(dirname(__DIR__)) . '/includes/auth.php';
require_once dirname(dirname(__DIR__)) . '/includes/helpers.php';
require_once dirname(dirname(__DIR__)) . '/includes/rbac.php';

if (function_exists('require_admin_login')) require_admin_login();
require_permission('updates.manage');

$db = $GLOBALS['db'] ?? null;

$page_title = "Updates";
$page_subtitle = "Check and apply system updates";

require_once dirname(dirname(__DIR__)) . '/templates/layout/header.php';

$currentVersion = defined('APP_VERSION') ? (string)APP_VERSION : '1.0.0';
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

        <div class="card shadow-sm">
          <div class="card-body">
            <div class="mb-2">
              <div class="text-muted small">Current version</div>
              <div class="fs-5 fw-semibold"><?= h($currentVersion) ?></div>
            </div>

            <div class="d-flex gap-2">
              <button class="btn btn-outline-primary" id="btnCheck">Check for Updates</button>
              <button class="btn btn-primary" id="btnApply" disabled>Apply Update</button>
            </div>

            <div class="mt-3">
              <pre class="bg-light border rounded p-2 small mb-0" id="logBox">Ready.</pre>
            </div>

            <div class="small text-muted mt-2">
              This page expects API endpoints:
              <b>api/updates/check.php</b> and <b>api/updates/apply.php</b>.
            </div>
          </div>
        </div>
      </div>
    </main>
  </div>
</div>

<script>
(async function(){
  const logBox = document.getElementById('logBox');
  const btnCheck = document.getElementById('btnCheck');
  const btnApply = document.getElementById('btnApply');

  let payload = null;

  const log = (m)=>{ logBox.textContent += "\n" + m; };

  btnCheck.addEventListener('click', async ()=>{
    logBox.textContent = "Checking...";
    const res = await fetch('api/updates/check.php', {credentials:'same-origin'});
    const j = await res.json();
    payload = j;
    logBox.textContent = JSON.stringify(j, null, 2);
    btnApply.disabled = !j.success || !j.update_available;
  });

  btnApply.addEventListener('click', async ()=>{
    if (!payload?.update_available) return;
    if (!confirm('Apply update now?')) return;

    logBox.textContent = "Applying update...";
    const res = await fetch('api/updates/apply.php', {method:'POST', credentials:'same-origin'});
    const j = await res.json();
    logBox.textContent = JSON.stringify(j, null, 2);
    btnApply.disabled = true;
  });
})();
</script>

<?php require_once dirname(dirname(__DIR__)) . '/templates/layout/footer.php'; ?>