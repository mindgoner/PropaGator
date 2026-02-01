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
                ->json([
                    'success' => false,
                    'message' => 'Unauthorized',
                    'content' => null,
                ], 401)
                ->header('WWW-Authenticate', 'Basic realm="Propagator"');
        }

        $since = $request->query('since');
        if ($since === null || $since === '') {
            return response()->json([
                'success' => false,
                'message' => 'Missing since parameter',
                'content' => null,
            ], 400);
        }

        try {
            $sinceUtc = Carbon::parse($since, 'UTC');
        } catch (\Throwable $exception) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid since parameter',
                'content' => null,
            ], 400);
        }

        $records = PropagatorRequest::query()
            ->where('received_at', '>', $sinceUtc)
            ->orderBy('received_at')
            ->get();

        $secret = (string) config('propagator.shared_secret');
        if ($secret === '') {
            return response()->json([
                'success' => false,
                'message' => 'Missing PROPAGATOR_SECRET',
                'content' => null,
            ], 500);
        }

        $payloadRecords = $records->map(function (PropagatorRequest $record): array {
            return $this->formatRecord($record);
        })->values()->all();

        $jsonPayload = json_encode($payloadRecords);
        if ($jsonPayload === false) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to serialize payload',
                'content' => null,
            ], 500);
        }
        $encrypted = $this->encryptPayload($jsonPayload, $secret);
        if ($encrypted === null) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to encrypt payload',
                'content' => null,
            ], 500);
        }

        return response()->json([
            'success' => true,
            'message' => null,
            'content' => $encrypted,
        ]);
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

    private function encryptPayload(string $payload, string $secret): ?string
    {
        $cipher = 'AES-256-CBC';
        $ivLength = openssl_cipher_iv_length($cipher);
        if ($ivLength === false) {
            return null;
        }

        $iv = random_bytes($ivLength);
        $key = hash('sha256', $secret, true);
        $macKey = hash('sha256', $secret . '|mac', true);
        $ciphertext = openssl_encrypt($payload, $cipher, $key, OPENSSL_RAW_DATA, $iv);
        if ($ciphertext === false) {
            return null;
        }

        $mac = hash_hmac('sha256', $iv . $ciphertext, $macKey, true);

        return base64_encode($iv . $ciphertext . $mac);
    }

    private function formatRecord(PropagatorRequest $record): array
    {
        return [
            'id' => (string) $record->id,
            'method' => (string) $record->method,
            'path' => (string) $record->path,
            'headers' => $record->headers ?? [],
            'queryParams' => $record->query_params ?? [],
            'body' => (string) $record->body,
            'ip' => $record->ip,
            'userAgent' => $record->user_agent,
            'receivedAt' => $record->received_at
                ? $record->received_at->copy()->setTimezone('UTC')->toIso8601String()
                : null,
            'createdAt' => $record->created_at
                ? $record->created_at->copy()->setTimezone('UTC')->toIso8601String()
                : null,
            'updatedAt' => $record->updated_at
                ? $record->updated_at->copy()->setTimezone('UTC')->toIso8601String()
                : null,
        ];
    }
}
