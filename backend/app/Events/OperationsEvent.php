<?php

namespace App\Events;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;

final class OperationsEvent implements ShouldBroadcastNow
{
    public function __construct(public readonly array $envelope) {}

    public function broadcastOn(): array
    {
        return [new PrivateChannel('operations')];
    }

    public function broadcastAs(): string
    {
        return $this->envelope['event'];
    }

    public function broadcastWith(): array
    {
        return $this->envelope;
    }
}
