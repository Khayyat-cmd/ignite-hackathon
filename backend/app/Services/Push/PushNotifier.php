<?php

namespace App\Services\Push;

interface PushNotifier
{
    /**
     * @param  array{title: string, body: string, data: array<string, string>}  $notification
     */
    public function sendToResponder(string $responderId, array $notification): void;
}
