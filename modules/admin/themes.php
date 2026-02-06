<?php
// admin/themes.php
declare(strict_types=1);

require_once dirname(dirname(__DIR__)) . '/includes/bootstrap.php';
require_once dirname(dirname(__DIR__)) . '/includes/auth.php';
require_once dirname(dirname(__DIR__)) . '/includes/helpers.php';
require_once dirname(dirname(__DIR__)) . '/includes/rbac.php';

if (function_exists('require_admin_login')) require_admin_login();
require_permission('settings.manage');

$db = $GLOBALS['db'] ?? null;
$page_title = "Themes";
$page_subtitle = "Customize the system appearance";

// Get current theme from settings
$current_theme = 'default';
if ($db) {
    $stmt = $db->prepare("SELECT value FROM settings WHERE `key` = 'app_theme' LIMIT 1");
    if ($stmt) {
        $stmt->execute();
        $res = $stmt->get_result();
        if ($row = $res->fetch_assoc()) {
            $current_theme = $row['value'];
        }
        $stmt->close();
    }
}

$themes = [
    'default' => ['name' => 'Default Blue', 'color' => '#0d6efd'],
    'dark'    => ['name' => 'Dark Midnight', 'color' => '#375a7f'],
    'ocean'   => ['name' => 'Ocean Breeze', 'color' => '#0077b6'],
    'forest'  => ['name' => 'Forest Green', 'color' => '#2d6a4f'],
    'sunset'  => ['name' => 'Sunset Orange', 'color' => '#e67e22'],
    'royal'   => ['name' => 'Royal Purple', 'color' => '#6f42c1'],
    'slate'   => ['name' => 'Minimal Slate', 'color' => '#475569'],
    'rose'    => ['name' => 'Rose Garden', 'color' => '#d81b60'],
    'coffee'  => ['name' => 'Coffee Shop', 'color' => '#795548'],
    'cyber'   => ['name' => 'Cyberpunk Neon', 'color' => '#00ff41'],
];

require_once dirname(dirname(__DIR__)) . '/templates/layout/header.php';
?>
<div class="app-shell">
  <?php require_once dirname(dirname(__DIR__)) . '/templates/layout/sidebar.php'; ?>

  <div class="app-content">
    <?php require_once dirname(dirname(__DIR__)) . '/templates/layout/topbar.php'; ?>

    <main class="page-wrap">
      <div class="container-fluid py-3">
        
        <div class="row g-4">
          <div class="col-12 col-lg-8">
            <div class="card shadow-sm border-0 rounded-4">
              <div class="card-body p-4">
                <div class="d-flex align-items-center justify-content-between mb-4">
                  <h5 class="fw-bold mb-0">System Themes</h5>
                  <span class="badge bg-primary bg-opacity-10 text-primary px-3 py-2 rounded-pill">10 Presets</span>
                </div>
                <div class="row g-3">
                  <?php foreach ($themes as $id => $theme): ?>
                    <div class="col-6 col-md-4 col-xl-3">
                      <div class="theme-card border rounded-4 p-3 text-center position-relative <?= $current_theme === $id ? 'border-primary border-2 bg-light bg-opacity-50' : '' ?>" 
                           style="cursor: pointer;" onclick="setTheme('<?= $id ?>')">
                        <?php if ($current_theme === $id): ?>
                          <span class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-primary p-2">
                            <i class="bi bi-check-lg"></i>
                          </span>
                        <?php endif; ?>
                        <div class="theme-preview-circle mx-auto mb-3 shadow-sm" style="background-color: <?= $theme['color'] ?>;"></div>
                        <div class="small fw-bold"><?= h($theme['name']) ?></div>
                        <div class="text-muted extra-small text-uppercase mt-1"><?= h($id) ?></div>
                      </div>
                    </div>
                  <?php endforeach; ?>
                </div>
              </div>
            </div>
          </div>

          <div class="col-12 col-lg-4">
            <div class="card shadow-sm border-0 rounded-4 mb-4">
              <div class="card-body p-4">
                <h5 class="fw-bold mb-3">Custom Theme</h5>
                <p class="text-muted small">Upload a custom CSS file to personalize your experience further.</p>
                <form id="formUploadTheme" enctype="multipart/form-data">
                  <div class="mb-3">
                    <label class="form-label small fw-bold">Theme Name</label>
                    <input type="text" name="theme_name" class="form-control form-control-sm" placeholder="My Custom Theme" required>
                  </div>
                  <div class="mb-3">
                    <label class="form-label small fw-bold">CSS File</label>
                    <input type="file" name="theme_file" class="form-control form-control-sm" accept=".css" required>
                  </div>
                  <button type="submit" class="btn btn-primary btn-sm w-100">Upload & Apply</button>
                </form>
              </div>
            </div>

            <div class="card shadow-sm border-0 rounded-4">
              <div class="card-body p-4">
                <h5 class="fw-bold mb-3">Active Theme</h5>
                <div class="d-flex align-items-center gap-3 p-3 bg-light rounded-4">
                  <div class="rounded-circle" style="width: 32px; height: 32px; background-color: var(--primary-color);"></div>
                  <div>
                    <div class="fw-bold"><?= h($themes[$current_theme]['name'] ?? 'Custom Theme') ?></div>
                    <div class="text-muted small text-uppercase"><?= h($current_theme) ?></div>
                  </div>
                </div>
              </div>
            </div>
          </div>
        </div>

      </div>
    </main>
  </div>
</div>

<script>
async function setTheme(themeId) {
    const fd = new FormData();
    fd.append('key', 'app_theme');
    fd.append('value', themeId);
    
    try {
        const res = await fetch('<?= $BASE_URL ?>/api/settings/upsert.php', {
            method: 'POST',
            body: fd
        });
        const j = await res.json();
        if (j.success) {
            document.documentElement.setAttribute('data-theme', themeId);
            location.reload();
        } else {
            alert(j.message || 'Failed to update theme');
        }
    } catch (e) {
        alert('Error connecting to server');
    }
}

document.getElementById('formUploadTheme').addEventListener('submit', async (e) => {
    e.preventDefault();
    const formData = new FormData(e.target);
    
    try {
        const res = await fetch('<?= $BASE_URL ?>/api/admin/upload_theme.php', {
            method: 'POST',
            body: formData
        });
        const j = await res.json();
        if (j.success) {
            alert('Theme uploaded and applied successfully!');
            location.reload();
        } else {
            alert(j.message || 'Upload failed');
        }
    } catch (e) {
        alert('Error uploading theme');
    }
});
</script>

<style>
.theme-card {
    transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
    border: 1px solid var(--border-color);
}
.theme-card:hover {
    background-color: var(--light-color);
    transform: translateY(-4px);
    box-shadow: 0 10px 20px rgba(0,0,0,0.05);
    border-color: var(--primary-color);
}
.theme-preview-circle {
    width: 48px;
    height: 48px;
    border-radius: 14px;
    display: flex;
    align-items: center;
    justify-content: center;
}
.extra-small {
    font-size: 0.65rem;
    letter-spacing: 0.05em;
}
</style>

<?php require_once dirname(dirname(__DIR__)) . '/templates/layout/footer.php'; ?>
