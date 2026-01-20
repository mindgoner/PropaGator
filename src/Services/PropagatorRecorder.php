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

        $record = PropagatorRequest::create([
            'requestId' => $requestId,
            'requestMethod' => $request->getMethod(),
            'requestUrl' => $request->fullUrl(),
            'requestHeaders' => $request->headers->all(),
            'requestQueryParams' => $request->query->all(),
            'requestBody' => $request->getContent(),
            'requestIp' => $request->ip(),
            'requestUserAgent' => $request->headers->get('User-Agent'),
            'requestReceivedAt' => $receivedAt,
        ]);

        event(new RequestRecorded($record));

        return $record;
    }
}
