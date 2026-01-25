<?php
// includes/audit.php
declare(strict_types=1);

function audit_log(string $action, ?string $entity=null, ?string $entityId=null, ?string $details=null): void
{
    $db = $GLOBALS['db'];
    if (!$db instanceof mysqli) return;

    $userId = $_SESSION['user']['id'] ?? null;
    $ip     = $_SERVER['REMOTE_ADDR'] ?? null;
    $ua     = substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255);

    $stmt = $db->prepare("INSERT INTO audit_logs (user_id, action, entity, entity_id, details, ip_address, user_agent)
                          VALUES (?, ?, ?, ?, ?, ?, ?)");
    $stmt->bind_param("issssss", $userId, $action, $entity, $entityId, $details, $ip, $ua);
    $stmt->execute();
    $stmt->close();
}
