<?php
function sanitize_code(?string $code): string
{
    $code = strtolower($code ?? '');
    return preg_replace('/[^a-z0-9_]/', '', $code);
}

function ensure_active_client(PDO $db, string $code): bool
{
    $stmt = $db->prepare('SELECT 1 FROM _control_clientes WHERE codigo = ? AND activo = 1');
    $stmt->execute([$code]);
    return (bool) $stmt->fetchColumn();
}

function table_docs(string $code): string
{
    return "{$code}_documents";
}

function table_codes(string $code): string
{
    return "{$code}_codes";
}

function copy_dir(string $src, string $dst): bool
{
    if (!is_dir($src)) {
        return false;
    }
    if (!is_dir($dst) && !mkdir($dst, 0777, true) && !is_dir($dst)) {
        return false;
    }

    $dir = opendir($src);
    if (!$dir) {
        return false;
    }

    while (false !== ($file = readdir($dir))) {
        if ($file === '.' || $file === '..') {
            continue;
        }
        $from = $src . '/' . $file;
        $to = $dst . '/' . $file;
        if (is_dir($from)) {
            copy_dir($from, $to);
        } else {
            $parent = dirname($to);
            if (!is_dir($parent) && !mkdir($parent, 0777, true) && !is_dir($parent)) {
                closedir($dir);
                return false;
            }
            copy($from, $to);
        }
    }

    closedir($dir);
    return true;
}

function client_config_path(string $code): string
{
    return __DIR__ . '/../clientes/' . $code . '/config.php';
}

function load_client_config(string $code): array
{
    $path = client_config_path($code);
    if (is_file($path)) {
        $config = require $path;
        if (is_array($config)) {
            return $config;
        }
    }

    $template = __DIR__ . '/../clientes/template.config.php';
    if (is_file($template)) {
        $config = require $template;
        if (is_array($config)) {
            return $config;
        }
    }

    return [
        'branding' => [],
        'admin' => [],
        'db' => [],
    ];
}

function verify_secret(string $provided, string $expected): bool
{
    if ($expected === '') {
        return false;
    }

    if (preg_match('/^\$2[aby]\$/', $expected) === 1) {
        return password_verify($provided, $expected);
    }

    return hash_equals($expected, $provided);
}

function uploads_base_path(array $config, string $code): string
{
    $base = rtrim($config['uploads']['base_path'] ?? (__DIR__ . '/../storage/uploads'), '/');
    return $base . '/' . $code;
}
