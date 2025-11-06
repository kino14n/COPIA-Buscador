<?php
function audit_log(string $message, ?array $config = null): void
{
    if ($config === null && isset($GLOBALS['config']) && is_array($GLOBALS['config'])) {
        $config = $GLOBALS['config'];
    }

    if (!$config) {
        return;
    }

    $path = $config['logging']['path'] ?? null;
    if (!$path) {
        return;
    }

    $dir = dirname($path);
    if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
        return;
    }

    $date = date('Y-m-d H:i:s');
    $line = sprintf('[%s] %s%s', $date, $message, PHP_EOL);
    file_put_contents($path, $line, FILE_APPEND | LOCK_EX);
}
