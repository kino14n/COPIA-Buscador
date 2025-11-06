<?php
function admin_session_key(array $config): string
{
    return $config['admin']['session_key'] ?? 'superadmin_authenticated';
}

function is_admin_authenticated(array $config): bool
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }
    $key = admin_session_key($config);
    return !empty($_SESSION[$key]);
}

function require_admin_auth(array $config): void
{
    if (is_admin_authenticated($config)) {
        return;
    }
    $redirect = $_SERVER['REQUEST_URI'] ?? '/client-generator.php';
    header('Location: /admin-login.php?redirect=' . urlencode($redirect));
    exit;
}

function authenticate_admin(array $config, string $username, string $password): bool
{
    $expectedUser = $config['admin']['username'] ?? '';
    $expectedHash = $config['admin']['password_hash'] ?? '';
    $expectedPlain = getenv('ADMIN_PASSWORD') ?: null;

    if (!hash_equals($expectedUser, $username)) {
        return false;
    }

    if ($expectedHash !== '' && password_verify($password, $expectedHash)) {
        return true;
    }

    if ($expectedPlain !== null && hash_equals($expectedPlain, $password)) {
        return true;
    }

    return false;
}

function admin_login(array $config): void
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }
    $key = admin_session_key($config);
    $_SESSION[$key] = true;
}

function admin_logout(array $config): void
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }
    $key = admin_session_key($config);
    unset($_SESSION[$key]);
}
