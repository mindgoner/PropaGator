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
        return PropagatorRequest::create([
            'requestId' => (string) Str::uuid(),
            'requestMethod' => $request->getMethod(),
            'requestUrl' => $request->fullUrl(),
            'requestHeaders' => $request->headers->all(),
            'requestQueryParams' => $request->query->all(),
            'requestBody' => $request->getContent(),
            'requestIp' => $request->ip(),
            'requestUserAgent' => $request->headers->get('User-Agent'),
            'requestReceivedAt' => Carbon::now('UTC'),
        ]);
    }
}
