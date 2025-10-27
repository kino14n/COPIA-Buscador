<?php
function sanitize_code(?string $code): string
{
    $code = strtolower($code ?? '');
    return preg_replace('/[^a-z0-9_]/', '', $code);
}

function ensure_active_client(PDO $db, string $code): bool
{
    $stmt = $db->prepare('SELECT 1 FROM _control_clientes WHERE codigo = ? AND activo = 1');
    $stmt->execute([$code]);
    return (bool) $stmt->fetchColumn();
}

function table_docs(string $code): string
{
    return "{$code}_documents";
}

function table_codes(string $code): string
{
    return "{$code}_codes";
}

function copy_dir(string $src, string $dst): bool
{
    if (!is_dir($src)) {
        return false;
    }
    if (!is_dir($dst) && !mkdir($dst, 0777, true) && !is_dir($dst)) {
        return false;
    }

    $dir = opendir($src);
    if (!$dir) {
        return false;
    }

    while (false !== ($file = readdir($dir))) {
        if ($file === '.' || $file === '..') {
            continue;
        }
        $from = $src . '/' . $file;
        $to = $dst . '/' . $file;
        if (is_dir($from)) {
            copy_dir($from, $to);
        } else {
            $parent = dirname($to);
            if (!is_dir($parent) && !mkdir($parent, 0777, true) && !is_dir($parent)) {
                closedir($dir);
                return false;
            }
            copy($from, $to);
        }
    }

    closedir($dir);
    return true;
}
?>
