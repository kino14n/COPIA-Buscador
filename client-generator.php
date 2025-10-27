<?php
require __DIR__ . '/config.php';
session_start();

$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $codigo   = trim($_POST['codigo'] ?? '');
    $nombre   = trim($_POST['nombre'] ?? '');
    $password = $_POST['password'] ?? '';

    if ($codigo === '' || !preg_match('/^[A-Za-z0-9_]+$/', $codigo)) {
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

            $tablaDocs  = sprintf('`%s_documents`', $codigo);
            $tablaCodes = sprintf('`%s_codes`', $codigo);

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
            if (!is_dir($uploadsDir)) {
                if (!mkdir($uploadsDir, 0777, true) && !is_dir($uploadsDir)) {
                    throw new RuntimeException('No se pudo crear la carpeta de uploads.');
                }
            }

            $db->commit();
            $message = '✅ Cliente creado URL: ?c=' . htmlspecialchars($codigo, ENT_QUOTES, 'UTF-8');
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
