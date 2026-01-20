<?php

declare(strict_types=1);

namespace Mindgoner\Propagator\Console;

use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Mindgoner\Propagator\Facades\Propagator;
use Mindgoner\Propagator\Models\PropagatorRequest;
use Pusher\Pusher;

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

        if ($this->pusherEnabled() && class_exists(Pusher::class)) {
            return $this->listenWithPusher($since);
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

    private function listenWithPusher(Carbon $since): int
    {
        $this->info('Listening via Pusher.');

        $pusher = new Pusher(
            (string) config('propagator.pusher.key'),
            (string) config('propagator.pusher.secret'),
            (string) config('propagator.pusher.app_id'),
            [
                'cluster' => (string) config('propagator.pusher.cluster', 'mt1'),
                'useTLS' => true,
            ]
        );

        if (! method_exists($pusher, 'connect')
            || ! method_exists($pusher, 'subscribe')
            || ! method_exists($pusher, 'bind')
            || ! method_exists($pusher, 'loop')
        ) {
            $this->warn('Pusher client does not support websocket connections.');
            return $this->listenWithPolling((string) config('propagator.remote_url'), $since);
        }

        $pusher->connect();
        $pusher->subscribe('propagator.requests');

        $pusher->bind('request.recorded', function ($data) {
            $payload = is_string($data) ? json_decode($data, true) : $data;
            if (! is_array($payload)) {
                return;
            }

            $record = $payload['record'] ?? null;
            if (! is_array($record)) {
                return;
            }

            $request = $this->buildRequestFromRecord($record);
            $request->attributes->set('propagator_received_at', $payload['received_at'] ?? ($record['requestReceivedAt'] ?? null));
            $request->attributes->set('propagator_request_id', $record['requestId'] ?? null);

            Propagator::record($request);
            $this->replayToLocal($record);
        });

        $pusher->loop();

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
            $this->replayToLocal($record);

            $recordedAt = $record['requestReceivedAt'] ?? null;
            if (is_string($recordedAt) && $recordedAt !== '') {
                $parsedRecordedAt = Carbon::parse($recordedAt, 'UTC');
                if ($parsedRecordedAt->greaterThan($latest)) {
                    $latest = $parsedRecordedAt;
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

        $request->headers->set('X-Propagator-Origin', 'remote');

        if (isset($record['requestIp']) && is_string($record['requestIp'])) {
            $request->server->set('REMOTE_ADDR', $record['requestIp']);
        }

        if (isset($record['requestUserAgent']) && is_string($record['requestUserAgent'])) {
            $request->server->set('HTTP_USER_AGENT', $record['requestUserAgent']);
        }

        return $request;
    }

    private function replayToLocal(array $record): void
    {
        $targetUrl = (string) ($record['requestUrl'] ?? '');
        if ($targetUrl === '') {
            return;
        }

        $method = (string) ($record['requestMethod'] ?? 'GET');
        $headers = $record['requestHeaders'] ?? [];
        if (! is_array($headers)) {
            $headers = [];
        }

        unset($headers['host'], $headers['Host'], $headers['content-length'], $headers['Content-Length']);
        $headers['X-Propagator-Origin'] = 'remote';

        $body = (string) ($record['requestBody'] ?? '');
        $query = $record['requestQueryParams'] ?? [];
        if (! is_array($query)) {
            $query = [];
        }

        Http::withHeaders($headers)->send($method, $targetUrl, [
            'query' => $query,
            'body' => $body,
        ]);
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
