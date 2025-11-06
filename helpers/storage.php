<?php
function ensure_client_upload_dir(array $config, string $code): string
{
    $dir = uploads_base_path($config, $code);
    if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
        throw new RuntimeException('No se pudo crear la carpeta de uploads');
    }
    return $dir;
}

function sanitize_storage_relative(string $code, string $relativePath): string
{
    $relativePath = ltrim(str_replace(['\\', '..'], ['/', ''], $relativePath), '/');
    if (strpos($relativePath, $code . '/') === 0) {
        $relativePath = substr($relativePath, strlen($code) + 1);
    }
    return trim($relativePath, '/');
}

function resolve_client_upload_path(array $config, string $code, string $relativePath): array
{
    $relative = sanitize_storage_relative($code, $relativePath);
    $base = uploads_base_path($config, $code);
    $primary = $base . '/' . $relative;
    if (is_file($primary)) {
        return [$primary, $code . '/' . $relative];
    }

    $legacyClient = __DIR__ . '/../uploads/' . $code . '/' . $relative;
    if (is_file($legacyClient)) {
        return [$legacyClient, $code . '/' . $relative];
    }

    $legacy = __DIR__ . '/../uploads/' . ltrim($relativePath, '/');
    return [$legacy, ltrim($relativePath, '/')];
}

function sanitize_uploaded_filename(string $name): string
{
    $name = preg_replace('/[^a-zA-Z0-9_\.\-]/', '_', $name);
    return $name ?: 'documento.pdf';
}
