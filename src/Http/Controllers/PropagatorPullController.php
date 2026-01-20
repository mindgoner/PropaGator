<?php

declare(strict_types=1);

namespace Mindgoner\Propagator\Http\Controllers;

use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Mindgoner\Propagator\Models\PropagatorRequest;

class PropagatorPullController extends Controller
{
    public function pull(Request $request): JsonResponse
    {
        if (! $this->isAuthorized($request)) {
            return response()
                ->json(['message' => 'Unauthorized'], 401)
                ->header('WWW-Authenticate', 'Basic realm="Propagator"');
        }

        $since = $request->query('since');
        if ($since === null || $since === '') {
            return response()->json(['message' => 'Missing since parameter'], 400);
        }

        try {
            $sinceUtc = Carbon::parse($since, 'UTC');
        } catch (\Throwable $exception) {
            return response()->json(['message' => 'Invalid since parameter'], 400);
        }

        $records = PropagatorRequest::query()
            ->where('requestReceivedAt', '>', $sinceUtc)
            ->orderBy('requestReceivedAt')
            ->get();

        return response()->json($records);
    }

    private function isAuthorized(Request $request): bool
    {
        $expectedKey = (string) config('propagator.basic_auth.key');
        $expectedSecret = (string) config('propagator.basic_auth.secret');

        if ($expectedKey === '' || $expectedSecret === '') {
            return false;
        }

        $authorization = (string) $request->header('Authorization');
        if (! str_starts_with($authorization, 'Basic ')) {
            return false;
        }

        $decoded = base64_decode(substr($authorization, 6), true);
        if ($decoded === false || ! str_contains($decoded, ':')) {
            return false;
        }

        [$key, $secret] = explode(':', $decoded, 2);

        return hash_equals($expectedKey, $key) && hash_equals($expectedSecret, $secret);
    }
}
