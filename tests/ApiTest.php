<?php
declare(strict_types=1);

namespace Tests;

final class ApiTest extends BaseTestCase
{
    public function testLoginFlow(): void
    {
        $session = $this->callApi([
            'method' => 'GET',
            'get' => ['c' => $this->clientCode, 'action' => 'session'],
        ]);
        $this->assertFalse($session['authenticated']);

        $login = $this->callApi([
            'method' => 'POST',
            'get' => ['c' => $this->clientCode],
            'post' => ['action' => 'authenticate', 'access_key' => $this->accessKey],
        ]);
        $this->assertTrue($login['ok']);

        $sessionAfter = $this->callApi([
            'method' => 'GET',
            'get' => ['c' => $this->clientCode, 'action' => 'session'],
        ]);
        $this->assertTrue($sessionAfter['authenticated']);
    }

    public function testUploadAndSearch(): void
    {
        $this->callApi([
            'method' => 'POST',
            'get' => ['c' => $this->clientCode],
            'post' => ['action' => 'authenticate', 'access_key' => $this->accessKey],
        ]);

        $tmpFile = tempnam($this->tmpDir, 'pdf');
        file_put_contents($tmpFile, "%PDF-1.4\nTest");

        $upload = $this->callApi([
            'method' => 'POST',
            'get' => ['c' => $this->clientCode],
            'post' => [
                'action' => 'upload',
                'name' => 'Contrato',
                'date' => '2024-01-01',
                'codes' => "ABC123\nDEF456",
            ],
            'files' => [
                'file' => [
                    'name' => 'archivo.pdf',
                    'tmp_name' => $tmpFile,
                    'size' => filesize($tmpFile),
                    'type' => 'application/pdf',
                    'error' => 0,
                ],
            ],
        ]);

        $this->assertSame('Documento guardado', $upload['message']);

        $search = $this->callApi([
            'method' => 'POST',
            'get' => ['c' => $this->clientCode],
            'post' => ['action' => 'search', 'codes' => "ABC123"],
        ]);
        $this->assertIsArray($search);
        $this->assertNotEmpty($search);
        $this->assertSame('Contrato', $search[0]['name']);
    }
}
