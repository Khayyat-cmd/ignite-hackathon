<?php

namespace App\Jobs;

use App\Services\Push\PushNotifier;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class SendResponderPushNotification implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @param array<string, string> $data */
    public function __construct(
        public string $responderId,
        public string $title,
        public string $body,
        public array $data,
    ) {}

    /**
     * Execute the job.
     */
    public function handle(PushNotifier $notifier): void
    {
        $notifier->sendToResponder($this->responderId, [
            'title' => $this->title,
            'body' => $this->body,
            'data' => $this->data,
        ]);
    }
}
