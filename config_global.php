<?php
$env = static function (string $key, $default = null) {
    $value = getenv($key);
    if ($value === false) {
        return $default;
    }
    return $value;
};

$rootDir = __DIR__;
$storageRoot = rtrim($env('STORAGE_PATH', $rootDir . '/storage'), '/');
$uploadsPath = rtrim($env('UPLOADS_PATH', $storageRoot . '/uploads'), '/');

return [
    'db' => [
        'host' => $env('DB_HOST', 'localhost'),
        'name' => $env('DB_NAME', 'buscador'),
        'user' => $env('DB_USER', 'buscador'),
        'pass' => $env('DB_PASS', ''),
        'charset' => $env('DB_CHARSET', 'utf8mb4'),
    ],
    'admin' => [
        'username' => $env('ADMIN_USERNAME', 'admin'),
        'password_hash' => $env('ADMIN_PASSWORD_HASH', ''),
        'session_key' => $env('ADMIN_SESSION_KEY', 'superadmin_authenticated'),
    ],
    'security' => [
        'pdf_highlighter_url' => $env('PDF_HIGHLIGHTER_URL', ''),
    ],
    'uploads' => [
        'base_path' => $uploadsPath,
        'max_bytes' => (int) $env('UPLOAD_MAX_BYTES', 10 * 1024 * 1024),
        'allowed_mimes' => array_filter(array_map('trim', explode(',', (string) $env('UPLOAD_ALLOWED_MIMES', 'application/pdf,image/png,image/jpeg')))),
        'allowed_extensions' => array_filter(array_map('trim', explode(',', (string) $env('UPLOAD_ALLOWED_EXTENSIONS', 'pdf,png,jpg,jpeg')))),
    ],
    'limits' => [
        'search_max_codes' => max(1, (int) $env('SEARCH_MAX_CODES', 25)),
        'list_per_page_max' => max(1, (int) $env('LIST_PER_PAGE_MAX', 100)),
    ],
    'logging' => [
        'path' => $env('LOG_FILE', $rootDir . '/logs/app.log'),
    ],
];
