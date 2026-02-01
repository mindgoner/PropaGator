<?php

declare(strict_types=1);

namespace Mindgoner\Propagator\Tests\Feature;

use Carbon\Carbon;
use Illuminate\Support\Str;
use Mindgoner\Propagator\Models\PropagatorRequest;
use Mindgoner\Propagator\Tests\TestCase;

class PropagatorPullControllerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->app['config']->set('propagator.basic_auth.key', 'demo');
        $this->app['config']->set('propagator.basic_auth.secret', 'demo-secret');
        $this->app['config']->set('propagator.shared_secret', 'shared-secret');
    }

    public function test_pull_returns_encrypted_payload(): void
    {
        $receivedAt = Carbon::parse('2024-01-01T00:00:05Z');

        $record = PropagatorRequest::create([
            'id' => (string) Str::uuid(),
            'method' => 'POST',
            'path' => '/webhooks/provider',
            'headers' => ['content-type' => ['application/json']],
            'query_params' => [],
            'body' => '{"event":"paid"}',
            'ip' => '203.0.113.10',
            'user_agent' => 'ProviderBot/1.0',
            'received_at' => $receivedAt,
        ]);

        $since = $receivedAt->copy()->subSecond()->toIso8601String();

        $response = $this->withHeaders([
            'Authorization' => $this->basicAuthHeader('demo', 'demo-secret'),
        ])->getJson('/propagator/pull?since=' . $since);

        $response->assertOk();
        $response->assertJson([
            'success' => true,
            'message' => null,
        ]);

        $content = (string) $response->json('content');
        $payload = $this->decryptPayload($content, 'shared-secret');

        $this->assertNotNull($payload);
        $this->assertCount(1, $payload);
        $this->assertSame($record->id, $payload[0]['id']);
        $this->assertSame('/webhooks/provider', $payload[0]['path']);
    }

    public function test_pull_payload_hmac_detects_tampering(): void
    {
        $record = PropagatorRequest::create([
            'id' => (string) Str::uuid(),
            'method' => 'POST',
            'path' => '/webhooks/provider',
            'headers' => ['content-type' => ['application/json']],
            'query_params' => [],
            'body' => '{"event":"paid"}',
            'ip' => '203.0.113.10',
            'user_agent' => 'ProviderBot/1.0',
            'received_at' => Carbon::parse('2024-01-01T00:00:05Z'),
        ]);

        $since = '2024-01-01T00:00:00Z';

        $response = $this->withHeaders([
            'Authorization' => $this->basicAuthHeader('demo', 'demo-secret'),
        ])->getJson('/propagator/pull?since=' . $since);

        $response->assertOk();

        $content = (string) $response->json('content');
        $lastChar = substr($content, -1);
        $replacement = $lastChar === 'A' ? 'B' : 'A';
        $tampered = substr($content, 0, -1) . $replacement;

        $this->assertNull($this->decryptPayload($tampered, 'shared-secret'));
    }

    private function basicAuthHeader(string $key, string $secret): string
    {
        return 'Basic ' . base64_encode($key . ':' . $secret);
    }

    private function decryptPayload(string $payload, string $secret): ?array
    {
        $cipher = 'AES-256-CBC';
        $ivLength = openssl_cipher_iv_length($cipher);
        if ($ivLength === false) {
            return null;
        }

        $decoded = base64_decode($payload, true);
        if ($decoded === false) {
            return null;
        }

        $macLength = 32;
        if (strlen($decoded) <= $ivLength + $macLength) {
            return null;
        }

        $iv = substr($decoded, 0, $ivLength);
        $ciphertext = substr($decoded, $ivLength, -$macLength);
        $mac = substr($decoded, -$macLength);

        $key = hash('sha256', $secret, true);
        $macKey = hash('sha256', $secret . '|mac', true);
        $expectedMac = hash_hmac('sha256', $iv . $ciphertext, $macKey, true);

        if (! hash_equals($expectedMac, $mac)) {
            return null;
        }

        $plaintext = openssl_decrypt($ciphertext, $cipher, $key, OPENSSL_RAW_DATA, $iv);
        if ($plaintext === false) {
            return null;
        }

        $decodedJson = json_decode($plaintext, true);
        if (! is_array($decodedJson)) {
            return null;
        }

        return $decodedJson;
    }
}
