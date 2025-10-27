<?php
require __DIR__ . '/config.php';
session_start();

header('Content-Type: application/json');

function json_exit($payload) {
    echo json_encode($payload);
    exit;
}

$cliente = $_GET['c'] ?? ($_SESSION['cliente'] ?? null);
if ($cliente === null) {
    json_exit(['error' => 'Cliente no especificado']);
}

$cliente = preg_replace('/[^a-z0-9_]/i', '', (string)$cliente);
if ($cliente === '') {
    json_exit(['error' => 'Código de cliente inválido']);
}

try {
    $stmt = $db->prepare('SELECT codigo, activo FROM _control_clientes WHERE codigo = ? AND activo = 1 LIMIT 1');
    $stmt->execute([$cliente]);
    $clienteRow = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    json_exit(['error' => 'Error consultando clientes: ' . $e->getMessage()]);
}

if (!$clienteRow) {
    json_exit(['error' => 'Cliente no encontrado o inactivo']);
}

$_SESSION['cliente'] = $clienteRow['codigo'];
$cliente = $clienteRow['codigo'];

$tabla_docs = "{$cliente}_documents";
$tabla_codes = "{$cliente}_codes";
$tabla_docs_sql = "`{$tabla_docs}`";
$tabla_codes_sql = "`{$tabla_codes}`";

function ensure_upload_dir($cliente) {
    $dir = __DIR__ . '/uploads/' . $cliente;
    if (!is_dir($dir)) {
        if (!mkdir($dir, 0777, true) && !is_dir($dir)) {
            throw new RuntimeException('No se pudo crear la carpeta de uploads');
        }
    }
    return $dir;
}

function resolve_upload_path($cliente, $relativePath) {
    $relativePath = ltrim($relativePath, '/');
    if (strpos($relativePath, '/') === false) {
        $candidate = $cliente . '/' . $relativePath;
        $full = __DIR__ . '/uploads/' . $candidate;
        if (is_file($full)) {
            return [$full, $candidate];
        }
        $fullLegacy = __DIR__ . '/uploads/' . $relativePath;
        return [$fullLegacy, $relativePath];
    }
    return [__DIR__ . '/uploads/' . $relativePath, $relativePath];
}

$action = $_REQUEST['action'] ?? '';

try {
    switch ($action) {
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
            if (empty($_FILES['file']['tmp_name'])) {
                json_exit(['error' => 'Archivo no recibido']);
            }
            $uploadsDir = ensure_upload_dir($cliente);
            $filename = time() . '_' . basename($_FILES['file']['name']);
            $target = $uploadsDir . '/' . $filename;
            if (!move_uploaded_file($_FILES['file']['tmp_name'], $target)) {
                json_exit(['error' => 'No se pudo subir el PDF']);
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
            json_exit(['message' => 'Documento guardado']);

        case 'list':
            $page    = max(1, (int)($_GET['page'] ?? 1));
            $perPage = isset($_GET['per_page']) ? (int)$_GET['per_page'] : 50;
            $total   = (int)$db->query("SELECT COUNT(*) FROM {$tabla_docs_sql}")->fetchColumn();

            if ($perPage === 0) {
                $stmt = $db->query("SELECT d.id,d.name,d.date,d.path, GROUP_CONCAT(c.code SEPARATOR '\n') AS codes FROM {$tabla_docs_sql} d LEFT JOIN {$tabla_codes_sql} c ON d.id=c.document_id GROUP BY d.id ORDER BY d.date DESC");
                $rows = $stmt->fetchAll();
                $lastPage = 1;
                $page = 1;
            } else {
                $perPage = max(1, min(50, $perPage));
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
                $fn = time() . '_' . basename($_FILES['file']['name']);
                $target = $uploadsDir . '/' . $fn;
                move_uploaded_file($_FILES['file']['tmp_name'], $target);
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
            json_exit(['message' => 'Documento actualizado']);

        case 'delete':
            $id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
            if (!$id) {
                json_exit(['error' => 'ID inválido']);
            }
            $old = $db->prepare("SELECT path FROM {$tabla_docs_sql} WHERE id=?");
            $old->execute([$id]);
            $oldPath = $old->fetchColumn();
            if ($oldPath) {
                [$fullOld,] = resolve_upload_path($cliente, $oldPath);
                if (is_file($fullOld)) {
                    @unlink($fullOld);
                }
            }
            $db->prepare("DELETE FROM {$tabla_codes_sql} WHERE document_id=?")->execute([$id]);
            $db->prepare("DELETE FROM {$tabla_docs_sql} WHERE id=?")->execute([$id]);
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
} catch (Exception $e) {
    json_exit(['error' => $e->getMessage()]);
}
