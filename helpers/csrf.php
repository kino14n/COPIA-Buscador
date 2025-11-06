<?php

declare(strict_types=1);

function csrf_session_key(): string
{
    return '_csrf_token';
}

function csrf_ensure_session(): void
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }
}

function csrf_get_token(): string
{
    csrf_ensure_session();
    $key = csrf_session_key();
    if (empty($_SESSION[$key]) || !is_string($_SESSION[$key])) {
        $_SESSION[$key] = bin2hex(random_bytes(32));
    }
    return $_SESSION[$key];
}

function csrf_regenerate_token(): string
{
    csrf_ensure_session();
    $key = csrf_session_key();
    $_SESSION[$key] = bin2hex(random_bytes(32));
    return $_SESSION[$key];
}

function csrf_validate_token(?string $token): bool
{
    csrf_ensure_session();
    $stored = $_SESSION[csrf_session_key()] ?? '';
    if (!is_string($token) || $token === '' || !is_string($stored) || $stored === '') {
        return false;
    }
    return hash_equals($stored, $token);
}
