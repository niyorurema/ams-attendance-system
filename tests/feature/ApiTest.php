<?php

namespace Tests\Feature;

use CodeIgniter\Test\CIUnitTestCase;
use Config\Services;

/**
 * Tests HTTP de bout en bout pour l'API.
 *
 * Exécution : API_E2E_ENABLED=1 vendor/bin/phpunit tests/feature/ApiTest.php
 */
class ApiTest extends CIUnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        if (getenv('API_E2E_ENABLED') !== '1') {
            $this->markTestSkipped('Définissez API_E2E_ENABLED=1 avec le serveur API démarré.');
        }
    }

    public function testLoginSuccess(): void
    {
        $response = Services::curlrequest(['http_errors' => false])->post($this->baseUrl() . '/api/auth/login', [
            'json' => [
                'tenant_id' => (int) env('API_TEST_TENANT_ID', 1),
                'email' => (string) env('API_TEST_EMAIL', 'admin@obr.local'),
                'password' => (string) env('API_TEST_PASSWORD', 'Admin123!'),
            ],
        ]);

        $data = json_decode($response->getBody(), true);
        $this->assertSame(200, $response->getStatusCode(), 'Réponse login : ' . $response->getBody());
        $this->assertIsArray($data);
        $this->assertTrue($data['success'] ?? false);
        $this->assertArrayHasKey('access_token', $data['data'] ?? []);
        $this->assertNotEmpty($data['data']['access_token']);
    }

    public function testAuthenticatedProfile(): void
    {
        $login = $this->login();
        $response = Services::curlrequest(['http_errors' => false])->get($this->baseUrl() . '/api/me', [
            'headers' => ['Authorization' => 'Bearer ' . $login['data']['access_token']],
        ]);

        $data = json_decode($response->getBody(), true);
        $this->assertSame(200, $response->getStatusCode(), 'Réponse login : ' . $response->getBody());
        $this->assertIsArray($data);
        $this->assertTrue($data['success'] ?? false);
        $this->assertSame((int) $login['data']['user']['id'], (int) $data['data']['id']);
    }

    public function testProtectedEndpointRejectsMissingToken(): void
    {
        $response = Services::curlrequest(['http_errors' => false])->get($this->baseUrl() . '/api/me');
        $data = json_decode($response->getBody(), true);

        $this->assertSame(401, $response->getStatusCode());
        $this->assertIsArray($data);
        $this->assertFalse($data['success'] ?? true);
        $this->assertNull($data['data'] ?? null);
    }

    /** @return array<string, mixed> */
    private function login(): array
    {
        $response = Services::curlrequest(['http_errors' => false])->post($this->baseUrl() . '/api/auth/login', [
            'json' => [
                'tenant_id' => (int) env('API_TEST_TENANT_ID', 1),
                'email' => (string) env('API_TEST_EMAIL', 'admin@obr.local'),
                'password' => (string) env('API_TEST_PASSWORD', 'Admin123!'),
            ],
        ]);
        $data = json_decode($response->getBody(), true);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertIsArray($data);
        $this->assertTrue($data['success'] ?? false);

        return $data;
    }

    private function baseUrl(): string
    {
        return rtrim((string) env('API_TEST_BASE_URL', 'http://localhost:8080'), '/');
    }
}
