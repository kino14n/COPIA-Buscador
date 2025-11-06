<?php
declare(strict_types=1);

$request = json_decode(stream_get_contents(STDIN), true) ?? [];
$_GET = $request['get'] ?? [];
$_POST = $request['post'] ?? [];
$_FILES = [];
$_SERVER = array_merge($_SERVER, $request['server'] ?? []);
$_SERVER['REQUEST_METHOD'] = $request['method'] ?? 'GET';
$_SERVER['REMOTE_ADDR'] = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';

if (!empty($request['session_id'])) {
    session_id($request['session_id']);
}

if (!empty($request['files']) && is_array($request['files'])) {
    foreach ($request['files'] as $field => $info) {
        $_FILES[$field] = $info;
    }
}

require __DIR__ . '/../../api.php';
