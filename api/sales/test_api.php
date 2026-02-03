<?php
// Simple test file to debug the API
header('Content-Type: application/json');

// Enable error reporting
error_reporting(E_ALL);
ini_set('display_errors', 1);

echo json_encode([
  'success' => true,
  'message' => 'API test working',
  'timestamp' => date('Y-m-d H:i:s'),
  'get_data' => $_GET,
  'server_info' => [
    'php_version' => PHP_VERSION,
    'server_software' => $_SERVER['SERVER_SOFTWARE'] ?? 'unknown'
  ]
]);
?>
