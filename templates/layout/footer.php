<?php
$BASE_URL = $GLOBALS['BASE_URL'] ?? '';
?>
  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
  
  <script src="<?= htmlspecialchars($BASE_URL) ?>/assets/js/app.js"></script>
  <link href="https://cdn.jsdelivr.net/npm/cropperjs@1.6.1/dist/cropper.min.css" rel="stylesheet">
  <script src="<?= htmlspecialchars($BASE_URL) ?>/assets/js/sidebar.js"></script>
  <script src="https://cdn.jsdelivr.net/npm/cropperjs@1.6.1/dist/cropper.min.js"></script>

  <?php if (!empty($extra_js)) : ?>
    <?php foreach ((array)$extra_js as $js): ?>
      <script src="<?= htmlspecialchars($BASE_URL . '/' . ltrim($js, '/')) ?>"></script>
    <?php endforeach; ?>
  <?php endif; ?>
</body>
</html>
