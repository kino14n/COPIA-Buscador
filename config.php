<?php
// Mostrar errores en depuración
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Credenciales InfinityFree
$DB_HOST = "sql209.infinityfree.com";
$DB_NAME = "if0_40177665_nuevaprueva";
$DB_USER = "if0_40177665";
$DB_PASS = "E67zcU4NzLJq/";

// Conexión PDO
try {
    $dsn = "mysql:host={$DB_HOST};dbname={$DB_NAME};charset=utf8mb4;port=3306";
    $db = new PDO($dsn, $DB_USER, $DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
    ]);
    error_log("✅ [CONFIG] Conexión DB establecida correctamente");
} catch (PDOException $e) {
    error_log("❌ [CONFIG] Error de conexión DB: " . $e->getMessage());
    die("Error de conexión a la base de datos: " . $e->getMessage());
}
?>
