<?php
// includes/auth.php
declare(strict_types=1);

function require_login(): void
{
    if (empty($_SESSION['user'])) {
        header("Location: " . ($GLOBALS['BASE_URL'] ?? '') . "/login.php");
        exit;
    }
}

function current_user(): ?array
{
    return $_SESSION['user'] ?? null;
}

function require_role(string ...$roles): void
{
    require_login();
    $role = $_SESSION['user']['role'] ?? '';
    if (!in_array($role, $roles, true)) {
        http_response_code(403);
        echo "Forbidden";
        exit;
    }
}

function require_admin_login(): void
{
    require_login();
    $role = $_SESSION['user']['role'] ?? '';
    if (!in_array($role, ['admin', 'super_admin'], true)) {
        http_response_code(403);
        echo "Forbidden";
        exit;
    }
}
