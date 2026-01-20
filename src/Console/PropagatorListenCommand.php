<?php

declare(strict_types=1);

namespace Mindgoner\Propagator\Console;

use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Mindgoner\Propagator\Facades\Propagator;
use Mindgoner\Propagator\Models\PropagatorRequest;
use WebSocket\Client;

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

        $since = $this->lastReceivedAt();
        $this->pullSince($remoteUrl, $since);

        if ($this->pusherEnabled() && class_exists(Client::class)) {
            return $this->listenWithPusher($remoteUrl, $since);
        }

        return $this->listenWithPolling($remoteUrl, $since);
    }

    private function listenWithPolling(string $remoteUrl, Carbon $since): int
    {
        $interval = (int) config('propagator.poll_interval', 1);
        if ($interval < 1) {
            $interval = 1;
        }

        $this->info('Pusher not available. Falling back to polling.');

        while (true) {
            sleep($interval);
            $since = $this->pullSince($remoteUrl, $since);
        }

        return self::SUCCESS;
    }

    private function listenWithPusher(string $remoteUrl, Carbon $since): int
    {
        $this->info('Listening via Pusher.');

        $cluster = (string) config('propagator.pusher.cluster', 'mt1');
        $key = (string) config('propagator.pusher.key');

        $wsUrl = sprintf(
            'wss://ws-%s.pusher.com/app/%s?protocol=7&client=propagator&version=1.0&flash=false',
            $cluster,
            $key
        );

        $client = new Client($wsUrl, ['timeout' => 30]);

        $connected = false;
        while (true) {
            $message = $client->receive();
            if ($message === null) {
                continue;
            }

            $payload = json_decode($message, true);
            if (! is_array($payload) || ! isset($payload['event'])) {
                continue;
            }

            if ($payload['event'] === 'pusher:connection_established') {
                $connected = true;
                $client->send(json_encode([
                    'event' => 'pusher:subscribe',
                    'data' => ['channel' => 'propagator.requests'],
                ]));
                continue;
            }

            if (! $connected) {
                continue;
            }

            if ($payload['event'] === 'pusher:ping') {
                $client->send(json_encode(['event' => 'pusher:pong']));
                continue;
            }

            if ($payload['event'] !== 'request.recorded') {
                continue;
            }

            $data = json_decode((string) ($payload['data'] ?? ''), true);
            if (! is_array($data)) {
                continue;
            }

            $record = $data['record'] ?? null;
            if (! is_array($record)) {
                continue;
            }

            $request = $this->buildRequestFromRecord($record);
            $request->attributes->set('propagator_received_at', $data['received_at'] ?? ($record['requestReceivedAt'] ?? null));
            $request->attributes->set('propagator_request_id', $record['requestId'] ?? null);

            Propagator::record($request);
        }

        return self::SUCCESS;
    }

    private function pullSince(string $remoteUrl, Carbon $since): Carbon
    {
        $response = Http::withBasicAuth(
            (string) config('propagator.basic_auth.key'),
            (string) config('propagator.basic_auth.secret')
        )->get($remoteUrl, ['since' => $since->toIso8601String()]);

        if (! $response->ok()) {
            $this->warn('Pull failed with status ' . $response->status());
            return $since;
        }

        $records = $response->json();
        if (! is_array($records)) {
            $this->warn('Pull response was not a list.');
            return $since;
        }

        $latest = $since;
        foreach ($records as $record) {
            if (! is_array($record)) {
                continue;
            }

            $request = $this->buildRequestFromRecord($record);
            $request->attributes->set('propagator_received_at', $record['requestReceivedAt'] ?? null);
            $request->attributes->set('propagator_request_id', $record['requestId'] ?? null);

            Propagator::record($request);

            $recordedAt = $record['requestReceivedAt'] ?? null;
            if (is_string($recordedAt) && $recordedAt !== '') {
                $parsed = Carbon::parse($recordedAt, 'UTC');
                if ($parsed->greaterThan($latest)) {
                    $latest = $parsed;
                }
            }
        }

        return $latest;
    }

    private function buildRequestFromRecord(array $record): Request
    {
        $url = (string) ($record['requestUrl'] ?? '');
        if ($url === '') {
            $url = 'http://localhost';
        }

        $method = (string) ($record['requestMethod'] ?? 'GET');
        if ($method === '') {
            $method = 'GET';
        }

        $query = $record['requestQueryParams'] ?? [];
        if (! is_array($query)) {
            $query = [];
        }

        $body = (string) ($record['requestBody'] ?? '');

        $request = Request::create($url, $method, $query, [], [], [], $body);

        $headers = $record['requestHeaders'] ?? [];
        if (is_array($headers)) {
            $request->headers->replace($headers);
        }

        if (isset($record['requestIp']) && is_string($record['requestIp'])) {
            $request->server->set('REMOTE_ADDR', $record['requestIp']);
        }

        if (isset($record['requestUserAgent']) && is_string($record['requestUserAgent'])) {
            $request->server->set('HTTP_USER_AGENT', $record['requestUserAgent']);
        }

        return $request;
    }

    private function lastReceivedAt(): Carbon
    {
        $latest = PropagatorRequest::query()->max('requestReceivedAt');
        if ($latest instanceof Carbon) {
            return $latest->copy()->setTimezone('UTC');
        }

        if (is_string($latest) && $latest !== '') {
            return Carbon::parse($latest, 'UTC');
        }

        return Carbon::create(1970, 1, 1, 0, 0, 0, 'UTC');
    }

    private function pusherEnabled(): bool
    {
        return (bool) config('propagator.pusher.enabled', false);
    }
}
