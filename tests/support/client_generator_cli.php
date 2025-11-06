<?php
declare(strict_types=1);

$request = json_decode(stream_get_contents(STDIN), true) ?? [];
$sessionId = $request['session_id'] ?? bin2hex(random_bytes(16));
session_id($sessionId);
session_start();

require __DIR__ . '/../../config.php';
require __DIR__ . '/../../helpers/auth.php';
require __DIR__ . '/../../helpers/log.php';
require __DIR__ . '/../../helpers/tenant.php';
require __DIR__ . '/../../helpers/csrf.php';

$sessionKey = admin_session_key($config);
$_SESSION[$sessionKey] = true;

$_SERVER['REQUEST_METHOD'] = $request['method'] ?? 'GET';
$_POST = $request['post'] ?? [];
$_GET = $request['get'] ?? [];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && empty($_POST['_csrf'])) {
    $_POST['_csrf'] = csrf_get_token();
}

ob_start();
require __DIR__ . '/../../client-generator.php';
$output = ob_get_clean();

echo $output;
