<?php
ini_set('display_errors',1);
ini_set('display_startup_errors',1);
error_reporting(E_ALL);

$DB_HOST = "sql209.infinityfree.com";
$DB_USER = "if0_40177665";
$DB_PASS = "E67zcU4NzLJq/";
$DB_NAME = "if0_40177665_nuevaprueva";

echo "🔍 Intentando conectar con <b>{$DB_NAME}</b> en host <b>{$DB_HOST}</b><br>";

try {
    $dsn = "mysql:host={$DB_HOST};dbname={$DB_NAME};charset=utf8mb4";
    $pdo = new PDO($dsn, $DB_USER, $DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
    ]);
    echo "✅ Conexión exitosa a la base de datos <b>{$DB_NAME}</b>";
} catch (PDOException $e) {
    echo "❌ Error de conexión en {$DB_NAME}: " . $e->getMessage();
}
?>
