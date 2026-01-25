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

  <!-- App CSS -->
  <link rel="stylesheet" href="<?= htmlspecialchars($BASE_URL) ?>/assets/css/main.css">
  <link rel="stylesheet" href="<?= htmlspecialchars($BASE_URL) ?>/assets/css/sidebar.css">
  <link rel="stylesheet" href="<?= htmlspecialchars($BASE_URL) ?>/assets/css/forms.css">
  <link rel="stylesheet" href="<?= htmlspecialchars($BASE_URL) ?>/assets/css/tables.css">

  <?php if (!empty($extra_css)) : ?>
    <?php foreach ((array)$extra_css as $css): ?>
      <link rel="stylesheet" href="<?= htmlspecialchars($BASE_URL . '/' . ltrim($css, '/')) ?>">
    <?php endforeach; ?>
  <?php endif; ?>
</head>
<body>
