<?php
// includes/rbac.php
declare(strict_types=1);

function user_has_permission(string $permKey): bool
{
    if (empty($_SESSION['user'])) return false;

    // super_admin shortcut (optional; still supported by role perms anyway)
    if (($_SESSION['user']['role'] ?? '') === 'super_admin') return true;

    $db = $GLOBALS['db'];
    if (!$db instanceof mysqli) return false;

    $uid = (int)$_SESSION['user']['id'];

    // 1) user override deny/grant
    $stmt = $db->prepare("
      SELECT up.is_allowed
      FROM user_permissions up
      JOIN permissions p ON p.id = up.permission_id
      WHERE up.user_id = ? AND p.perm_key = ?
      LIMIT 1
    ");
    $stmt->bind_param("is", $uid, $permKey);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if ($row !== null) return (int)$row['is_allowed'] === 1;

    // 2) role permission
    $roleId = (int)($_SESSION['user']['role_id'] ?? 0);

    $stmt = $db->prepare("
      SELECT 1
      FROM role_permissions rp
      JOIN permissions p ON p.id = rp.permission_id
      WHERE rp.role_id = ? AND p.perm_key = ?
      LIMIT 1
    ");
    $stmt->bind_param("is", $roleId, $permKey);
    $stmt->execute();
    $ok = (bool)$stmt->get_result()->fetch_row();
    $stmt->close();

    return $ok;
}

function require_permission(string $permKey): void
{
    require_login();
    if (!user_has_permission($permKey)) {
        http_response_code(403);
        echo "Forbidden";
        exit;
    }
}
