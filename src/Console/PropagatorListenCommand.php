<?php

declare(strict_types=1);

namespace Mindgoner\Propagator\Console;

use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Mindgoner\Propagator\Facades\Propagator;
use Mindgoner\Propagator\Models\PropagatorRequest;

class PropagatorListenCommand extends Command
{
    protected $signature = 'propagator:listen';
    protected $description = 'Listen for remote Propagator requests.';

    public function handle(): int
    {
        $remoteUrl = (string) config('propagator.remote_url');
        if ($remoteUrl === '') {
            $this->error('PROPAGATOR_REMOTE_URL is not set.');
            return self::FAILURE;
        }

        $since = $this->lastReceivedAt()->subSecond();
        $this->pullSince($remoteUrl, $since);

        return $this->listenWithPolling($remoteUrl, $since);
    }

    private function listenWithPolling(string $remoteUrl, Carbon $since): int
    {
        $interval = (int) config('propagator.poll_interval', 1);
        if ($interval < 1) {
            $interval = 1;
        }

        $this->info('Listening via polling.');

        while (true) {
            sleep($interval);
            $since = $this->pullSince($remoteUrl, $since);
        }

        return self::SUCCESS;
    }


    private function pullSince(string $remoteUrl, Carbon $since): Carbon
    {
        $sinceParam = $since->copy()->subSecond()->toIso8601String();

        $response = Http::withBasicAuth(
            (string) config('propagator.basic_auth.key'),
            (string) config('propagator.basic_auth.secret')
        )->get($remoteUrl, ['since' => $sinceParam]);

        if (! $response->ok()) {
            $this->warn('Pull failed with status ' . $response->status());
            return $since;
        }

        $payload = $response->json();
        if (! is_array($payload)) {
            $this->warn('Pull response was not a JSON object.');
            return $since;
        }

        if (! ($payload['success'] ?? false)) {
            $message = $payload['message'] ?? 'Unknown error';
            $this->warn('Pull response error: ' . $message);
            return $since;
        }

        $content = $payload['content'] ?? null;
        if (! is_string($content) || $content === '') {
            $this->warn('Pull response missing encrypted content.');
            return $since;
        }

        $secret = (string) config('propagator.shared_secret');
        if ($secret === '') {
            $this->warn('Missing PROPAGATOR_SECRET for decrypting payload.');
            return $since;
        }

        $decrypted = $this->decryptPayload($content, $secret);
        if ($decrypted === null) {
            $this->warn('Failed to decrypt pull payload.');
            return $since;
        }

        $records = json_decode($decrypted, true);
        if (! is_array($records)) {
            $this->warn('Pull payload was not a list.');
            return $since;
        }

        $latest = $since;
        foreach ($records as $record) {
            if (! is_array($record)) {
                continue;
            }

            $request = $this->buildRequestFromRecord($record);
            $request->attributes->set('propagator_received_at', $record['receivedAt'] ?? null);
            $request->attributes->set('propagator_id', $record['id'] ?? null);

            Propagator::record($request);
            $this->replayToLocal($record);

            $recordedAt = $record['receivedAt'] ?? null;
            if (is_string($recordedAt) && $recordedAt !== '') {
                $parsedRecordedAt = Carbon::parse($recordedAt, 'UTC');
                if ($parsedRecordedAt->greaterThan($latest)) {
                    $latest = $parsedRecordedAt;
                }
            }
        }

        return $latest;
    }

    private function decryptPayload(string $payload, string $secret): ?string
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

        return $plaintext;
    }

    private function buildRequestFromRecord(array $record): Request
    {
        $path = (string) ($record['path'] ?? '');
        if ($path === '') {
            $path = '/';
        }

        $method = (string) ($record['method'] ?? 'GET');
        if ($method === '') {
            $method = 'GET';
        }

        $query = $record['queryParams'] ?? [];
        if (! is_array($query)) {
            $query = [];
        }

        $body = (string) ($record['body'] ?? '');

        $localUrl = $this->buildLocalUrl($path);
        $request = Request::create($localUrl, $method, $query, [], [], [], $body);

        $headers = $record['headers'] ?? [];
        if (is_array($headers)) {
            $request->headers->replace($headers);
        }

        $request->headers->set('X-Propagator-Origin', 'remote');

        if (isset($record['ip']) && is_string($record['ip'])) {
            $request->server->set('REMOTE_ADDR', $record['ip']);
        }

        if (isset($record['userAgent']) && is_string($record['userAgent'])) {
            $request->server->set('HTTP_USER_AGENT', $record['userAgent']);
        }

        return $request;
    }

    private function replayToLocal(array $record): void
    {
        $path = (string) ($record['path'] ?? '');
        if ($path === '') {
            return;
        }

        $method = (string) ($record['method'] ?? 'GET');
        $headers = $record['headers'] ?? [];
        if (! is_array($headers)) {
            $headers = [];
        }

        unset($headers['host'], $headers['Host'], $headers['content-length'], $headers['Content-Length']);
        $headers['X-Propagator-Origin'] = 'remote';

        $body = (string) ($record['body'] ?? '');
        $query = $record['queryParams'] ?? [];
        if (! is_array($query)) {
            $query = [];
        }

        $targetUrl = $this->buildLocalUrl($path);

        Http::withHeaders($headers)->send($method, $targetUrl, [
            'query' => $query,
            'body' => $body,
        ]);
    }

    private function lastReceivedAt(): Carbon
    {
        $latest = PropagatorRequest::query()->max('received_at');
        if ($latest instanceof Carbon) {
            return $latest->copy()->setTimezone('UTC');
        }

        if (is_string($latest) && $latest !== '') {
            return Carbon::parse($latest, 'UTC');
        }

        return Carbon::create(1970, 1, 1, 0, 0, 0, 'UTC');
    }

    private function buildLocalUrl(string $path): string
    {
        $base = (string) config('propagator.local_base_url', 'http://localhost');
        $base = rtrim($base, '/');
        $path = '/' . ltrim($path, '/');

        return $base . $path;
    }

}
