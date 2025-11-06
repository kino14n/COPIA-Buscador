<?php
declare(strict_types=1);

require __DIR__ . '/config_global.php';

$requirements = [
    'PHP >= 7.4' => version_compare(PHP_VERSION, '7.4.0', '>='),
    'PDO extension' => extension_loaded('pdo'),
    'PDO MySQL' => extension_loaded('pdo_mysql'),
    'Fileinfo' => extension_loaded('fileinfo'),
    'Zip' => class_exists('ZipArchive'),
];

$missing = array_keys(array_filter($requirements, static fn($ok) => !$ok));
if ($missing) {
    fwrite(STDERR, "Requisitos faltantes:\n" . implode("\n", array_map(static fn($item) => " - {$item}", $missing)) . "\n");
    exit(1);
}

$directories = [
    __DIR__ . '/storage',
    __DIR__ . '/storage/uploads',
    __DIR__ . '/storage/cache',
    __DIR__ . '/storage/quotas',
    __DIR__ . '/logs',
];

foreach ($directories as $dir) {
    if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
        fwrite(STDERR, "No se pudo crear el directorio: {$dir}\n");
        exit(1);
    }
}

echo "Directorios verificados.\n";

$envPath = __DIR__ . '/.env';
if (!file_exists($envPath) && file_exists(__DIR__ . '/.env.example')) {
    copy(__DIR__ . '/.env.example', $envPath);
    echo "Archivo .env creado desde .env.example\n";
}

$keyFile = __DIR__ . '/storage/app.key';
if (!file_exists($keyFile)) {
    $key = bin2hex(random_bytes(32));
    file_put_contents($keyFile, $key);
    echo "Clave de aplicación generada en storage/app.key\n";
}

echo "Instalación completada. Configure las variables en .env y ejecute php migrate.php.\n";
