<?php
if (function_exists('ob_start')) {
    if (PHP_SAPI !== 'cli') {
        ob_start('ob_gzhandler');
    } else {
        ob_start();
    }
}

require __DIR__ . '/config.php';
require __DIR__ . '/helpers/tenant.php';
require __DIR__ . '/helpers/log.php';
require __DIR__ . '/helpers/storage.php';
require __DIR__ . '/helpers/csrf.php';
require __DIR__ . '/helpers/logger.php';

session_start();
header('Content-Type: application/json');
error_log('✅ [API] api.php iniciado');

function json_exit($payload)
{
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

function respond(array $payload = [], int $status = 200): void
{
    if (isset($GLOBALS['csrf_token_response'])) {
        $payload['_csrf'] = $GLOBALS['csrf_token_response'];
    }
    http_response_code($status);
    json_exit($payload);
}

function respond_error(string $publicMessage, int $status = 400, string $logMessage = '', array $context = []): void
{
    if ($logMessage !== '') {
        log_event('ERROR', $logMessage, $context);
    }
    http_response_code($status);
    $payload = ['error' => $publicMessage];
    if (isset($GLOBALS['csrf_token_response'])) {
        $payload['_csrf'] = $GLOBALS['csrf_token_response'];
    }
    json_exit($payload);
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

function sanitize_text(string $value, int $maxLength = 255): string
{
    $value = trim($value);
    $value = preg_replace('/[\x00-\x1F\x7F]/u', '', $value);
    if ($value === null) {
        $value = '';
    }
    if (mb_strlen($value) > $maxLength) {
        $value = mb_substr($value, 0, $maxLength);
    }
    return $value;
}

function sanitize_date_value(string $value): string
{
    $value = trim($value);
    $date = DateTime::createFromFormat('Y-m-d', $value);
    return $date && $date->format('Y-m-d') === $value ? $value : '';
}

function sanitize_codes_input(string $codesRaw): array
{
    $lines = preg_split('/\r?\n/', $codesRaw);
    if (!$lines) {
        return [];
    }
    $codes = [];
    foreach ($lines as $line) {
        $code = strtoupper(trim($line));
        if ($code !== '' && preg_match('/^[A-Z0-9\-_.]+$/', $code)) {
            $codes[] = $code;
        }
    }
    return array_values(array_unique($codes));
}

function rate_limit_bucket(string $cliente): string
{
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    return hash('sha256', $cliente . '|' . $ip);
}

function login_rate_limiter(string $cliente, int $maxAttempts = 5, int $windowSeconds = 300): void
{
    $bucket = rate_limit_bucket($cliente);
    if (!isset($_SESSION['login_attempts'])) {
        $_SESSION['login_attempts'] = [];
    }
    $now = time();
    $attempts = array_filter($_SESSION['login_attempts'][$bucket] ?? [], static fn($ts) => ($now - $ts) < $windowSeconds);
    if (count($attempts) >= $maxAttempts) {
        respond_error('Demasiados intentos. Inténtelo más tarde.', 429, 'Rate limit excedido en login', ['cliente' => $cliente, 'bucket' => $bucket]);
    }
    $attempts[] = $now;
    $_SESSION['login_attempts'][$bucket] = $attempts;
}

function login_rate_reset(string $cliente): void
{
    $bucket = rate_limit_bucket($cliente);
    unset($_SESSION['login_attempts'][$bucket]);
}

function generate_uuid_filename(string $originalName): string
{
    $uuid = bin2hex(random_bytes(16));
    $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
    return $extension ? $uuid . '.' . $extension : $uuid;
}

function client_usage_dir(): string
{
    $base = __DIR__ . '/storage/quotas';
    if (!is_dir($base) && !mkdir($base, 0775, true) && !is_dir($base)) {
        throw new RuntimeException('No se pudo crear el directorio de cuotas');
    }
    return $base;
}

function quota_cache_path(string $cliente): string
{
    return client_usage_dir() . '/' . $cliente . '.json';
}

function calculate_client_usage(string $cliente, string $uploadsDir): int
{
    $usage = 0;
    if (!is_dir($uploadsDir)) {
        return 0;
    }
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($uploadsDir, FilesystemIterator::SKIP_DOTS));
    foreach ($iterator as $file) {
        if ($file->isFile()) {
            $usage += (int) $file->getSize();
        }
    }
    file_put_contents(quota_cache_path($cliente), json_encode(['usage' => $usage, 'updated_at' => time()]));
    return $usage;
}

function get_client_usage(string $cliente, string $uploadsDir): int
{
    $path = quota_cache_path($cliente);
    if (!is_file($path)) {
        return calculate_client_usage($cliente, $uploadsDir);
    }
    $data = json_decode(file_get_contents($path), true);
    if (!is_array($data) || !isset($data['usage'])) {
        return calculate_client_usage($cliente, $uploadsDir);
    }
    return (int) $data['usage'];
}

function update_client_usage(string $cliente, string $uploadsDir): void
{
    calculate_client_usage($cliente, $uploadsDir);
}

function ensure_quota(string $cliente, string $uploadsDir, int $newSize, int $oldSize = 0): void
{
    global $config;
    $quota = $config['uploads']['client_quota_bytes'] ?? (200 * 1024 * 1024);
    if ($quota <= 0) {
        return;
    }
    $current = get_client_usage($cliente, $uploadsDir);
    $projected = $current - $oldSize + $newSize;
    if ($projected > $quota) {
        respond_error('Se alcanzó la cuota de almacenamiento del cliente.', 413, 'Cuota excedida', [
            'cliente' => $cliente,
            'uso_actual' => $current,
            'nuevo' => $newSize,
            'cuota' => $quota,
        ]);
    }
}

function ensure_csrf_token(): void
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        $GLOBALS['csrf_token_response'] = csrf_get_token();
        return;
    }
    $token = $_POST['_csrf'] ?? '';
    if (!csrf_validate_token(is_string($token) ? $token : '')) {
        respond_error('Token CSRF inválido.', 400, 'Fallo CSRF', ['token' => $token]);
    }
    $GLOBALS['csrf_token_response'] = csrf_regenerate_token();
}

function suggest_cache_path(string $cliente, string $term): string
{
    $hash = hash('sha256', $cliente . '|' . $term);
    $dir = __DIR__ . '/storage/cache';
    if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
        throw new RuntimeException('No se pudo crear el directorio de caché');
    }
    return $dir . '/suggest_' . $hash . '.json';
}

function suggest_cache_get(string $cliente, string $term): ?array
{
    $path = suggest_cache_path($cliente, $term);
    if (!is_file($path)) {
        return null;
    }
    $data = json_decode(file_get_contents($path), true);
    if (!is_array($data) || !isset($data['expires']) || $data['expires'] < time()) {
        @unlink($path);
        return null;
    }
    return $data['results'] ?? null;
}

function suggest_cache_put(string $cliente, string $term, array $results, int $ttl = 300): void
{
    $payload = [
        'results' => $results,
        'expires' => time() + $ttl,
    ];
    file_put_contents(suggest_cache_path($cliente, $term), json_encode($payload));
}

function validate_uploaded_file(array $file, array $config): void
{
    if (($file['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK || empty($file['tmp_name'])) {
        throw new RuntimeException('Archivo no recibido');
    }

    $tmpPath = $file['tmp_name'];
    if (!is_uploaded_file($tmpPath)) {
        throw new RuntimeException('Carga de archivo inválida');
    }

    $size = isset($file['size']) ? (int) $file['size'] : filesize($tmpPath);
    if ($size === false) {
        throw new RuntimeException('No se pudo determinar el tamaño del archivo');
    }

    $maxBytes = $config['uploads']['max_bytes'] ?? 0;
    if ($maxBytes > 0 && $size > $maxBytes) {
        throw new RuntimeException('El archivo supera el tamaño máximo permitido');
    }

    $allowedExtensions = $config['uploads']['allowed_extensions'] ?? [];
    if ($allowedExtensions) {
        $extension = strtolower(pathinfo($file['name'] ?? '', PATHINFO_EXTENSION));
        if ($extension === '' || !in_array($extension, $allowedExtensions, true)) {
            throw new RuntimeException('Extensión de archivo no permitida');
        }
    }

    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime = $finfo ? finfo_file($finfo, $tmpPath) : null;
    if ($finfo) {
        finfo_close($finfo);
    }
    if (!$mime && function_exists('mime_content_type')) {
        $mime = mime_content_type($tmpPath);
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

$publicActions = ['authenticate', 'login', 'session'];
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !in_array($action, ['authenticate', 'login'], true)) {
    ensure_csrf_token();
}

if (!in_array($action, $publicActions, true)) {
    ensure_client_authenticated($cliente);
}

try {
    switch ($action) {
        case 'session':
            $authenticated = !empty($_SESSION['client_auth'][$cliente]);
            $GLOBALS['csrf_token_response'] = csrf_get_token();
            respond([
                'authenticated' => $authenticated,
                'csrf_token' => $GLOBALS['csrf_token_response'],
                'cliente' => $cliente,
            ]);
            break;

        case 'authenticate':
        case 'login':
            login_rate_limiter($cliente);
            $accessKey = sanitize_text($_POST['access_key'] ?? '', 255);
            if ($accessKey === '') {
                respond_error('Clave requerida.', 400, 'Clave de acceso vacía', ['cliente' => $cliente]);
            }
            $expected = $clientAdminConfig['access_key'] ?? '';
            if ($expected === '') {
                respond_error('Acceso no configurado.', 500, 'Clave de acceso no configurada', ['cliente' => $cliente]);
            }
            if (!verify_secret($accessKey, $expected)) {
                log_event('WARNING', 'Clave inválida para cliente', ['cliente' => $cliente]);
                respond_error('Credenciales inválidas.', 403, 'Clave inválida', ['cliente' => $cliente]);
            }
            session_regenerate_id(true);
            mark_client_authenticated($cliente);
            login_rate_reset($cliente);
            audit_log('Acceso concedido para cliente ' . $cliente);
            $GLOBALS['csrf_token_response'] = csrf_get_token();
            respond(['ok' => true, 'csrf_token' => $GLOBALS['csrf_token_response']]);
            break;

        case 'suggest':
            $term = sanitize_text($_GET['term'] ?? '', 100);
            if ($term === '') {
                respond([]);
            }
            $cached = suggest_cache_get($cliente, $term);
            if ($cached !== null) {
                respond($cached);
            }
            $stmt = $db->prepare("SELECT DISTINCT code FROM {$tabla_codes_sql} WHERE code LIKE ? ORDER BY code ASC LIMIT 10");
            $stmt->execute([$term . '%']);
            $results = $stmt->fetchAll(PDO::FETCH_COLUMN);
            $results = array_values(array_filter(array_map('strtoupper', $results)));
            suggest_cache_put($cliente, $term, $results);
            respond($results);
            break;

        case 'upload':
            $name = sanitize_text($_POST['name'] ?? '', 255);
            $date = sanitize_date_value($_POST['date'] ?? '');
            $codes = sanitize_codes_input($_POST['codes'] ?? '');
            if ($name === '' || $date === '') {
                respond_error('Datos inválidos.', 422, 'Nombre o fecha inválida', ['cliente' => $cliente]);
            }
            if (empty($_FILES['file']['tmp_name'])) {
                respond_error('Archivo requerido.', 400, 'Archivo no enviado', ['cliente' => $cliente]);
            }
            validate_uploaded_file($_FILES['file'], $config);
            $uploadsDir = ensure_upload_dir($cliente);
            $newSize = (int) ($_FILES['file']['size'] ?? filesize($_FILES['file']['tmp_name']));
            ensure_quota($cliente, $uploadsDir, $newSize);
            $filename = generate_uuid_filename($_FILES['file']['name'] ?? 'documento.pdf');
            $target = $uploadsDir . '/' . $filename;

            $db->beginTransaction();
            try {
                if (!move_uploaded_file($_FILES['file']['tmp_name'], $target)) {
                    throw new RuntimeException('No se pudo mover el archivo');
                }
                $path = $cliente . '/' . $filename;
                $stmt = $db->prepare("INSERT INTO {$tabla_docs_sql} (name,date,path) VALUES (?,?,?)");
                $stmt->execute([$name, $date, $path]);
                $docId = (int) $db->lastInsertId();

                if ($codes) {
                    $ins = $db->prepare("INSERT INTO {$tabla_codes_sql} (document_id,code) VALUES (?,?)");
                    foreach ($codes as $c) {
                        $ins->execute([$docId, $c]);
                    }
                }
                $db->commit();
                update_client_usage($cliente, $uploadsDir);
                audit_log('Documento creado para cliente ' . $cliente . ' (ID ' . $docId . ')');
                respond(['message' => 'Documento guardado']);
            } catch (Throwable $e) {
                $db->rollBack();
                @unlink($target);
                throw $e;
            }
            break;

        case 'list':
            $page = max(1, (int) ($_GET['page'] ?? 1));
            $perPage = (int) ($_GET['per_page'] ?? $listPerPageMax);
            $perPage = max(1, min($listPerPageMax, $perPage));
            $total = (int) $db->query("SELECT COUNT(*) FROM {$tabla_docs_sql}")->fetchColumn();
            $offset = ($page - 1) * $perPage;
            $lastPage = max(1, (int) ceil($total / $perPage));

            $stmt = $db->prepare("SELECT d.id,d.name,d.date,d.path, GROUP_CONCAT(c.code SEPARATOR '\n') AS codes FROM {$tabla_docs_sql} d LEFT JOIN {$tabla_codes_sql} c ON d.id=c.document_id GROUP BY d.id ORDER BY d.date DESC LIMIT :l OFFSET :o");
            $stmt->bindValue(':l', $perPage, PDO::PARAM_INT);
            $stmt->bindValue(':o', $offset, PDO::PARAM_INT);
            $stmt->execute();
            $rows = $stmt->fetchAll();

            $docs = array_map(static function ($r) {
                return [
                    'id' => (int) $r['id'],
                    'name' => $r['name'],
                    'date' => $r['date'],
                    'path' => $r['path'],
                    'codes' => $r['codes'] ? explode("\n", $r['codes']) : [],
                ];
            }, $rows);

            respond([
                'total' => $total,
                'page' => $page,
                'per_page' => $perPage,
                'last_page' => $lastPage,
                'data' => $docs,
            ]);
            break;

        case 'search':
            $codes = sanitize_codes_input($_POST['codes'] ?? '');
            if (empty($codes)) {
                respond([]);
            }
            if (count($codes) > $searchMaxCodes) {
                respond_error('Demasiados códigos.', 422, 'Máximo de códigos excedido', ['cliente' => $cliente]);
            }
            $cond = implode(' OR ', array_fill(0, count($codes), 'UPPER(c.code) = ?'));
            $stmt = $db->prepare("SELECT d.id,d.name,d.date,d.path,c.code FROM {$tabla_docs_sql} d JOIN {$tabla_codes_sql} c ON d.id=c.document_id WHERE {$cond}");
            $stmt->execute($codes);
            $rows = $stmt->fetchAll();

            $docs = [];
            foreach ($rows as $r) {
                $id = (int) $r['id'];
                if (!isset($docs[$id])) {
                    $docs[$id] = [
                        'id' => $id,
                        'name' => $r['name'],
                        'date' => $r['date'],
                        'path' => $r['path'],
                        'codes' => [],
                    ];
                }
                $code = strtoupper($r['code']);
                if (!in_array($code, $docs[$id]['codes'], true)) {
                    $docs[$id]['codes'][] = $code;
                }
            }

            $remaining = $codes;
            $selected = [];
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

            respond(array_values($selected));
            break;

        case 'download_pdfs':
            $uploadsDir = ensure_upload_dir($cliente);
            if (!class_exists('ZipArchive')) {
                respond_error('Función no disponible.', 500, 'Extensión ZipArchive no disponible');
            }

            header('Content-Type: application/zip');
            header('Content-Disposition: attachment; filename="uploads_' . date('Ymd_His') . '.zip"');

            $tmpFile = tempnam(sys_get_temp_dir(), 'zip');
            $zip = new ZipArchive();
            if ($zip->open($tmpFile, ZipArchive::CREATE) !== true) {
                respond_error('No se pudo preparar la descarga.', 500, 'Error creando zip');
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
            $id = (int) ($_POST['id'] ?? 0);
            $name = sanitize_text($_POST['name'] ?? '', 255);
            $date = sanitize_date_value($_POST['date'] ?? '');
            $codes = sanitize_codes_input($_POST['codes'] ?? '');
            if (!$id || $name === '' || $date === '') {
                respond_error('Datos inválidos.', 422, 'Datos inválidos en edición', ['cliente' => $cliente, 'id' => $id]);
            }

            $db->beginTransaction();
            try {
                $oldStmt = $db->prepare("SELECT path FROM {$tabla_docs_sql} WHERE id=?");
                $oldStmt->execute([$id]);
                $oldPath = $oldStmt->fetchColumn();
                if (!$oldPath) {
                    throw new RuntimeException('Documento no encontrado');
                }
                $newPath = $oldPath;
                $uploadsDir = ensure_upload_dir($cliente);
                $oldFullPath = null;
                $oldSize = 0;
                if ($oldPath) {
                    [$oldFullPath,] = resolve_upload_path($cliente, $oldPath);
                    if ($oldFullPath && is_file($oldFullPath)) {
                        $oldSize = (int) filesize($oldFullPath);
                    }
                }

                if (!empty($_FILES['file']['tmp_name'])) {
                    validate_uploaded_file($_FILES['file'], $config);
                    $newSize = (int) ($_FILES['file']['size'] ?? filesize($_FILES['file']['tmp_name']));
                    ensure_quota($cliente, $uploadsDir, $newSize, $oldSize);
                    $newName = generate_uuid_filename($_FILES['file']['name']);
                    $target = $uploadsDir . '/' . $newName;
                    if (!move_uploaded_file($_FILES['file']['tmp_name'], $target)) {
                        throw new RuntimeException('No se pudo actualizar el archivo');
                    }
                    $newPath = $cliente . '/' . $newName;
                    if ($oldFullPath && is_file($oldFullPath)) {
                        @unlink($oldFullPath);
                    }
                    $db->prepare("UPDATE {$tabla_docs_sql} SET name=?,date=?,path=? WHERE id=?")->execute([$name, $date, $newPath, $id]);
                } else {
                    $db->prepare("UPDATE {$tabla_docs_sql} SET name=?,date=? WHERE id=?")->execute([$name, $date, $id]);
                }

                $db->prepare("DELETE FROM {$tabla_codes_sql} WHERE document_id=?")->execute([$id]);
                if ($codes) {
                    $ins = $db->prepare("INSERT INTO {$tabla_codes_sql} (document_id,code) VALUES (?,?)");
                    foreach ($codes as $c) {
                        $ins->execute([$id, $c]);
                    }
                }
                $db->commit();
                update_client_usage($cliente, $uploadsDir);
                audit_log('Documento actualizado para cliente ' . $cliente . ' (ID ' . $id . ')');
                respond(['message' => 'Documento actualizado']);
            } catch (Throwable $e) {
                $db->rollBack();
                throw $e;
            }
            break;

        case 'delete':
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                respond_error('Método no permitido.', 405);
            }
            $id = (int) ($_POST['id'] ?? 0);
            if (!$id) {
                respond_error('ID inválido.', 422, 'ID inválido en eliminación', ['cliente' => $cliente]);
            }
            $providedKey = sanitize_text($_POST['deletion_key'] ?? '', 255);
            if ($providedKey === '') {
                respond_error('Clave de eliminación requerida.', 400, 'Clave de eliminación vacía', ['cliente' => $cliente]);
            }
            $expectedDeletion = $clientAdminConfig['deletion_key'] ?? '';
            if ($expectedDeletion === '') {
                respond_error('Clave de eliminación no configurada.', 500, 'Clave de eliminación no configurada', ['cliente' => $cliente]);
            }
            if (!verify_secret($providedKey, $expectedDeletion)) {
                respond_error('Clave de eliminación inválida.', 403, 'Clave de eliminación incorrecta', ['cliente' => $cliente]);
            }
            if (($_POST['confirm'] ?? '') !== 'yes') {
                respond_error('Confirmación requerida.', 400, 'Confirmación no recibida', ['cliente' => $cliente]);
            }

            $db->beginTransaction();
            try {
                $old = $db->prepare("SELECT path FROM {$tabla_docs_sql} WHERE id=?");
                $old->execute([$id]);
                $oldPath = $old->fetchColumn();
                if (!$oldPath) {
                    throw new RuntimeException('Documento no encontrado');
                }
                [$fullOld,] = resolve_upload_path($cliente, $oldPath);
                if ($fullOld && is_file($fullOld)) {
                    @unlink($fullOld);
                }
                $db->prepare("DELETE FROM {$tabla_codes_sql} WHERE document_id=?")->execute([$id]);
                $db->prepare("DELETE FROM {$tabla_docs_sql} WHERE id=?")->execute([$id]);
                $db->commit();
                update_client_usage($cliente, ensure_upload_dir($cliente));
                audit_log('Documento eliminado para cliente ' . $cliente . ' (ID ' . $id . ')');
                respond(['message' => 'Documento eliminado']);
            } catch (Throwable $e) {
                $db->rollBack();
                throw $e;
            }
            break;

        case 'search_by_code':
            $code = sanitize_text($_POST['code'] ?? ($_GET['code'] ?? ''), 100);
            if ($code === '') {
                respond([]);
            }
            $stmt = $db->prepare("SELECT d.id, d.name, d.date, d.path, GROUP_CONCAT(c2.code SEPARATOR '\n') AS codes FROM {$tabla_docs_sql} d JOIN {$tabla_codes_sql} c1 ON d.id = c1.document_id LEFT JOIN {$tabla_codes_sql} c2 ON d.id = c2.document_id WHERE UPPER(c1.code) = ? GROUP BY d.id");
            $stmt->execute([strtoupper($code)]);
            $rows = $stmt->fetchAll();

            $docs = array_map(static function ($r) {
                return [
                    'id' => (int) $r['id'],
                    'name' => $r['name'],
                    'date' => $r['date'],
                    'path' => $r['path'],
                    'codes' => $r['codes'] ? explode("\n", $r['codes']) : [],
                ];
            }, $rows);

            respond($docs);
            break;

        default:
            respond_error('Acción inválida.', 400, 'Acción no soportada', ['action' => $action]);
    }
} catch (PDOException $e) {
    log_event('ERROR', 'Error de base de datos', ['cliente' => $cliente, 'error' => $e->getMessage()]);
    respond_error('Error interno.', 500);
} catch (Throwable $e) {
    log_event('ERROR', 'Error en API', ['cliente' => $cliente, 'error' => $e->getMessage()]);
    respond_error('No se pudo completar la solicitud.', 500);
}
