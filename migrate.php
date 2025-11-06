<?php
declare(strict_types=1);

require __DIR__ . '/config.php';

$appliedTable = '_migrations';
$db->exec("CREATE TABLE IF NOT EXISTS {$appliedTable} (id INT AUTO_INCREMENT PRIMARY KEY, migration VARCHAR(255) UNIQUE NOT NULL, applied_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)");

$stmt = $db->query("SELECT migration FROM {$appliedTable}");
$applied = $stmt->fetchAll(PDO::FETCH_COLUMN) ?: [];

$migrations = glob(__DIR__ . '/migrations/[0-9][0-9][0-9]_*.php');
sort($migrations);

foreach ($migrations as $file) {
    $name = basename($file);
    if (in_array($name, $applied, true)) {
        continue;
    }
    $callable = require $file;
    if (!is_callable($callable)) {
        throw new RuntimeException("La migración {$name} no devolvió una función ejecutable");
    }
    echo "Ejecutando {$name}...\n";
    $db->beginTransaction();
    try {
        $callable($db);
        $ins = $db->prepare("INSERT INTO {$appliedTable} (migration) VALUES (?)");
        $ins->execute([$name]);
        $db->commit();
        echo "OK\n";
    } catch (Throwable $e) {
        $db->rollBack();
        throw $e;
    }
}

echo "Migraciones completadas.\n";
