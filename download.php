<?php
require __DIR__ . '/config.php';
require __DIR__ . '/helpers/tenant.php';
require __DIR__ . '/helpers/storage.php';
require __DIR__ . '/helpers/log.php';

session_start();

$cliente = sanitize_code($_GET['c'] ?? '');
$id = (int)($_GET['id'] ?? 0);

if ($cliente === '' || !$id) {
    http_response_code(400);
    echo 'Parámetros inválidos';
    exit;
}

if (!ensure_active_client($db, $cliente)) {
    http_response_code(404);
    echo 'Documento no encontrado';
    exit;
}

if (empty($_SESSION['client_auth'][$cliente])) {
    http_response_code(403);
    echo 'No autorizado';
    exit;
}

$tabla_docs = table_docs($cliente);
$tabla_docs_sql = "`{$tabla_docs}`";
$stmt = $db->prepare("SELECT name, path FROM {$tabla_docs_sql} WHERE id = ?");
$stmt->execute([$id]);
$row = $stmt->fetch();

if (!$row) {
    http_response_code(404);
    echo 'Documento no encontrado';
    exit;
}

[$fullPath,] = resolve_client_upload_path($config, $cliente, $row['path']);
if (!is_file($fullPath)) {
    http_response_code(404);
    echo 'Archivo no disponible';
    exit;
}

$mime = 'application/octet-stream';
$finfo = finfo_open(FILEINFO_MIME_TYPE);
if ($finfo) {
    $detected = finfo_file($finfo, $fullPath);
    finfo_close($finfo);
    if ($detected) {
        $mime = $detected;
    }
}

$filename = $row['name'] ?: basename($fullPath);
$safeName = preg_replace('/[^a-zA-Z0-9_\.\-]/', '_', $filename);
header('Content-Type: ' . $mime);
header('Content-Disposition: inline; filename="' . $safeName . '"');
header('Content-Length: ' . filesize($fullPath));

audit_log('Descarga de documento ' . $id . ' para cliente ' . $cliente);
readfile($fullPath);
exit;
