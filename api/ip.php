<?php
// api/ip.php
header('Content-Type: application/json');
$ip = $_SERVER['SERVER_ADDR'] ?? '127.0.0.1';
echo json_encode(['ip' => $ip]);
?>
