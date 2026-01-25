<?php
// logout.php
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/audit.php';

$BASE_URL = $GLOBALS['BASE_URL'] ?? '';

if (!empty($_SESSION['user']['id'])) {
    audit_log('auth.logout', 'user', (string)$_SESSION['user']['id'], 'User logged out');
}

$_SESSION = [];
if (ini_get("session.use_cookies")) {
    $p = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000, $p["path"], $p["domain"], (bool)$p["secure"], (bool)$p["httponly"]);
}
session_destroy();

header("Location: {$BASE_URL}/login.php");
exit;
