<?php 
// api.php

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// —————————————————————————————
// Cabecera y configuración MySQL
// —————————————————————————————
header('Content-Type: application/json');
$host   = 'sql200.infinityfree.com';
$dbname = 'if0_39064130_buscador';
$user   = 'if0_39064130';
$pass   = 'POQ2ODdvhG';

$dsn = "mysql:host=$host;port=3306;dbname=$dbname;charset=utf8";
try {
    $db = new PDO($dsn, $user, $pass, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
} catch (PDOException $e) {
    echo json_encode(['error' => 'Error de conexión: '.$e->getMessage()]);
    exit;
}

$action = $_REQUEST['action'] ?? '';

switch ($action) {

  // —— AUTOCOMPLETE SUGGEST ——  
  case 'suggest':
    $term = trim($_GET['term'] ?? '');
    if ($term === '') {
      echo json_encode([]);
      exit;
    }
    $stmt = $db->prepare("
      SELECT DISTINCT code 
      FROM codes 
      WHERE code LIKE ? 
      ORDER BY code ASC 
      LIMIT 10
    ");
    $stmt->execute([$term . '%']);
    $codes = $stmt->fetchAll(PDO::FETCH_COLUMN);
    echo json_encode($codes);
    break;

  // —— SUBIR NUEVO DOCUMENTO ——  
  case 'upload':
    $name  = $_POST['name'];
    $date  = $_POST['date'];
    $codes = array_filter(array_map('trim', preg_split('/\r?\n/', $_POST['codes'] ?? '')));
    $file  = $_FILES['file'];
    $filename = time().'_'.basename($file['name']);
    if (!move_uploaded_file($file['tmp_name'], __DIR__.'/uploads/'.$filename)) {
      echo json_encode(['error'=>'No se pudo subir el PDF']);
      exit;
    }
    $db->prepare('INSERT INTO documents (name,date,path) VALUES (?,?,?)')
       ->execute([$name,$date,$filename]);
    $docId = $db->lastInsertId();
    $ins = $db->prepare('INSERT INTO codes (document_id,code) VALUES (?,?)');
    foreach (array_unique($codes) as $c) {
      $ins->execute([$docId,$c]);
    }
    echo json_encode(['message'=>'Documento guardado']);
    break;

  // —— LISTAR CON PAGINACIÓN ——  
  case 'list':
    $page    = max(1,(int)($_GET['page'] ?? 1));
    $perPage = isset($_GET['per_page']) ? (int)$_GET['per_page'] : 50;
    $total   = (int)$db->query("SELECT COUNT(*) FROM documents")->fetchColumn();

    if ($perPage === 0) {
      $stmt = $db->query("
        SELECT d.id,d.name,d.date,d.path,
               GROUP_CONCAT(c.code SEPARATOR '\n') AS codes
        FROM documents d
        LEFT JOIN codes c ON d.id=c.document_id
        GROUP BY d.id
        ORDER BY d.date DESC
      ");
      $rows = $stmt->fetchAll();
      $lastPage = 1;
      $page = 1;
    } else {
      $perPage = max(1, min(50, $perPage));
      $offset  = ($page - 1) * $perPage;
      $lastPage = (int)ceil($total / $perPage);

      $stmt = $db->prepare("
        SELECT d.id,d.name,d.date,d.path,
               GROUP_CONCAT(c.code SEPARATOR '\n') AS codes
        FROM documents d
        LEFT JOIN codes c ON d.id=c.document_id
        GROUP BY d.id
        ORDER BY d.date DESC
        LIMIT :l OFFSET :o
      ");
      $stmt->bindValue(':l',$perPage,PDO::PARAM_INT);
      $stmt->bindValue(':o',$offset ,PDO::PARAM_INT);
      $stmt->execute();
      $rows = $stmt->fetchAll();
    }

    $docs = array_map(function($r){
      return [
        'id'    => (int)$r['id'],
        'name'  => $r['name'],
        'date'  => $r['date'],
        'path'  => $r['path'],
        'codes' => $r['codes'] ? explode("\n",$r['codes']) : []
      ];
    }, $rows);

    echo json_encode([
      'total'     => $total,
      'page'      => $page,
      'per_page'  => $perPage,
      'last_page' => $lastPage,
      'data'      => $docs
    ]);
    break;

  // —— BÚSQUEDA INTELIGENTE VORAZ ——  
  case 'search':
    $codes = array_filter(array_map('trim', preg_split('/\r?\n/', $_POST['codes'] ?? '')));
    if (empty($codes)) {
      echo json_encode([]);
      exit;
    }

   // Usar UPPER para insensibilidad a mayúsculas/minúsculas
  $cond = implode(" OR ", array_fill(0, count($codes), "UPPER(c.code) = UPPER(?)"));
  $stmt = $db->prepare("
    SELECT d.id,d.name,d.date,d.path,c.code
    FROM documents d
    JOIN codes c ON d.id=c.document_id
    WHERE $cond
  ");
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
          'codes' => []
        ];
      }
      if (!in_array($r['code'], $docs[$id]['codes'], true)) {
        $docs[$id]['codes'][] = $r['code'];
      }
    }

    $remaining = $codes;
    $selected  = [];
    while ($remaining) {
      $best      = null;
      $bestCover = [];
      foreach ($docs as $d) {
        $cover = array_intersect($d['codes'], $remaining);
        if (!$best
            || count($cover) > count($bestCover)
            || (count($cover) === count($bestCover) && $d['date'] > $best['date'])
        ) {
          $best      = $d;
          $bestCover = $cover;
        }
      }
      if (!$best || empty($bestCover)) break;
      $selected[] = $best;
      $remaining = array_diff($remaining, $bestCover);
      unset($docs[$best['id']]);
    }

    echo json_encode(array_values($selected));
    break;

  // —— ACCIÓN: DESCARGAR TODOS LOS PDFS EN ZIP ——  
  case 'download_pdfs':
    $uploadsDir = __DIR__ . '/uploads';
    if (!is_dir($uploadsDir)) {
      echo json_encode(['error'=>'Carpeta uploads no encontrada']);
      exit;
    }

    // Cabeceras para descarga ZIP
    header('Content-Type: application/zip');
    header('Content-Disposition: attachment; filename="uploads_'.date('Ymd_His').'.zip"');

    // Crear ZIP en tmp
    $tmpFile = tempnam(sys_get_temp_dir(), 'zip');
    $zip = new ZipArchive();
    if ($zip->open($tmpFile, ZipArchive::CREATE) !== TRUE) {
      echo json_encode(['error'=>'No se pudo crear el ZIP']);
      exit;
    }

    // Agregar recursivamente todos los archivos de uploads
    $files = new RecursiveIteratorIterator(
      new RecursiveDirectoryIterator($uploadsDir),
      RecursiveIteratorIterator::LEAVES_ONLY
    );
    foreach ($files as $file) {
      if (!$file->isDir()) {
        $filePath     = $file->getRealPath();
        $relativePath = substr($filePath, strlen($uploadsDir) + 1);
        $zip->addFile($filePath, $relativePath);
      }
    }
    $zip->close();

    // Enviar contenido
    readfile($tmpFile);
    unlink($tmpFile);
    exit;

  // —— EDITAR DOCUMENTO ——  
  case 'edit':
    $id   = (int)$_POST['id'];
    $name = $_POST['name'];
    $date = $_POST['date'];
    $codes= array_filter(array_map('trim', preg_split('/\r?\n/', $_POST['codes'] ?? '')));
    if (!empty($_FILES['file']['tmp_name'])) {
      $old = $db->prepare('SELECT path FROM documents WHERE id=?');
      $old->execute([$id]);
      @unlink(__DIR__.'/uploads/'.$old->fetchColumn());
      $fn = time().'_'.basename($_FILES['file']['name']);
      move_uploaded_file($_FILES['file']['tmp_name'], __DIR__.'/uploads/'.$fn);
      $db->prepare('UPDATE documents SET name=?,date=?,path=? WHERE id=?')
         ->execute([$name,$date,$fn,$id]);
    } else {
      $db->prepare('UPDATE documents SET name=?,date=? WHERE id=?')
         ->execute([$name,$date,$id]);
    }
    $db->prepare('DELETE FROM codes WHERE document_id=?')->execute([$id]);
    $ins = $db->prepare('INSERT INTO codes (document_id,code) VALUES (?,?)');
    foreach (array_unique($codes) as $c) {
      $ins->execute([$id,$c]);
    }
    echo json_encode(['message'=>'Documento actualizado']);
    break;

    if (!$id || !$name || !$date) {
  echo json_encode(['error' => 'Faltan campos obligatorios']);
  exit;
}

  // —— ELIMINAR DOCUMENTO ——  
  case 'delete':
    $id = (int)($_GET['id'] ?? 0);
    $old = $db->prepare('SELECT path FROM documents WHERE id=?');
    $old->execute([$id]);
    @unlink(__DIR__.'/uploads/'.$old->fetchColumn());
    $db->prepare('DELETE FROM codes WHERE document_id=?')->execute([$id]);
    $db->prepare('DELETE FROM documents WHERE id=?')->execute([$id]);
    echo json_encode(['message'=>'Documento eliminado']);
    break;

// —— BÚSQUEDA POR CÓDIGO ——  
case 'search_by_code':
  $code = trim($_POST['code'] ?? $_GET['code'] ?? '');
  if (!$code) {
    echo json_encode([]);
    exit;
  }

  // Trae todos los códigos asociados al documento donde existe el código buscado (insensible a mayúsculas)
  $stmt = $db->prepare("
    SELECT d.id, d.name, d.date, d.path, GROUP_CONCAT(c2.code SEPARATOR '\n') AS codes
    FROM documents d
    JOIN codes c1 ON d.id = c1.document_id
    LEFT JOIN codes c2 ON d.id = c2.document_id
    WHERE UPPER(c1.code) = UPPER(?)
    GROUP BY d.id
  ");
  $stmt->execute([$code]);
  $rows = $stmt->fetchAll();

  $docs = array_map(function($r){
    return [
      'id'    => (int)$r['id'],
      'name'  => $r['name'],
      'date'  => $r['date'],
      'path'  => $r['path'],
      'codes' => $r['codes'] ? explode("\n", $r['codes']) : []
    ];
  }, $rows);

  echo json_encode($docs);
  break;






  default:
    echo json_encode(['error'=>'Acción inválida']);
    break;
}
