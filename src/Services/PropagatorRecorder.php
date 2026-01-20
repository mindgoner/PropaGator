<?php

declare(strict_types=1);

namespace Mindgoner\Propagator\Services;

use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Mindgoner\Propagator\Events\RequestRecorded;
use Mindgoner\Propagator\Models\PropagatorRequest;

class PropagatorRecorder
{
    private const ORIGIN_HEADER = 'X-Propagator-Origin';
    private const ORIGIN_REMOTE = 'remote';

    public function record(Request $request): PropagatorRequest
    {
        $receivedAt = $request->attributes->get('propagator_received_at');
        if ($receivedAt instanceof Carbon) {
            $receivedAt = $receivedAt->copy()->setTimezone('UTC');
        } elseif (is_string($receivedAt) && $receivedAt !== '') {
            $receivedAt = Carbon::parse($receivedAt, 'UTC');
        } else {
            $receivedAt = Carbon::now('UTC');
        }

        $requestId = (string) $request->attributes->get('propagator_request_id', '');
        if ($requestId === '') {
            $requestId = (string) Str::uuid();
        }

        $payload = [
            'requestId' => $requestId,
            'requestMethod' => $request->getMethod(),
            'requestUrl' => $request->fullUrl(),
            'requestHeaders' => $request->headers->all(),
            'requestQueryParams' => $request->query->all(),
            'requestBody' => $request->getContent(),
            'requestIp' => $request->ip(),
            'requestUserAgent' => $request->headers->get('User-Agent'),
            'requestReceivedAt' => $receivedAt,
        ];

        if ($requestId !== '') {
            $record = PropagatorRequest::updateOrCreate(
                ['requestId' => $requestId],
                $payload
            );
        } else {
            $record = PropagatorRequest::create($payload);
        }

        $shouldBroadcast = ! $this->isRemoteRequest($request);
        event(new RequestRecorded($record, $shouldBroadcast));

        return $record;
    }

    private function isRemoteRequest(Request $request): bool
    {
        return $request->headers->get(self::ORIGIN_HEADER) === self::ORIGIN_REMOTE;
    }
}
