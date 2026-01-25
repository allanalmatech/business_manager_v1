<?php
// login.php
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/audit.php';

$BASE_URL = $GLOBALS['BASE_URL'] ?? '';

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf = $_POST['csrf'] ?? '';
    if (!hash_equals($_SESSION['csrf'] ?? '', $csrf)) {
        $error = "Invalid session. Refresh and try again.";
    } else {
        $username = trim((string)($_POST['username'] ?? ''));
        $password = (string)($_POST['password'] ?? '');

        $db = $GLOBALS['db'];

        $stmt = $db->prepare("
          SELECT u.id, u.role_id, r.name AS role, u.username, u.full_name, u.password_hash, u.is_active
          FROM users u
          JOIN roles r ON r.id = u.role_id
          WHERE u.username = ? OR u.email = ?
          LIMIT 1
        ");
        $stmt->bind_param("ss", $username, $username);
        $stmt->execute();
        $user = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$user || (int)$user['is_active'] !== 1 || !password_verify($password, $user['password_hash'])) {
            $error = "Invalid credentials.";
            audit_log('auth.login_failed', 'user', $username, 'Invalid credentials');
        } else {
            session_regenerate_id(true);

            $_SESSION['user'] = [
                'id'      => (int)$user['id'],
                'role_id' => (int)$user['role_id'],
                'role'    => (string)$user['role'],
                'username'=> (string)$user['username'],
                'name'    => (string)$user['full_name'],
            ];

            // update last login
            $stmt = $db->prepare("UPDATE users SET last_login_at = NOW() WHERE id = ?");
            $uid = (int)$user['id'];
            $stmt->bind_param("i", $uid);
            $stmt->execute();
            $stmt->close();

            audit_log('auth.login_success', 'user', (string)$user['id'], 'Login successful');

            header("Location: {$BASE_URL}/index.php");
            exit;
        }
    }
}
?>
<!doctype html>
<html>
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Login</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">
  <div class="container py-5">
    <div class="row justify-content-center">
      <div class="col-12 col-md-6 col-lg-4">
        <div class="card shadow-sm">
          <div class="card-body">
            <h5 class="mb-3">Sign in</h5>

            <?php if ($error): ?>
              <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
            <?php endif; ?>

            <form method="post">
              <input type="hidden" name="csrf" value="<?= htmlspecialchars($_SESSION['csrf'] ?? '') ?>">
              <div class="mb-2">
                <label class="form-label">Username or Email</label>
                <input class="form-control" name="username" required>
              </div>
              <div class="mb-3">
                <label class="form-label">Password</label>
                <input class="form-control" name="password" type="password" required>
              </div>
              <button class="btn btn-primary w-100">Login</button>
              <div class="mt-3 text-center">
                <a href="<?= htmlspecialchars($BASE_URL) ?>/forgot_password.php">Forgot password?</a>
              </div>
            </form>

          </div>
        </div>
      </div>
    </div>
  </div>
</body>
</html>
