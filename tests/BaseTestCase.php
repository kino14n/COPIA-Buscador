<?php
declare(strict_types=1);

namespace Tests;

use PDO;
use PHPUnit\Framework\TestCase;

abstract class BaseTestCase extends TestCase
{
    protected string $tmpDir;
    protected string $dbPath;
    protected string $storagePath;
    protected string $clientCode = 'testclient';
    protected string $accessKey = 'access123';
    protected string $deletionKey = 'delete123';
    protected string $sessionId;
    protected ?string $csrfToken = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmpDir = sys_get_temp_dir() . '/buscador_' . uniqid('', true);
        $this->dbPath = $this->tmpDir . '/db.sqlite';
        $this->storagePath = $this->tmpDir . '/storage';
        $uploadsPath = $this->storagePath . '/uploads';
        $clientUpload = $uploadsPath . '/' . $this->clientCode;
        $logsPath = $this->tmpDir . '/logs';
        $clientesPath = __DIR__ . '/../clientes/' . $this->clientCode;

        mkdir($this->tmpDir, 0775, true);
        mkdir($uploadsPath, 0775, true);
        mkdir($clientUpload, 0775, true);
        mkdir($logsPath, 0775, true);
        mkdir($clientesPath, 0775, true);

        putenv('DB_DRIVER=sqlite');
        putenv('DB_NAME=' . $this->dbPath);
        putenv('DB_USER=');
        putenv('DB_PASS=');
        putenv('STORAGE_PATH=' . $this->storagePath);
        putenv('UPLOADS_PATH=' . $uploadsPath);
        putenv('LOG_FILE=' . $logsPath . '/app.log');
        putenv('UPLOAD_CLIENT_QUOTA_BYTES=104857600');

        $pdo = new PDO('sqlite:' . $this->dbPath);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec('CREATE TABLE _control_clientes (codigo TEXT PRIMARY KEY, nombre TEXT, password_hash TEXT, activo INTEGER, rol TEXT)');

        $pdo->prepare('INSERT INTO _control_clientes (codigo, nombre, password_hash, activo, rol) VALUES (?,?,?,?,?)')
            ->execute([$this->clientCode, 'Cliente de prueba', password_hash('client-pass', PASSWORD_DEFAULT), 1, 'cliente']);

        $docs = $this->clientCode . '_documents';
        $codes = $this->clientCode . '_codes';
        $pdo->exec("CREATE TABLE {$docs} (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT, date TEXT, path TEXT)");
        $pdo->exec("CREATE TABLE {$codes} (id INTEGER PRIMARY KEY AUTOINCREMENT, document_id INTEGER, code TEXT)");

        $config = [
            'admin' => [
                'access_key' => password_hash($this->accessKey, PASSWORD_DEFAULT),
                'deletion_key' => password_hash($this->deletionKey, PASSWORD_DEFAULT),
            ],
        ];
        file_put_contents($clientesPath . '/config.php', '<?php return ' . var_export($config, true) . ';');

        $this->sessionId = bin2hex(random_bytes(16));
        $this->callApi([
            'method' => 'GET',
            'get' => ['c' => $this->clientCode, 'action' => 'session'],
        ]);
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        $this->deleteDirectory($this->tmpDir);
        $clienteDir = __DIR__ . '/../clientes/' . $this->clientCode;
        $this->deleteDirectory($clienteDir);
    }

    protected function callApi(array $payload): array
    {
        $payload['session_id'] = $this->sessionId;
        $method = strtoupper($payload['method'] ?? 'GET');
        if ($method === 'POST') {
            $payload['post'] = $payload['post'] ?? [];
            if ($this->csrfToken && empty($payload['post']['_csrf'])) {
                $payload['post']['_csrf'] = $this->csrfToken;
            }
        }
        $cmd = 'php ' . escapeshellarg(__DIR__ . '/support/api_cli.php');
        $descriptorSpec = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $process = proc_open($cmd, $descriptorSpec, $pipes, __DIR__ . '/..');
        if (!is_resource($process)) {
            throw new \RuntimeException('No se pudo ejecutar la API de pruebas');
        }
        fwrite($pipes[0], json_encode($payload));
        fclose($pipes[0]);
        $output = stream_get_contents($pipes[1]);
        $errors = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $status = proc_close($process);
        if ($status !== 0 && $errors) {
            throw new \RuntimeException('Error ejecutando API: ' . $errors);
        }
        $response = json_decode($output, true) ?? [];
        if (is_array($response) && array_key_exists('_csrf', $response)) {
            $this->csrfToken = $response['_csrf'];
            unset($response['_csrf']);
        }
        return $response;
    }

    protected function deleteDirectory(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($items as $item) {
            if ($item->isDir()) {
                @rmdir($item->getRealPath());
            } else {
                @unlink($item->getRealPath());
            }
        }
        @rmdir($path);
    }
}
