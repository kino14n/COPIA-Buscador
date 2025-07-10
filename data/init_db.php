<?php
// data/init_db.php
// Inicializa la base SQLite y crea tablas si no existen
$db = new PDO('sqlite:' . __DIR__ . '/documents.db');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$db->exec("CREATE TABLE IF NOT EXISTS documents (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT NOT NULL,
    date TEXT NOT NULL,
    path TEXT NOT NULL
)");
$db->exec("CREATE TABLE IF NOT EXISTS codes (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    document_id INTEGER NOT NULL,
    code TEXT NOT NULL,
    FOREIGN KEY(document_id) REFERENCES documents(id)
)");
echo "Tablas creadas o verificadas correctamente.\n";
?>