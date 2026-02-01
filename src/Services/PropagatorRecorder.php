<?php

declare(strict_types=1);

namespace Mindgoner\Propagator\Services;

use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
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

        $id = (string) $request->attributes->get('propagator_id', '');
        if ($id === '') {
            $id = (string) Str::uuid();
        }

        $payload = [
            'id' => $id,
            'method' => $request->getMethod(),
            'path' => $request->getPathInfo(),
            'headers' => $request->headers->all(),
            'queryParams' => $request->query->all(),
            'body' => $request->getContent(),
            'ip' => $request->ip(),
            'userAgent' => $request->headers->get('User-Agent'),
            'receivedAt' => $receivedAt,
        ];

        $databasePayload = PropagatorRequest::toDatabasePayload($payload);
        $record = PropagatorRequest::updateOrCreate(
            ['id' => $id],
            $databasePayload
        );

        return $record;
    }
}
