<?php

declare(strict_types=1);

function app_log_path(): string
{
    if (isset($GLOBALS['config']) && is_array($GLOBALS['config'])) {
        $path = $GLOBALS['config']['logging']['path'] ?? null;
        if (is_string($path) && $path !== '') {
            $dir = dirname($path);
            if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
                throw new RuntimeException('No se pudo crear el directorio de logs');
            }
            return $path;
        }
    }

    $defaultDir = __DIR__ . '/../logs';
    if (!is_dir($defaultDir) && !mkdir($defaultDir, 0775, true) && !is_dir($defaultDir)) {
        throw new RuntimeException('No se pudo crear el directorio de logs por defecto');
    }

    return $defaultDir . '/app.log';
}

function log_event(string $level, string $message, array $context = []): void
{
    try {
        $record = [
            'timestamp' => date('c'),
            'level' => strtoupper($level),
            'message' => $message,
            'context' => $context,
        ];

        $line = json_encode($record, JSON_UNESCAPED_UNICODE);
        if ($line === false) {
            $line = json_encode([
                'timestamp' => $record['timestamp'],
                'level' => $record['level'],
                'message' => 'Error codificando registro de log',
            ], JSON_UNESCAPED_UNICODE);
        }

        file_put_contents(app_log_path(), $line . PHP_EOL, FILE_APPEND | LOCK_EX);
    } catch (Throwable $e) {
        error_log('Fallo escribiendo log estructurado: ' . $e->getMessage());
    }
}
