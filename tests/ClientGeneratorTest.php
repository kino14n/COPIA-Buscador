<?php
declare(strict_types=1);

namespace Tests;

use PDO;

final class ClientGeneratorTest extends BaseTestCase
{
    public function testClientCreation(): void
    {
        $sessionId = bin2hex(random_bytes(16));
        $payload = [
            'session_id' => $sessionId,
            'method' => 'POST',
            'post' => [
                'codigo' => 'nuevo',
                'nombre' => 'Cliente Nuevo',
                'password' => 'claveSegura1',
                'access_key' => 'accesoNuevo',
                'deletion_key' => 'eliminarNuevo',
            ],
        ];

        $cmd = 'php ' . escapeshellarg(__DIR__ . '/support/client_generator_cli.php');
        $process = proc_open($cmd, [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes, __DIR__ . '/..');
        if (!is_resource($process)) {
            $this->fail('No se pudo ejecutar el generador de clientes');
        }
        fwrite($pipes[0], json_encode($payload));
        fclose($pipes[0]);
        stream_get_contents($pipes[1]);
        stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($process);

        $pdo = new PDO('sqlite:' . $this->dbPath);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $stmt = $pdo->prepare('SELECT nombre FROM _control_clientes WHERE codigo = ?');
        $stmt->execute(['nuevo']);
        $row = $stmt->fetchColumn();

        $this->assertSame('Cliente Nuevo', $row);

        $docs = $pdo->query("SELECT name FROM nuevo_documents")->fetchAll(PDO::FETCH_COLUMN);
        $this->assertIsArray($docs);

        $this->deleteDirectory(__DIR__ . '/../clientes/nuevo');
    }
}
