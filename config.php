<?php
$runtimeConfig = require __DIR__ . '/config_global.php';

if (!is_array($runtimeConfig)) {
    throw new RuntimeException('La configuración global debe devolver un array');
}

$GLOBALS['config'] = $runtimeConfig;

$logFile = $runtimeConfig['logging']['path'] ?? null;
if ($logFile) {
    $logDir = dirname($logFile);
    if (!is_dir($logDir) && !mkdir($logDir, 0775, true) && !is_dir($logDir)) {
        throw new RuntimeException('No se pudo crear el directorio de logs');
    }
    ini_set('log_errors', '1');
    ini_set('error_log', $logFile);
}

$uploadsBase = $runtimeConfig['uploads']['base_path'] ?? (__DIR__ . '/storage/uploads');
if (!is_dir($uploadsBase) && !mkdir($uploadsBase, 0775, true) && !is_dir($uploadsBase)) {
    throw new RuntimeException('No se pudo crear el directorio base de uploads');
}

$dbConfig = $runtimeConfig['db'];
$driver = $dbConfig['driver'] ?? 'mysql';

switch ($driver) {
    case 'sqlite':
        $dsn = 'sqlite:' . ($dbConfig['path'] ?? $dbConfig['name']);
        $dbUser = null;
        $dbPass = null;
        break;
    default:
        $dsn = sprintf(
            '%s:host=%s;dbname=%s;charset=%s',
            $driver,
            $dbConfig['host'],
            $dbConfig['name'],
            $dbConfig['charset']
        );
        $dbUser = $dbConfig['user'];
        $dbPass = $dbConfig['pass'];
}

try {
    $db = new PDO($dsn, $dbUser, $dbPass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
} catch (PDOException $e) {
    if (!empty($logFile)) {
        error_log('[CONFIG] Error de conexión DB: ' . $e->getMessage());
    }
    throw new RuntimeException('No se pudo conectar a la base de datos');
}

$config = $runtimeConfig;
