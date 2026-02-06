<?php
// templates/layout/header.php
if (!isset($page_title)) $page_title = 'Business Manager';
$BASE_URL = $GLOBALS['BASE_URL'] ?? '';
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">

  <title><?= htmlspecialchars($page_title) ?></title>

  <!-- Bootstrap -->
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  
  <!-- Bootstrap Icons -->
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css" rel="stylesheet">

  <!-- App CSS -->
  <link rel="stylesheet" href="<?= htmlspecialchars($BASE_URL) ?>/assets/css/main.css">
  <link rel="stylesheet" href="<?= htmlspecialchars($BASE_URL) ?>/assets/css/sidebar.css">
  <link rel="stylesheet" href="<?= htmlspecialchars($BASE_URL) ?>/assets/css/forms.css">
  <link rel="stylesheet" href="<?= htmlspecialchars($BASE_URL) ?>/assets/css/tables.css">
  <link rel="stylesheet" href="<?= htmlspecialchars($BASE_URL) ?>/assets/css/themes/themes_bundle.css">
  
  <?php
    // Theme loader
    $db = $GLOBALS['db'] ?? null;
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
    
    // Load custom theme if it exists
    if (str_starts_with($current_theme, 'custom_')) {
        echo '<link rel="stylesheet" href="' . htmlspecialchars($BASE_URL) . '/assets/css/themes/' . htmlspecialchars($current_theme) . '.css">';
    }
  ?>
  <script>
    document.documentElement.setAttribute('data-theme', '<?= htmlspecialchars($current_theme) ?>');
  </script>

  <?php if (!empty($extra_css)) : ?>
    <?php foreach ((array)$extra_css as $css): ?>
      <link rel="stylesheet" href="<?= htmlspecialchars($BASE_URL . '/' . ltrim($css, '/')) ?>">
    <?php endforeach; ?>
  <?php endif; ?>
</head>
<body>
