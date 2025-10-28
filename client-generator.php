<?php
// Habilitar reporte de errores para debugging
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Iniciar sesión
session_start();

error_log('✅ [GENERATOR] client-generator.php iniciado');
echo '<!-- GEN INIT -->' . PHP_EOL;

// Verificar que los archivos necesarios existan
$configFile = __DIR__ . '/config.php';
$helperFile = __DIR__ . '/helpers/tenant.php';

if (!file_exists($configFile)) {
    die('❌ Error: No se encuentra config.php en ' . $configFile);
}

if (!file_exists($helperFile)) {
    die('❌ Error: No se encuentra helpers/tenant.php en ' . $helperFile);
}

// Cargar archivos requeridos
try {
    require $configFile;
    require $helperFile;
    echo '<!-- Config y helpers cargados correctamente -->' . PHP_EOL;
} catch (Exception $e) {
    die('❌ Error al cargar archivos: ' . $e->getMessage());
}

// Verificar que la conexión a la base de datos existe
if (!isset($db) || !($db instanceof PDO)) {
    die('❌ Error: No hay conexión a la base de datos');
}

// Variables de estado
$err = $ok = null;
$codigoInput = $_POST['codigo'] ?? '';
$nombreInput = $_POST['nombre'] ?? '';
$codigoCreado = '';

// Procesar el formulario
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $codigo = sanitize_code($codigoInput);
        $nombre = trim($nombreInput);
        $pass = trim($_POST['password'] ?? '');
        $importar = isset($_POST['importar']);

        if ($codigo === '' || $nombre === '' || $pass === '') {
            throw new Exception('Faltan campos requeridos.');
        }

        // Verificar que el código no existe
        $st = $db->prepare('SELECT 1 FROM _control_clientes WHERE codigo = ?');
        $st->execute([$codigo]);
        if ($st->fetch()) {
            throw new Exception('El código ya existe.');
        }

        // Crear el cliente
        $hash = password_hash($pass, PASSWORD_BCRYPT);
        $db->prepare('INSERT INTO _control_clientes (codigo, nombre, password_hash, activo) VALUES (?, ?, ?, 1)')
           ->execute([$codigo, $nombre, $hash]);

        // Crear tablas del cliente
        $docs = table_docs($codigo);
        $codes = table_codes($codigo);
        $docsSql = "`{$docs}`";
        $codesSql = "`{$codes}`";

        $db->exec("CREATE TABLE IF NOT EXISTS {$docsSql} (
            id INT AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(255) NOT NULL,
            date DATE NOT NULL,
            path VARCHAR(255) NOT NULL,
            codigos_extraidos TEXT DEFAULT NULL,
            INDEX idx_date (date)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $db->exec("CREATE TABLE IF NOT EXISTS {$codesSql} (
            id INT AUTO_INCREMENT PRIMARY KEY,
            document_id INT NOT NULL,
            code VARCHAR(100) NOT NULL,
            INDEX idx_code (code),
            FOREIGN KEY (document_id) REFERENCES {$docsSql} (id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        // Crear carpeta de uploads
        $uploadsDir = __DIR__ . '/uploads/' . $codigo;
        if (!is_dir($uploadsDir)) {
            if (!mkdir($uploadsDir, 0777, true) && !is_dir($uploadsDir)) {
                throw new Exception('No se pudo crear la carpeta de uploads.');
            }
        }

        // Crear carpeta del cliente
        $clientesDir = __DIR__ . '/clientes';
        if (!is_dir($clientesDir)) {
            if (!mkdir($clientesDir, 0777, true) && !is_dir($clientesDir)) {
                throw new Exception('No se pudo crear la carpeta clientes.');
            }
        }

        $templateDir = __DIR__ . '/htdocs';
        $destDir = $clientesDir . '/' . $codigo;

        if (is_dir($destDir)) {
            throw new Exception('La carpeta del cliente ya existe.');
        }

        // Copiar plantilla
        if (is_dir($templateDir)) {
            if (!copy_dir($templateDir, $destDir)) {
                throw new Exception('No se pudo copiar la plantilla de htdocs.');
            }
        } else {
            if (!mkdir($destDir, 0777, true) && !is_dir($destDir)) {
                throw new Exception('No se pudo crear la carpeta del cliente.');
            }
        }

        // Crear archivo tenant.json
        file_put_contents($destDir . '/tenant.json', json_encode([
            'codigo' => $codigo,
            'nombre' => $nombre,
        ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

        // Importar datos si se solicitó
        if ($importar) {
            $hasDocs = $db->query("SHOW TABLES LIKE 'documents'")->fetchColumn();
            $hasCodes = $db->query("SHOW TABLES LIKE 'codes'")->fetchColumn();

            if ($hasDocs) {
                $db->exec("INSERT INTO {$docsSql} (id, name, date, path, codigos_extraidos)
                    SELECT id, name, date, path, codigos_extraidos FROM `documents`");
            }
            if ($hasCodes) {
                $db->exec("INSERT INTO {$codesSql} (id, document_id, code)
                    SELECT id, document_id, code FROM `codes`");
            }

            if ($hasDocs) {
                $nextId = (int) $db->query("SELECT IFNULL(MAX(id), 0) + 1 FROM {$docsSql}")->fetchColumn();
                $db->exec("ALTER TABLE {$docsSql} AUTO_INCREMENT = {$nextId}");
            }
            if ($hasCodes) {
                $nextCodeId = (int) $db->query("SELECT IFNULL(MAX(id), 0) + 1 FROM {$codesSql}")->fetchColumn();
                $db->exec("ALTER TABLE {$codesSql} AUTO_INCREMENT = {$nextCodeId}");
            }
        }

        $ok = "✅ Cliente creado exitosamente. URL: /clientes/{$codigo}/index.html | API con c: ?c={$codigo}";
        $codigoCreado = $codigo;
        error_log('✅ [GENERATOR] Cliente creado: ' . $codigo);
        
    } catch (Exception $e) {
        error_log('❌ [GENERATOR] Error: ' . $e->getMessage());
        echo '<!-- GEN ERROR: ' . htmlspecialchars($e->getMessage()) . ' -->' . PHP_EOL;
        $err = '❌ ' . $e->getMessage();
    }
}
?>
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Crear cliente</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: system-ui, -apple-system, "Segoe UI", Roboto, Ubuntu, "Helvetica Neue", Arial, sans-serif;
            background: linear-gradient(135deg, #0b1220 0%, #1a2332 100%);
            color: #e8eefc;
            display: grid;
            place-items: center;
            min-height: 100vh;
            padding: 20px;
        }
        .container {
            width: 100%;
            max-width: 600px;
        }
        form {
            background: #111a2b;
            padding: 32px;
            border-radius: 16px;
            box-shadow: 0 10px 40px rgba(0, 0, 0, 0.5);
        }
        h2 {
            margin-bottom: 24px;
            color: #e8eefc;
            font-size: 1.75rem;
            text-align: center;
        }
        label {
            display: block;
            margin-top: 16px;
            margin-bottom: 6px;
            color: #9fb3ce;
            font-weight: 500;
        }
        input[type=text],
        input[type=password] {
            width: 100%;
            padding: 12px 14px;
            border: 1px solid #2a3550;
            border-radius: 10px;
            background: #0e1626;
            color: #e8eefc;
            font-size: 1rem;
            transition: border-color 0.2s;
        }
        input[type=text]:focus,
        input[type=password]:focus {
            outline: none;
            border-color: #2a6df6;
        }
        button {
            margin-top: 24px;
            width: 100%;
            padding: 14px;
            border: 0;
            border-radius: 12px;
            background: #2a6df6;
            color: #fff;
            font-weight: 700;
            font-size: 1.05rem;
            cursor: pointer;
            transition: filter 0.2s;
        }
        button:hover {
            filter: brightness(1.15);
        }
        .row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 16px;
            align-items: end;
        }
        .ok {
            background: #0f5132;
            color: #d1f3e0;
            padding: 14px 18px;
            border-radius: 10px;
            margin-bottom: 16px;
            line-height: 1.5;
        }
        .err {
            background: #5c1a1a;
            color: #ffe3e3;
            padding: 14px 18px;
            border-radius: 10px;
            margin-bottom: 16px;
            line-height: 1.5;
        }
        .chk {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-top: 16px;
            color: #9fb3ce;
        }
        .chk input {
            width: auto;
            margin: 0;
            cursor: pointer;
        }
        .chk label {
            margin: 0;
            cursor: pointer;
        }
        p.note {
            margin-top: 16px;
            color: #9fb3ce;
            font-size: 0.9rem;
            line-height: 1.5;
        }
        code {
            color: #c8d9ff;
            background: #0e1626;
            padding: 2px 6px;
            border-radius: 4px;
        }
        @media (max-width: 640px) {
            form { padding: 24px; }
            .row { grid-template-columns: 1fr; }
        }
    </style>
</head>
<body>
    <div class="container">
        <form method="post">
            <h2>🏢 Crear nuevo cliente</h2>
            
            <?php if ($ok): ?>
                <div class="ok"><?php echo htmlspecialchars($ok, ENT_QUOTES, 'UTF-8'); ?></div>
            <?php endif; ?>
            
            <?php if ($err): ?>
                <div class="err"><?php echo htmlspecialchars($err, ENT_QUOTES, 'UTF-8'); ?></div>
            <?php endif; ?>
            
            <label for="codigo">Código del cliente <small>(minúsculas, números y _)</small></label>
            <input 
                id="codigo" 
                name="codigo" 
                type="text"
                required 
                pattern="[a-z0-9_]+"
                placeholder="cliente1" 
                value="<?php echo htmlspecialchars($codigoInput, ENT_QUOTES, 'UTF-8'); ?>"
            >
            
            <label for="nombre">Nombre del cliente</label>
            <input 
                id="nombre" 
                name="nombre" 
                type="text"
                required 
                placeholder="Ferretería XYZ" 
                value="<?php echo htmlspecialchars($nombreInput, ENT_QUOTES, 'UTF-8'); ?>"
            >
            
            <div class="row">
                <div>
                    <label for="password">Contraseña</label>
                    <input id="password" name="password" type="password" required minlength="4">
                </div>
                <div class="chk">
                    <input 
                        type="checkbox" 
                        name="importar" 
                        id="im" 
                        <?php echo isset($_POST['importar']) ? 'checked' : ''; ?>
                    >
                    <label for="im">Importar datos de tablas globales</label>
                </div>
            </div>
            
            <button type="submit">✅ Crear cliente</button>
            
            <p class="note">
                Se crearán las tablas necesarias con prefijo, la carpeta 
                <code>uploads/<?php echo htmlspecialchars($codigoCreado ?: sanitize_code($codigoInput), ENT_QUOTES, 'UTF-8'); ?></code> 
                y se clonará la plantilla de <code>htdocs/</code>.
            </p>
        </form>
    </div>
</body>
</html>
