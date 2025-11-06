<?php
require __DIR__ . '/config.php';
require __DIR__ . '/helpers/tenant.php';
require __DIR__ . '/helpers/auth.php';
require __DIR__ . '/helpers/log.php';
require __DIR__ . '/helpers/csrf.php';

session_start();
require_admin_auth($config);

audit_log('Ingreso al administrador de clientes');

$err = $ok = null;
$csrfToken = csrf_get_token();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (!csrf_validate_token($_POST['_csrf'] ?? null)) {
            throw new RuntimeException('Token CSRF inválido.');
        }

        csrf_regenerate_token();
        $csrfToken = csrf_get_token();

        $action = $_POST['action'] ?? '';
        switch ($action) {
            case 'toggle':
                $codigo = sanitize_code($_POST['codigo'] ?? '');
                if ($codigo === '') {
                    throw new RuntimeException('Código de cliente inválido.');
                }
                $stmt = $db->prepare('SELECT activo FROM _control_clientes WHERE codigo = ?');
                $stmt->execute([$codigo]);
                $row = $stmt->fetch(PDO::FETCH_ASSOC);
                if (!$row) {
                    throw new RuntimeException('Cliente no encontrado.');
                }
                $nuevoEstado = (int) (!((int) $row['activo']));
                $upd = $db->prepare('UPDATE _control_clientes SET activo = ? WHERE codigo = ?');
                $upd->execute([$nuevoEstado, $codigo]);
                $ok = $nuevoEstado ? 'Cliente activado correctamente.' : 'Cliente desactivado correctamente.';
                audit_log('Actualización de estado de cliente ' . $codigo . ' -> ' . $nuevoEstado);
                break;
            default:
                throw new RuntimeException('Acción no soportada.');
        }
    } catch (Throwable $e) {
        $err = '❌ ' . $e->getMessage();
        csrf_regenerate_token();
        $csrfToken = csrf_get_token();
    }
}

try {
    $clientesStmt = $db->query('SELECT codigo, nombre, activo FROM _control_clientes ORDER BY nombre');
    $clientes = $clientesStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $clientes = [];
    $err = $err ?: 'No se pudieron cargar los clientes: ' . $e->getMessage();
}
?>
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Administración de clientes</title>
    <style>
        body {
            font-family: system-ui, -apple-system, "Segoe UI", sans-serif;
            margin: 0;
            background: #0b1220;
            color: #e8eefc;
            min-height: 100vh;
        }
        header {
            padding: 1.5rem 2rem;
            background: #111a2b;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.4);
        }
        main {
            padding: 2rem;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 1.5rem;
        }
        th, td {
            padding: 0.75rem 1rem;
            text-align: left;
        }
        th {
            background: #18263b;
        }
        tr:nth-child(even) {
            background: rgba(255, 255, 255, 0.04);
        }
        tr:nth-child(odd) {
            background: rgba(255, 255, 255, 0.02);
        }
        .status {
            font-weight: 600;
        }
        .status--active {
            color: #4ade80;
        }
        .status--inactive {
            color: #f87171;
        }
        .actions form {
            display: inline;
        }
        .btn {
            border: none;
            border-radius: 0.5rem;
            padding: 0.5rem 0.9rem;
            cursor: pointer;
            font-weight: 600;
        }
        .btn--primary {
            background: #3b82f6;
            color: #fff;
        }
        .btn--warning {
            background: #f97316;
            color: #fff;
        }
        .message {
            margin-top: 1rem;
            padding: 0.75rem 1rem;
            border-radius: 0.75rem;
            background: rgba(59, 130, 246, 0.2);
        }
        .message.err {
            background: rgba(239, 68, 68, 0.25);
        }
        .empty {
            margin-top: 2rem;
            text-align: center;
            opacity: 0.8;
        }
        a.back {
            color: #93c5fd;
            text-decoration: none;
        }
    </style>
</head>
<body>
    <header>
        <h1>Panel de administración de clientes</h1>
        <p><a class="back" href="client-generator.php">← Volver al generador</a></p>
    </header>
    <main>
        <?php if ($ok): ?>
            <div class="message"><?php echo htmlspecialchars($ok, ENT_QUOTES, 'UTF-8'); ?></div>
        <?php endif; ?>
        <?php if ($err): ?>
            <div class="message err"><?php echo htmlspecialchars($err, ENT_QUOTES, 'UTF-8'); ?></div>
        <?php endif; ?>

        <?php if (!$clientes): ?>
            <p class="empty">No hay clientes registrados.</p>
        <?php else: ?>
            <table>
                <thead>
                    <tr>
                        <th>Código</th>
                        <th>Nombre</th>
                        <th>Estado</th>
                        <th>Acciones</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($clientes as $cli): ?>
                        <tr>
                            <td><code><?php echo htmlspecialchars($cli['codigo'], ENT_QUOTES, 'UTF-8'); ?></code></td>
                            <td><?php echo htmlspecialchars($cli['nombre'], ENT_QUOTES, 'UTF-8'); ?></td>
                            <td class="status <?php echo ((int) $cli['activo']) ? 'status--active' : 'status--inactive'; ?>">
                                <?php echo ((int) $cli['activo']) ? 'Activo' : 'Inactivo'; ?>
                            </td>
                            <td class="actions">
                                <form method="post">
                                    <input type="hidden" name="_csrf" value="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>">
                                    <input type="hidden" name="action" value="toggle">
                                    <input type="hidden" name="codigo" value="<?php echo htmlspecialchars($cli['codigo'], ENT_QUOTES, 'UTF-8'); ?>">
                                    <button type="submit" class="btn btn--warning">
                                        <?php echo ((int) $cli['activo']) ? 'Desactivar' : 'Activar'; ?>
                                    </button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </main>
</body>
</html>
