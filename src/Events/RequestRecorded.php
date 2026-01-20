<?php

declare(strict_types=1);

namespace Mindgoner\Propagator\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Mindgoner\Propagator\Models\PropagatorRequest;

class RequestRecorded implements ShouldBroadcast
{
    use Dispatchable;
    use InteractsWithSockets;
    use SerializesModels;

    public PropagatorRequest $record;
    public string $receivedAt;
    private bool $broadcast;

    public function __construct(PropagatorRequest $record, bool $broadcast = true)
    {
        $this->record = $record;
        $this->receivedAt = $record->requestReceivedAt->copy()->setTimezone('UTC')->toIso8601String();
        $this->broadcast = $broadcast;
    }

    public function broadcastOn(): Channel
    {
        return new Channel('propagator.requests');
    }

    public function broadcastAs(): string
    {
        return 'request.recorded';
    }

    public function broadcastWhen(): bool
    {
        return $this->broadcast && (bool) config('propagator.pusher.enabled', false);
    }

    public function broadcastWith(): array
    {
        return [
            'record' => $this->record->toArray(),
            'received_at' => $this->receivedAt,
        ];
    }
}
