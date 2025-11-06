<?php
ini_set('display_errors', '0');
ini_set('display_startup_errors', '0');
error_reporting(E_ALL);

session_start();

require __DIR__ . '/config.php';
require __DIR__ . '/helpers/tenant.php';
require __DIR__ . '/helpers/log.php';
require __DIR__ . '/helpers/auth.php';
require __DIR__ . '/helpers/csrf.php';

require_admin_auth($config);

audit_log('Ingreso al generador de clientes');

$err = $ok = null;
$codigoInput = $_POST['codigo'] ?? '';
$nombreInput = $_POST['nombre'] ?? '';
$codigoCreado = '';
$csrfToken = csrf_get_token();

function generator_hash_secret(string $secret): string
{
    if (preg_match('/^\$2[aby]\$/', $secret) === 1) {
        return $secret;
    }
    return password_hash($secret, PASSWORD_DEFAULT);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (!csrf_validate_token($_POST['_csrf'] ?? null)) {
            throw new RuntimeException('Token CSRF inválido.');
        }

        csrf_regenerate_token();
        $csrfToken = csrf_get_token();

        $codigo = sanitize_code($codigoInput);
        $codigoInput = $codigo;
        $nombre = trim($nombreInput);
        $pass = trim($_POST['password'] ?? '');
        $accessKey = trim($_POST['access_key'] ?? '');
        $deletionKey = trim($_POST['deletion_key'] ?? '');
        $importar = isset($_POST['importar']);

        if ($codigo === '' || $nombre === '' || $pass === '' || $accessKey === '' || $deletionKey === '') {
            throw new RuntimeException('Faltan campos requeridos.');
        }

        $st = $db->prepare('SELECT 1 FROM _control_clientes WHERE codigo = ?');
        $st->execute([$codigo]);
        if ($st->fetch()) {
            throw new RuntimeException('El código ya existe.');
        }

        $hash = password_hash($pass, PASSWORD_BCRYPT);
        $db->prepare('INSERT INTO _control_clientes (codigo, nombre, password_hash, activo) VALUES (?, ?, ?, 1)')
           ->execute([$codigo, $nombre, $hash]);

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

        $uploadsDir = uploads_base_path($config, $codigo);
        if (!is_dir($uploadsDir) && !mkdir($uploadsDir, 0775, true) && !is_dir($uploadsDir)) {
            throw new RuntimeException('No se pudo crear la carpeta de uploads privada.');
        }

        $clientesDir = __DIR__ . '/clientes/' . $codigo;
        if (is_dir($clientesDir)) {
            throw new RuntimeException('La carpeta del cliente ya existe.');
        }

        if (!mkdir($clientesDir, 0775, true) && !is_dir($clientesDir)) {
            throw new RuntimeException('No se pudo crear la carpeta del cliente.');
        }

        $templateDir = __DIR__ . '/htdocs';
        if (is_dir($templateDir)) {
            if (!copy_dir($templateDir, $clientesDir)) {
                throw new RuntimeException('No se pudo copiar la plantilla de htdocs.');
            }
        }

        file_put_contents($clientesDir . '/tenant.json', json_encode([
            'codigo' => $codigo,
            'nombre' => $nombre,
        ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

        $clientConfig = [
            'branding' => [
                'name' => $nombre,
                'contact_email' => $_POST['contact_email'] ?? null,
            ],
            'admin' => [
                'access_key' => generator_hash_secret($accessKey),
                'deletion_key' => generator_hash_secret($deletionKey),
            ],
            'db' => [],
        ];
        $configBody = "<?php\nreturn " . var_export($clientConfig, true) . ";\n";
        file_put_contents($clientesDir . '/config.php', $configBody);

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
        audit_log('Cliente creado: ' . $codigo);
        $codigoInput = '';
        $nombreInput = '';
        $_POST = [];
    } catch (Throwable $e) {
        audit_log('Error al crear cliente: ' . $e->getMessage());
        $err = '❌ ' . $e->getMessage();
        csrf_regenerate_token();
        $csrfToken = csrf_get_token();
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
            <input type="hidden" name="_csrf" value="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>">
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

            <label for="access_key">Clave de acceso del panel</label>
            <input id="access_key" name="access_key" type="text" required minlength="4" value="<?php echo isset($_POST['access_key']) ? htmlspecialchars($_POST['access_key'], ENT_QUOTES, 'UTF-8') : ''; ?>">

            <label for="deletion_key">Clave para eliminar documentos</label>
            <input id="deletion_key" name="deletion_key" type="text" required minlength="4" value="<?php echo isset($_POST['deletion_key']) ? htmlspecialchars($_POST['deletion_key'], ENT_QUOTES, 'UTF-8') : ''; ?>">

            <button type="submit">✅ Crear cliente</button>

            <p class="note">
                Se crearán las tablas necesarias con prefijo, la carpeta privada en
                <code>storage/uploads/<?php echo htmlspecialchars($codigoCreado ?: sanitize_code($codigoInput), ENT_QUOTES, 'UTF-8'); ?></code>
                y se clonará la plantilla de <code>htdocs/</code> si existe.
            </p>
        </form>
    </div>
</body>
</html>
