<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

$DB_HOST = "sql209.infinityfree.com";
$DB_NAME = "if0_40177665_nuevaprueva"; // 🔄 Puedes cambiar a nuevaprueva2 si deseas
$DB_USER = "if0_40177665";
$DB_PASS = "E67zcU4NzLJq/";

try {
    $dsn = "mysql:host={$DB_HOST};dbname={$DB_NAME};charset=utf8mb4";
    $db = new PDO($dsn, $DB_USER, $DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
    ]);
    error_log("✅ [CONFIG] Conexión DB establecida correctamente a {$DB_NAME}");
} catch (PDOException $e) {
    error_log("❌ [CONFIG] Error de conexión DB: " . $e->getMessage());
    die("❌ Error de conexión a la base de datos: " . $e->getMessage());
}
?>
