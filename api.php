<?php
require __DIR__ . '/config.php';
require __DIR__ . '/helpers/tenant.php';
require __DIR__ . '/helpers/log.php';
require __DIR__ . '/helpers/storage.php';

session_start();
header('Content-Type: application/json');
error_log('✅ [API] api.php iniciado');
function json_exit($payload) {
    echo json_encode($payload);
    exit;
}

$scriptName = $_SERVER['SCRIPT_NAME'] ?? '';
$clienteRaw = $_GET['c']
    ?? ($_SESSION['cliente']
        ?? (basename(dirname($scriptName)) === 'clientes' ? basename(dirname(__DIR__)) : null));

if ($clienteRaw === null) {
    $scriptDir = trim(dirname($scriptName), '/');
    $parts = $scriptDir !== '' ? explode('/', $scriptDir) : [];
    $count = count($parts);
    if ($count >= 2 && $parts[$count - 2] === 'clientes') {
        $clienteRaw = $parts[$count - 1];
    }
}

$cliente = sanitize_code($clienteRaw);
if ($cliente === '') {
    json_exit(['error' => 'Cliente no especificado o inválido']);
}

if (!ensure_active_client($db, $cliente)) {
    json_exit(['error' => 'Cliente no encontrado o inactivo']);
}

error_log('✅ [API] Cliente validado: ' . $cliente);

$_SESSION['cliente'] = $cliente;

$clientConfig = load_client_config($cliente);
$clientAdminConfig = isset($clientConfig['admin']) && is_array($clientConfig['admin']) ? $clientConfig['admin'] : [];
$searchMaxCodes = $config['limits']['search_max_codes'] ?? 25;
$listPerPageMax = $config['limits']['list_per_page_max'] ?? 100;

$tabla_docs = table_docs($cliente);
$tabla_codes = table_codes($cliente);
$tabla_docs_sql = "`{$tabla_docs}`";
$tabla_codes_sql = "`{$tabla_codes}`";

function ensure_upload_dir($cliente) {
    global $config;
    return ensure_client_upload_dir($config, $cliente);
}

function resolve_upload_path($cliente, $relativePath) {
    global $config;
    return resolve_client_upload_path($config, $cliente, $relativePath);
}

function mark_client_authenticated(string $cliente): void
{
    if (!isset($_SESSION['client_auth'])) {
        $_SESSION['client_auth'] = [];
    }
    $_SESSION['client_auth'][$cliente] = true;
}

function ensure_client_authenticated(string $cliente): void
{
    if (empty($_SESSION['client_auth'][$cliente])) {
        http_response_code(403);
        json_exit(['error' => 'No autenticado']);
    }
}

function validate_uploaded_file(array $file, array $config): void
{
    if (($file['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK || empty($file['tmp_name'])) {
        throw new RuntimeException('Archivo no recibido');
    }

    $maxBytes = $config['uploads']['max_bytes'] ?? 0;
    if ($maxBytes > 0 && ($file['size'] ?? 0) > $maxBytes) {
        throw new RuntimeException('El archivo supera el tamaño máximo permitido');
    }

    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime = $finfo ? finfo_file($finfo, $file['tmp_name']) : null;
    if ($finfo) {
        finfo_close($finfo);
    }
    if (!$mime && function_exists('mime_content_type')) {
        $mime = mime_content_type($file['tmp_name']);
    }

    $allowed = $config['uploads']['allowed_mimes'] ?? [];
    if ($allowed) {
        if (!$mime) {
            throw new RuntimeException('No se pudo validar el tipo de archivo');
        }
        if (!in_array($mime, $allowed, true)) {
            throw new RuntimeException('Tipo de archivo no permitido');
        }
    }
}


$action = $_REQUEST['action'] ?? '';

$publicActions = ['authenticate'];
if (!in_array($action, $publicActions, true)) {
    ensure_client_authenticated($cliente);
}

try {
    switch ($action) {
        case 'authenticate':
            $accessKey = trim($_POST['access_key'] ?? '');
            if ($accessKey === '') {
                json_exit(['error' => 'Clave requerida']);
            }
            $expected = $clientAdminConfig['access_key'] ?? '';
            if ($expected === '') {
                json_exit(['error' => 'Acceso no configurado']);
            }
            if (!verify_secret($accessKey, $expected)) {
                audit_log('Intento fallido de acceso para cliente ' . $cliente);
                http_response_code(403);
                json_exit(['error' => 'Clave inválida']);
            }
            mark_client_authenticated($cliente);
            audit_log('Acceso concedido para cliente ' . $cliente);
            json_exit(['ok' => true]);

        case 'suggest':
            $term = trim($_GET['term'] ?? '');
            if ($term === '') {
                json_exit([]);
            }
            $stmt = $db->prepare("SELECT DISTINCT code FROM {$tabla_codes_sql} WHERE code LIKE ? ORDER BY code ASC LIMIT 10");
            $stmt->execute([$term . '%']);
            json_exit($stmt->fetchAll(PDO::FETCH_COLUMN));

        case 'upload':
            $name  = $_POST['name'] ?? '';
            $date  = $_POST['date'] ?? '';
            $codes = array_filter(array_map('trim', preg_split('/\r?\n/', $_POST['codes'] ?? '')));
            validate_uploaded_file($_FILES['file'] ?? [], $config);
            $uploadsDir = ensure_upload_dir($cliente);
            $filename = time() . '_' . sanitize_uploaded_filename($_FILES['file']['name'] ?? '');
            $target = $uploadsDir . '/' . $filename;
            if (!move_uploaded_file($_FILES['file']['tmp_name'], $target)) {
                throw new RuntimeException('No se pudo subir el archivo');
            }
            $path = $cliente . '/' . $filename;

            $stmt = $db->prepare("INSERT INTO {$tabla_docs_sql} (name,date,path) VALUES (?,?,?)");
            $stmt->execute([$name, $date, $path]);
            $docId = $db->lastInsertId();

            if ($codes) {
                $ins = $db->prepare("INSERT INTO {$tabla_codes_sql} (document_id,code) VALUES (?,?)");
                foreach (array_unique($codes) as $c) {
                    $ins->execute([$docId, $c]);
                }
            }
            audit_log('Documento creado para cliente ' . $cliente . ' (ID ' . $docId . ')');
            json_exit(['message' => 'Documento guardado']);

        case 'list':
            $page    = max(1, (int)($_GET['page'] ?? 1));
            $perPage = isset($_GET['per_page']) ? (int)$_GET['per_page'] : $listPerPageMax;
            $total   = (int)$db->query("SELECT COUNT(*) FROM {$tabla_docs_sql}")->fetchColumn();

            if ($perPage === 0) {
                $stmt = $db->query("SELECT d.id,d.name,d.date,d.path, GROUP_CONCAT(c.code SEPARATOR '\n') AS codes FROM {$tabla_docs_sql} d LEFT JOIN {$tabla_codes_sql} c ON d.id=c.document_id GROUP BY d.id ORDER BY d.date DESC");
                $rows = $stmt->fetchAll();
                $lastPage = 1;
                $page = 1;
            } else {
                $perPage = max(1, min($listPerPageMax, $perPage));
                $offset  = ($page - 1) * $perPage;
                $lastPage = (int)ceil($total / $perPage);

                $stmt = $db->prepare("SELECT d.id,d.name,d.date,d.path, GROUP_CONCAT(c.code SEPARATOR '\n') AS codes FROM {$tabla_docs_sql} d LEFT JOIN {$tabla_codes_sql} c ON d.id=c.document_id GROUP BY d.id ORDER BY d.date DESC LIMIT :l OFFSET :o");
                $stmt->bindValue(':l', $perPage, PDO::PARAM_INT);
                $stmt->bindValue(':o', $offset, PDO::PARAM_INT);
                $stmt->execute();
                $rows = $stmt->fetchAll();
            }

            $docs = array_map(function ($r) {
                return [
                    'id'    => (int)$r['id'],
                    'name'  => $r['name'],
                    'date'  => $r['date'],
                    'path'  => $r['path'],
                    'codes' => $r['codes'] ? explode("\n", $r['codes']) : [],
                ];
            }, $rows);

            json_exit([
                'total'     => $total,
                'page'      => $page,
                'per_page'  => $perPage,
                'last_page' => $lastPage,
                'data'      => $docs,
            ]);

        case 'search':
            $codes = array_filter(array_map('trim', preg_split('/\r?\n/', $_POST['codes'] ?? '')));
            if (empty($codes)) {
                json_exit([]);
            }
            if (count($codes) > $searchMaxCodes) {
                json_exit(['error' => 'Se permiten hasta ' . $searchMaxCodes . ' códigos por búsqueda']);
            }
            $cond = implode(' OR ', array_fill(0, count($codes), 'UPPER(c.code) = UPPER(?)'));
            $stmt = $db->prepare("SELECT d.id,d.name,d.date,d.path,c.code FROM {$tabla_docs_sql} d JOIN {$tabla_codes_sql} c ON d.id=c.document_id WHERE {$cond}");
            $stmt->execute($codes);
            $rows = $stmt->fetchAll();

            $docs = [];
            foreach ($rows as $r) {
                $id = (int)$r['id'];
                if (!isset($docs[$id])) {
                    $docs[$id] = [
                        'id'    => $id,
                        'name'  => $r['name'],
                        'date'  => $r['date'],
                        'path'  => $r['path'],
                        'codes' => [],
                    ];
                }
                if (!in_array($r['code'], $docs[$id]['codes'], true)) {
                    $docs[$id]['codes'][] = $r['code'];
                }
            }

            $remaining = $codes;
            $selected  = [];
            while ($remaining) {
                $best = null;
                $bestCover = [];
                foreach ($docs as $d) {
                    $cover = array_intersect($d['codes'], $remaining);
                    if (!$best || count($cover) > count($bestCover) || (count($cover) === count($bestCover) && $d['date'] > $best['date'])) {
                        $best = $d;
                        $bestCover = $cover;
                    }
                }
                if (!$best || empty($bestCover)) {
                    break;
                }
                $selected[] = $best;
                $remaining = array_diff($remaining, $bestCover);
                unset($docs[$best['id']]);
            }

            json_exit(array_values($selected));

        case 'download_pdfs':
            $uploadsDir = ensure_upload_dir($cliente);
            if (!class_exists('ZipArchive')) {
                json_exit(['error' => 'Extensión ZipArchive no disponible']);
            }

            header('Content-Type: application/zip');
            header('Content-Disposition: attachment; filename="uploads_' . date('Ymd_His') . '.zip"');

            $tmpFile = tempnam(sys_get_temp_dir(), 'zip');
            $zip = new ZipArchive();
            if ($zip->open($tmpFile, ZipArchive::CREATE) !== true) {
                json_exit(['error' => 'No se pudo crear el ZIP']);
            }

            $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($uploadsDir, FilesystemIterator::SKIP_DOTS));
            foreach ($files as $file) {
                if ($file->isDir()) {
                    continue;
                }
                $filePath = $file->getRealPath();
                $relativePath = $cliente . '/' . substr($filePath, strlen($uploadsDir) + 1);
                $zip->addFile($filePath, $relativePath);
            }
            $zip->close();

            audit_log('Descarga masiva de documentos para cliente ' . $cliente);
            readfile($tmpFile);
            unlink($tmpFile);
            exit;

        case 'edit':
            $id   = (int)($_POST['id'] ?? 0);
            $name = $_POST['name'] ?? '';
            $date = $_POST['date'] ?? '';
            $codes = array_filter(array_map('trim', preg_split('/\r?\n/', $_POST['codes'] ?? '')));
            if (!$id || !$name || !$date) {
                json_exit(['error' => 'Faltan campos obligatorios']);
            }

            if (!empty($_FILES['file']['tmp_name'])) {
                validate_uploaded_file($_FILES['file'], $config);
                $old = $db->prepare("SELECT path FROM {$tabla_docs_sql} WHERE id=?");
                $old->execute([$id]);
                $oldPath = $old->fetchColumn();
                if ($oldPath) {
                    [$fullOld,] = resolve_upload_path($cliente, $oldPath);
                    if (is_file($fullOld)) {
                        @unlink($fullOld);
                    }
                }
                $uploadsDir = ensure_upload_dir($cliente);
                $fn = time() . '_' . sanitize_uploaded_filename($_FILES['file']['name']);
                $target = $uploadsDir . '/' . $fn;
                if (!move_uploaded_file($_FILES['file']['tmp_name'], $target)) {
                    throw new RuntimeException('No se pudo actualizar el archivo');
                }
                $path = $cliente . '/' . $fn;
                $db->prepare("UPDATE {$tabla_docs_sql} SET name=?,date=?,path=? WHERE id=?")->execute([$name, $date, $path, $id]);
            } else {
                $db->prepare("UPDATE {$tabla_docs_sql} SET name=?,date=? WHERE id=?")->execute([$name, $date, $id]);
            }

            $db->prepare("DELETE FROM {$tabla_codes_sql} WHERE document_id=?")->execute([$id]);
            if ($codes) {
                $ins = $db->prepare("INSERT INTO {$tabla_codes_sql} (document_id,code) VALUES (?,?)");
                foreach (array_unique($codes) as $c) {
                    $ins->execute([$id, $c]);
                }
            }
            audit_log('Documento actualizado para cliente ' . $cliente . ' (ID ' . $id . ')');
            json_exit(['message' => 'Documento actualizado']);

        case 'delete':
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                http_response_code(405);
                json_exit(['error' => 'Método no permitido']);
            }
            $id = (int)($_POST['id'] ?? 0);
            if (!$id) {
                json_exit(['error' => 'ID inválido']);
            }
            $providedKey = trim($_POST['deletion_key'] ?? '');
            if ($providedKey === '') {
                json_exit(['error' => 'Clave de eliminación requerida']);
            }
            $expectedDeletion = $clientAdminConfig['deletion_key'] ?? '';
            if ($expectedDeletion === '') {
                json_exit(['error' => 'Clave de eliminación no configurada']);
            }
            if (!verify_secret($providedKey, $expectedDeletion)) {
                http_response_code(403);
                json_exit(['error' => 'Clave de eliminación inválida']);
            }
            if (($_POST['confirm'] ?? '') !== 'yes') {
                json_exit(['error' => 'Confirmación requerida']);
            }
            $old = $db->prepare("SELECT path FROM {$tabla_docs_sql} WHERE id=?");
            $old->execute([$id]);
            $oldPath = $old->fetchColumn();
            if (!$oldPath) {
                json_exit(['error' => 'Documento no encontrado']);
            }
            [$fullOld,] = resolve_upload_path($cliente, $oldPath);
            if (is_file($fullOld)) {
                @unlink($fullOld);
            }
            $db->prepare("DELETE FROM {$tabla_codes_sql} WHERE document_id=?")->execute([$id]);
            $db->prepare("DELETE FROM {$tabla_docs_sql} WHERE id=?")->execute([$id]);
            audit_log('Documento eliminado para cliente ' . $cliente . ' (ID ' . $id . ')');
            json_exit(['message' => 'Documento eliminado']);

        case 'search_by_code':
            $code = trim($_POST['code'] ?? $_GET['code'] ?? '');
            if ($code === '') {
                json_exit([]);
            }
            $stmt = $db->prepare("SELECT d.id, d.name, d.date, d.path, GROUP_CONCAT(c2.code SEPARATOR '\n') AS codes FROM {$tabla_docs_sql} d JOIN {$tabla_codes_sql} c1 ON d.id = c1.document_id LEFT JOIN {$tabla_codes_sql} c2 ON d.id = c2.document_id WHERE UPPER(c1.code) = UPPER(?) GROUP BY d.id");
            $stmt->execute([$code]);
            $rows = $stmt->fetchAll();

            $docs = array_map(function ($r) {
                return [
                    'id'    => (int)$r['id'],
                    'name'  => $r['name'],
                    'date'  => $r['date'],
                    'path'  => $r['path'],
                    'codes' => $r['codes'] ? explode("\n", $r['codes']) : [],
                ];
            }, $rows);

            json_exit($docs);

        default:
            json_exit(['error' => 'Acción inválida']);
    }
} catch (PDOException $e) {
    error_log('❌ [API] Error DB: ' . $e->getMessage());
    audit_log('Error de base de datos para cliente ' . $cliente . ': ' . $e->getMessage());
    http_response_code(500);
    json_exit(['error' => 'Ocurrió un error en la base de datos.']);
} catch (Throwable $e) {
    audit_log('Error en API para cliente ' . $cliente . ': ' . $e->getMessage());
    http_response_code(400);
    json_exit(['error' => $e->getMessage()]);
}
