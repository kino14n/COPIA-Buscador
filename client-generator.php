<?php
require __DIR__ . '/config.php';
session_start();

function copy_recursive(string $source, string $destination): void {
    if (!is_dir($source)) {
        throw new RuntimeException("Directorio de origen no encontrado: {$source}");
    }

    if (!is_dir($destination) && !mkdir($destination, 0777, true) && !is_dir($destination)) {
        throw new RuntimeException("No se pudo crear el directorio destino: {$destination}");
    }

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($source, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );

    foreach ($iterator as $item) {
        $targetPath = $destination . DIRECTORY_SEPARATOR . $iterator->getSubPathName();
        if ($item->isDir()) {
            if (!is_dir($targetPath) && !mkdir($targetPath, 0777, true) && !is_dir($targetPath)) {
                throw new RuntimeException("No se pudo crear el directorio: {$targetPath}");
            }
        } else {
            if (!copy($item->getPathname(), $targetPath)) {
                throw new RuntimeException("No se pudo copiar el archivo: {$item->getPathname()}");
            }
        }
    }
}

$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $codigo   = trim($_POST['codigo'] ?? '');
    $nombre   = trim($_POST['nombre'] ?? '');
    $password = $_POST['password'] ?? '';

    $codigo = preg_replace('/[^a-z0-9_]/i', '', $codigo);

    if ($codigo === '') {
        $error = 'El código solo puede contener letras, números o guiones bajos.';
    } elseif ($nombre === '') {
        $error = 'El nombre es obligatorio.';
    } elseif ($password === '') {
        $error = 'La contraseña es obligatoria.';
    } else {
        try {
            $db->beginTransaction();

            $stmt = $db->prepare('SELECT COUNT(*) FROM _control_clientes WHERE codigo = ?');
            $stmt->execute([$codigo]);
            if ($stmt->fetchColumn() > 0) {
                throw new RuntimeException('El código ya existe.');
            }

            $hash = password_hash($password, PASSWORD_BCRYPT);
            $stmt = $db->prepare('INSERT INTO _control_clientes (codigo, nombre, password_hash, activo) VALUES (?, ?, ?, 1)');
            $stmt->execute([$codigo, $nombre, $hash]);

            $tablaDocs  = "`{$codigo}_documents`";
            $tablaCodes = "`{$codigo}_codes`";

            $db->exec("CREATE TABLE IF NOT EXISTS {$tablaDocs} (
                id INT AUTO_INCREMENT PRIMARY KEY,
                name VARCHAR(255) NOT NULL,
                date DATE,
                path VARCHAR(255) NOT NULL,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

            $db->exec("CREATE TABLE IF NOT EXISTS {$tablaCodes} (
                id INT AUTO_INCREMENT PRIMARY KEY,
                document_id INT NOT NULL,
                code VARCHAR(255) NOT NULL,
                FOREIGN KEY (document_id) REFERENCES {$tablaDocs}(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

            $uploadsDir = __DIR__ . '/uploads/' . $codigo;
            if (!is_dir($uploadsDir) && !mkdir($uploadsDir, 0777, true) && !is_dir($uploadsDir)) {
                throw new RuntimeException('No se pudo crear la carpeta de uploads.');
            }
            @chmod($uploadsDir, 0777);

            $clientesBase = __DIR__ . '/clientes';
            if (!is_dir($clientesBase) && !mkdir($clientesBase, 0777, true) && !is_dir($clientesBase)) {
                throw new RuntimeException('No se pudo crear el directorio base de clientes.');
            }

            $plantilla = __DIR__ . '/htdocs';
            if (!is_dir($plantilla)) {
                throw new RuntimeException('No se encontró la carpeta de plantilla htdocs/.');
            }

            $clienteDir = $clientesBase . '/' . $codigo;
            if (is_dir($clienteDir)) {
                throw new RuntimeException('La carpeta del cliente ya existe.');
            }

            copy_recursive($plantilla, $clienteDir);

            $apiBootstrap = <<<'PHP'
<?php
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
$_GET['c'] = '{{CLIENTE}}';
$_SESSION['cliente'] = '{{CLIENTE}}';
require dirname(__DIR__, 2) . '/api.php';
PHP;

            $apiBootstrap = str_replace('{{CLIENTE}}', $codigo, $apiBootstrap);
            if (false === file_put_contents($clienteDir . '/api.php', $apiBootstrap)) {
                throw new RuntimeException('No se pudo actualizar api.php de la plantilla.');
            }

            $pdfBootstrap = <<<'PHP'
<?php
$_GET['c'] = '{{CLIENTE}}';
chdir(dirname(__DIR__, 2));
require __DIR__ . '/../../pdf-search.php';
PHP;

            $pdfBootstrap = str_replace('{{CLIENTE}}', $codigo, $pdfBootstrap);
            if (false === file_put_contents($clienteDir . '/pdf-search.php', $pdfBootstrap)) {
                throw new RuntimeException('No se pudo actualizar pdf-search.php de la plantilla.');
            }

            $db->commit();
            $message = '✅ Cliente creado URL: /clientes/' . htmlspecialchars($codigo, ENT_QUOTES, 'UTF-8') . '/index.html';
        } catch (Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            $error = 'Error al crear el cliente: ' . $e->getMessage();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Generador de Clientes</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 2rem; background: #f5f5f5; }
        form { background: #fff; padding: 2rem; border-radius: 8px; max-width: 400px; }
        label { display: block; margin-top: 1rem; font-weight: bold; }
        input { width: 100%; padding: 0.5rem; margin-top: 0.3rem; }
        button { margin-top: 1.5rem; padding: 0.7rem 1.2rem; background: #2563eb; color: #fff; border: none; border-radius: 4px; cursor: pointer; }
        .message { margin-top: 1rem; padding: 0.8rem; border-radius: 4px; }
        .message.success { background: #dcfce7; color: #166534; }
        .message.error { background: #fee2e2; color: #991b1b; }
    </style>
</head>
<body>
    <h1>Crear nuevo cliente</h1>
    <?php if ($message): ?>
        <div class="message success"><?php echo $message; ?></div>
    <?php endif; ?>
    <?php if ($error): ?>
        <div class="message error"><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></div>
    <?php endif; ?>
    <form method="post">
        <label for="codigo">Código</label>
        <input type="text" name="codigo" id="codigo" value="<?php echo htmlspecialchars($_POST['codigo'] ?? '', ENT_QUOTES, 'UTF-8'); ?>" required>

        <label for="nombre">Nombre</label>
        <input type="text" name="nombre" id="nombre" value="<?php echo htmlspecialchars($_POST['nombre'] ?? '', ENT_QUOTES, 'UTF-8'); ?>" required>

        <label for="password">Contraseña</label>
        <input type="password" name="password" id="password" required>

        <button type="submit">Crear cliente</button>
    </form>
</body>
</html>
