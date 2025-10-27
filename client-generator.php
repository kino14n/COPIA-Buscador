<?php
session_start();
require __DIR__ . '/config.php';
require __DIR__ . '/helpers/tenant.php';

$err = $ok = null;
$codigoInput = $_POST['codigo'] ?? '';
$nombreInput = $_POST['nombre'] ?? '';
$codigoCreado = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $codigo = sanitize_code($codigoInput);
        $nombre = trim($nombreInput);
        $pass = trim($_POST['password'] ?? '');
        $importar = isset($_POST['importar']);

        if ($codigo === '' || $nombre === '' || $pass === '') {
            throw new Exception('Faltan campos requeridos.');
        }

        $st = $db->prepare('SELECT 1 FROM _control_clientes WHERE codigo = ?');
        $st->execute([$codigo]);
        if ($st->fetch()) {
            throw new Exception('El código ya existe.');
        }

        $hash = password_hash($pass, PASSWORD_BCRYPT);
        $db->prepare('INSERT INTO _control_clientes (codigo, nombre, password_hash, activo) VALUES (?, ?, ?, 1)')->execute([$codigo, $nombre, $hash]);

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

        $uploadsDir = __DIR__ . '/uploads/' . $codigo;
        if (!is_dir($uploadsDir) && !mkdir($uploadsDir, 0777, true) && !is_dir($uploadsDir)) {
            throw new Exception('No se pudo crear la carpeta de uploads.');
        }

        $clientesDir = __DIR__ . '/clientes';
        if (!is_dir($clientesDir) && !mkdir($clientesDir, 0777, true) && !is_dir($clientesDir)) {
            throw new Exception('No se pudo crear la carpeta clientes.');
        }

        $templateDir = __DIR__ . '/htdocs';
        $destDir = $clientesDir . '/' . $codigo;

        if (is_dir($destDir)) {
            throw new Exception('La carpeta del cliente ya existe.');
        }

        if (is_dir($templateDir)) {
            if (!copy_dir($templateDir, $destDir)) {
                throw new Exception('No se pudo copiar la plantilla de htdocs.');
            }
        } else {
            if (!mkdir($destDir, 0777, true) && !is_dir($destDir)) {
                throw new Exception('No se pudo crear la carpeta del cliente.');
            }
        }

        file_put_contents($destDir . '/tenant.json', json_encode([
            'codigo' => $codigo,
            'nombre' => $nombre,
        ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

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

        $ok = "✅ Cliente creado. URL: /clientes/{$codigo}/index.html | API con c: ?c={$codigo}";
        $codigoCreado = $codigo;
    } catch (Exception $e) {
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
        body {font-family: system-ui,-apple-system,"Segoe UI",Roboto,Ubuntu,"Helvetica Neue",Arial;background:#0b1220;color:#e8eefc;display:grid;place-items:center;min-height:100vh;}
        form {background:#111a2b;padding:24px;border-radius:16px;box-shadow:0 10px 30px rgba(0,0,0,.35);width:min(560px,90vw);}
        label {display:block;margin:.6rem 0 .25rem;color:#9fb3ce;}
        input[type=text], input[type=password] {width:100%;padding:.7rem .8rem;border:1px solid #2a3550;border-radius:10px;background:#0e1626;color:#e8eefc;}
        button {margin-top:12px;width:100%;padding:.9rem;border:0;border-radius:12px;background:#2a6df6;color:#fff;font-weight:700;cursor:pointer;}
        button:hover {filter:brightness(1.08);}
        .row {display:grid;grid-template-columns:1fr 1fr;gap:.8rem;align-items:end;}
        .ok {background:#0f5132;color:#d1f3e0;padding:.8rem 1rem;border-radius:10px;margin-bottom:12px;}
        .err {background:#5c1a1a;color:#ffe3e3;padding:.8rem 1rem;border-radius:10px;margin-bottom:12px;}
        .chk {display:flex;align-items:center;gap:.6rem;margin-top:.6rem;color:#9fb3ce;}
        .chk input {width:auto;margin:0;}
        p.note {margin-top:10px;color:#9fb3ce;}
        code {color:#c8d9ff;}
    </style>
</head>
<body>
<form method="post">
    <h2>🏢 Crear nuevo cliente</h2>
    <?php if ($ok) { echo '<div class="ok">' . htmlspecialchars($ok, ENT_QUOTES, 'UTF-8') . '</div>'; } ?>
    <?php if ($err) { echo '<div class="err">' . htmlspecialchars($err, ENT_QUOTES, 'UTF-8') . '</div>'; } ?>
    <label for="codigo">Código (minúsculas, números y _)</label>
    <input id="codigo" name="codigo" required placeholder="cliente1" value="<?php echo htmlspecialchars($codigoInput, ENT_QUOTES, 'UTF-8'); ?>">
    <label for="nombre">Nombre</label>
    <input id="nombre" name="nombre" required placeholder="Ferretería XYZ" value="<?php echo htmlspecialchars($nombreInput, ENT_QUOTES, 'UTF-8'); ?>">
    <div class="row">
        <div>
            <label for="password">Password</label>
            <input id="password" name="password" type="password" required>
        </div>
        <div class="chk">
            <input type="checkbox" name="importar" id="im" <?php echo isset($_POST['importar']) ? 'checked' : ''; ?>>
            <label for="im">Importar datos de tablas globales (documents/codes)</label>
        </div>
    </div>
    <button type="submit">✅ Crear cliente</button>
    <p class="note">Se crearán tablas con prefijo, carpeta <code>uploads/<?php echo htmlspecialchars($codigoCreado ?: sanitize_code($codigoInput), ENT_QUOTES, 'UTF-8'); ?></code> y clon de <code>htdocs/</code>.</p>
</form>
</body>
</html>
