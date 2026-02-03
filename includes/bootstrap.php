<?php
// includes/bootstrap.php
declare(strict_types=1);

require_once __DIR__ . '/../config/db.php';

$dbCfg = require __DIR__ . '/../config/db.php';
$appCfg = require __DIR__ . '/../config/app.php';

// Define app version constant
define('APP_VERSION', $appCfg['app_version']);

$mysqli = new mysqli($dbCfg['host'], $dbCfg['username'], $dbCfg['password'], $dbCfg['database']);
if ($mysqli->connect_error) {
    http_response_code(500);
    die('Database connection failed.');
}
$mysqli->set_charset($dbCfg['charset']);

// Secure session settings
ini_set('session.use_strict_mode', '1');
ini_set('session.cookie_httponly', '1');
ini_set('session.cookie_samesite', 'Lax');
// If HTTPS later, set to 1:
ini_set('session.cookie_secure', isset($_SERVER['HTTPS']) ? '1' : '0');

require_once __DIR__ . '/session_db.php';
$handler = new DbSessionHandler($mysqli, 60 * 60 * 2); // 2h
session_set_save_handler($handler, true);

session_name('BMSESSID');
session_start();

// Simple CSRF token seed (you’ll expand later)
if (empty($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(32));
}

// Make DB accessible
$GLOBALS['db'] = $mysqli;

// Base URL for assets/links (subfolder safe)
$BASE_URL = '';
$docRoot = realpath((string)($_SERVER['DOCUMENT_ROOT'] ?? '')) ?: '';
$projRoot = realpath(__DIR__ . '/..') ?: '';
if ($docRoot !== '' && $projRoot !== '') {
    $docRootN = str_replace('\\', '/', rtrim($docRoot, "\\/"));
    $projRootN = str_replace('\\', '/', rtrim($projRoot, "\\/"));
    if (stripos($projRootN, $docRootN) === 0) {
        $rel = substr($projRootN, strlen($docRootN));
        $rel = '/' . ltrim((string)$rel, '/');
        $BASE_URL = rtrim($rel, '/');
    }
}
if ($BASE_URL === '') {
    $BASE_URL = rtrim(dirname((string)($_SERVER['SCRIPT_NAME'] ?? '')), '/\\');
    if ($BASE_URL === '/' || $BASE_URL === '\\') $BASE_URL = '';
}
$GLOBALS['BASE_URL'] = $BASE_URL;
