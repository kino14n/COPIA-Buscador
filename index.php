<?php
require __DIR__ . '/config.php';
session_start();

function has_column(PDO $db, string $table, string $column): bool {
    $stmt = $db->prepare('SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?');
    $stmt->execute([$table, $column]);
    return (bool)$stmt->fetchColumn();
}

$hasRolColumn = false;
try {
    $hasRolColumn = has_column($db, '_control_clientes', 'rol');
} catch (PDOException $e) {
    $hasRolColumn = false;
}

if (isset($_GET['logout'])) {
    session_destroy();
    header('Location: index.php');
    exit;
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $codigo = trim($_POST['cliente'] ?? '');
    $password = $_POST['password'] ?? '';

    if ($codigo === '' || $password === '') {
        $error = 'Debe seleccionar un cliente y escribir la contraseña.';
    } else {
        try {
            $select = 'SELECT codigo, nombre, password_hash, activo' . ($hasRolColumn ? ', rol' : '') . ' FROM _control_clientes WHERE codigo = ? LIMIT 1';
            $stmt = $db->prepare($select);
            $stmt->execute([$codigo]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$row || !(int)$row['activo'] || !password_verify($password, $row['password_hash'])) {
                $error = 'Credenciales inválidas.';
            } else {
                $_SESSION['cliente'] = $row['codigo'];
                $rol = $hasRolColumn && isset($row['rol']) && $row['rol'] ? $row['rol'] : 'cliente';
                $_SESSION['cliente_rol'] = $rol;

                if ($rol === 'admin') {
                    header('Location: admin/');
                } else {
                    header('Location: index.html?c=' . urlencode($row['codigo']));
                }
                exit;
            }
        } catch (PDOException $e) {
            $error = 'Error en la autenticación: ' . $e->getMessage();
        }
    }
}

try {
    $clientesStmt = $db->query('SELECT codigo, nombre FROM _control_clientes WHERE activo = 1 ORDER BY nombre');
    $clientes = $clientesStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $clientes = [];
    $error = $error ?: 'No se pudieron cargar los clientes: ' . $e->getMessage();
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Acceso al Buscador</title>
    <style>
        body { font-family: Arial, sans-serif; background: #f3f4f6; margin: 0; display: flex; align-items: center; justify-content: center; min-height: 100vh; }
        .card { background: #fff; padding: 2.5rem; border-radius: 12px; box-shadow: 0 10px 30px rgba(15, 23, 42, 0.12); width: 100%; max-width: 420px; }
        h1 { margin-top: 0; color: #111827; text-align: center; }
        label { display: block; margin-top: 1.2rem; font-weight: bold; color: #1f2937; }
        select, input { width: 100%; padding: 0.75rem; margin-top: 0.5rem; border-radius: 0.5rem; border: 1px solid #d1d5db; font-size: 1rem; }
        button { width: 100%; margin-top: 1.8rem; padding: 0.85rem; border: none; border-radius: 0.5rem; background: #2563eb; color: #fff; font-size: 1.05rem; font-weight: bold; cursor: pointer; transition: background-color 0.2s ease-in-out; }
        button:hover { background: #1d4ed8; }
        .error { margin-top: 1rem; color: #b91c1c; background: #fee2e2; padding: 0.75rem; border-radius: 0.5rem; }
        .logout { text-align: center; margin-top: 1.5rem; }
        .logout a { color: #2563eb; text-decoration: none; }
    </style>
</head>
<body>
    <div class="card">
        <h1>Selecciona tu cliente</h1>
        <?php if ($error): ?>
            <div class="error"><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></div>
        <?php endif; ?>
        <form method="post">
            <label for="cliente">Cliente</label>
            <select name="cliente" id="cliente" required>
                <option value="">Seleccione un cliente...</option>
                <?php foreach ($clientes as $cli): ?>
                    <option value="<?php echo htmlspecialchars($cli['codigo'], ENT_QUOTES, 'UTF-8'); ?>" <?php echo (($_POST['cliente'] ?? '') === $cli['codigo']) ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars($cli['nombre'] . ' (' . $cli['codigo'] . ')', ENT_QUOTES, 'UTF-8'); ?>
                    </option>
                <?php endforeach; ?>
            </select>

            <label for="password">Contraseña</label>
            <input type="password" name="password" id="password" required>

            <button type="submit">Ingresar</button>
        </form>
        <?php if (isset($_SESSION['cliente'])): ?>
            <div class="logout">
                <a href="?logout=1">Cerrar sesión de <?php echo htmlspecialchars($_SESSION['cliente'], ENT_QUOTES, 'UTF-8'); ?></a>
            </div>
        <?php endif; ?>
    </div>
</body>
</html>
