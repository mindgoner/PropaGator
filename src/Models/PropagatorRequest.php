<?php

declare(strict_types=1);

namespace Mindgoner\Propagator\Models;

use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;

class PropagatorRequest extends Model
{
    protected $guarded = [];

    protected $casts = [
        'headers' => 'array',
        'query_params' => 'array',
        'received_at' => 'datetime',
    ];

    public $incrementing = false;
    protected $keyType = 'string';

    public function getTable(): string
    {
        return config('propagator.table_prefix', 'propagator_') . 'requests';
    }

    public static function toDatabasePayload(array $payload): array
    {
        return [
            'id' => $payload['id'] ?? null,
            'method' => $payload['method'] ?? null,
            'path' => $payload['path'] ?? null,
            'headers' => $payload['headers'] ?? null,
            'query_params' => $payload['queryParams'] ?? null,
            'body' => $payload['body'] ?? null,
            'ip' => $payload['ip'] ?? null,
            'user_agent' => $payload['userAgent'] ?? null,
            'received_at' => $payload['receivedAt'] ?? null,
        ];
    }

    protected function serializeDate(DateTimeInterface $date): string
    {
        return $date->setTimezone(new \DateTimeZone('UTC'))->format(DateTimeInterface::ATOM);
    }
}
