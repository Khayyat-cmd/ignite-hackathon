<?php

namespace App\Jobs;

use App\Models\Responder;
use App\Services\ResponderSignals;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class RefreshResponder implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 75;

    public int $uniqueFor = 120;

    public function __construct(public string $responderId, public ?int $actorId) {}

    public function uniqueId(): string
    {
        return $this->responderId;
    }

    public function handle(ResponderSignals $service): void
    {
        $responder = Responder::find($this->responderId);
        if ($responder?->authorized) {
            $service->refresh($responder, $this->actorId);
        }
    }
}
