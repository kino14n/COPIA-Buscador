<?php
require __DIR__ . '/config.php';
require __DIR__ . '/helpers/auth.php';
require __DIR__ . '/helpers/log.php';

session_start();

$redirect = $_GET['redirect'] ?? ($_POST['redirect'] ?? '/client-generator.php');
if (!is_string($redirect) || strpos($redirect, '\n') !== false) {
    $redirect = '/client-generator.php';
}

$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = (string) ($_POST['password'] ?? '');

    if (authenticate_admin($config, $username, $password)) {
        admin_login($config);
        audit_log('Superadministrador autenticado');
        header('Location: ' . $redirect);
        exit;
    }

    $error = 'Credenciales inválidas';
    audit_log('Intento fallido de autenticación de superadministrador');
}
?>
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Acceso administrador</title>
    <style>
        body { font-family: system-ui, sans-serif; background: #0b1220; color: #f0f4ff; display:flex; align-items:center; justify-content:center; min-height:100vh; margin:0; }
        form { background:#111a2b; padding:2rem; border-radius:1rem; box-shadow:0 20px 40px rgba(0,0,0,0.4); width:100%; max-width:360px; }
        h1 { margin-top:0; margin-bottom:1.5rem; font-size:1.6rem; text-align:center; }
        label { display:block; margin-bottom:0.5rem; font-weight:600; }
        input { width:100%; padding:0.75rem 1rem; border-radius:0.75rem; border:1px solid #1f2a3d; background:#0d1627; color:#f0f4ff; margin-bottom:1rem; }
        button { width:100%; padding:0.85rem; border:none; border-radius:0.75rem; background:#3b82f6; color:#fff; font-weight:600; cursor:pointer; }
        .error { background:#dc2626; padding:0.75rem 1rem; border-radius:0.75rem; margin-bottom:1rem; text-align:center; }
    </style>
</head>
<body>
    <form method="post" autocomplete="off">
        <h1>Panel administrador</h1>
        <?php if ($error): ?>
            <div class="error"><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></div>
        <?php endif; ?>
        <label for="username">Usuario</label>
        <input id="username" name="username" type="text" required autofocus>
        <label for="password">Contraseña</label>
        <input id="password" name="password" type="password" required>
        <input type="hidden" name="redirect" value="<?php echo htmlspecialchars($redirect, ENT_QUOTES, 'UTF-8'); ?>">
        <button type="submit">Acceder</button>
    </form>
</body>
</html>
