<?php

namespace App\Jobs;

use App\Events\OperationsEvent;
use App\Models\DomainEvent;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class PublishDomainEvent implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    public int $timeout = 20;

    public int $uniqueFor = 300;

    public function __construct(public int $eventId) {}

    public function uniqueId(): string
    {
        return (string) $this->eventId;
    }

    public function backoff(): array
    {
        return [1, 5, 15, 60];
    }

    public function handle(): void
    {
        $event = DomainEvent::find($this->eventId);
        if (! $event || $event->published_at || in_array(config('broadcasting.default'), ['null', 'log'], true)) {
            return;
        }
        event(new OperationsEvent($event->envelope()));
        $event->update(['published_at' => now()]);
    }
}
