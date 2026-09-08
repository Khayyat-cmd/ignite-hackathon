<?php

namespace App\Services\Push;

use App\Models\DevicePushToken;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;

final class FirebaseCloudMessaging implements PushNotifier
{
    public function sendToResponder(string $responderId, array $notification): void
    {
        if (! $this->configured()) {
            return;
        }

        DevicePushToken::query()->where('responder_id', $responderId)->get()
            ->each(fn (DevicePushToken $registration) => $this->send($registration, $notification));
    }

    private function send(DevicePushToken $registration, array $notification): void
    {
        $response = $this->client()->post(
            sprintf('https://fcm.googleapis.com/v1/projects/%s/messages:send', rawurlencode(config('services.firebase.project_id'))),
            ['message' => [
                'token' => $registration->token,
                'notification' => ['title' => $notification['title'], 'body' => $notification['body']],
                'data' => $notification['data'],
                'android' => ['priority' => 'high', 'notification' => [
                    'channel_id' => 'aman_missions',
                    'notification_priority' => 'PRIORITY_MAX',
                    'visibility' => 'PUBLIC',
                    'default_vibrate_timings' => false,
                    'vibrate_timings' => ['0s', '0.5s', '0.25s', '0.5s', '0.25s', '0.5s'],
                ]],
                'apns' => [
                    'headers' => ['apns-priority' => '10'],
                    'payload' => ['aps' => [
                        'sound' => 'default',
                        'interruption-level' => 'time-sensitive',
                    ]],
                ],
            ]],
        );

        if ($response->successful()) {
            return;
        }
        if ($response->status() === 404 && data_get($response->json(), 'error.details.0.errorCode') === 'UNREGISTERED') {
            $registration->delete();

            return;
        }

        $response->throw();
    }

    private function client(): PendingRequest
    {
        return Http::acceptJson()->asJson()->withToken($this->accessToken())
            ->timeout((int) config('services.firebase.timeout_seconds'));
    }

    private function accessToken(): string
    {
        return Cache::remember('firebase.messaging.access_token', now()->addMinutes(50), function (): string {
            $credentials = $this->credentials();
            $now = time();
            $header = $this->base64Url(json_encode(['alg' => 'RS256', 'typ' => 'JWT'], JSON_THROW_ON_ERROR));
            $claims = $this->base64Url(json_encode([
                'iss' => $credentials['client_email'],
                'scope' => 'https://www.googleapis.com/auth/firebase.messaging',
                'aud' => $credentials['token_uri'] ?? 'https://oauth2.googleapis.com/token',
                'iat' => $now,
                'exp' => $now + 3600,
            ], JSON_THROW_ON_ERROR));
            $unsigned = $header.'.'.$claims;
            abort_unless(openssl_sign($unsigned, $signature, $credentials['private_key'], OPENSSL_ALGO_SHA256), 500, 'Could not sign Firebase credentials.');
            $assertion = $unsigned.'.'.$this->base64Url($signature);

            $response = Http::asForm()->timeout((int) config('services.firebase.timeout_seconds'))->post(
                $credentials['token_uri'] ?? 'https://oauth2.googleapis.com/token',
                ['grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer', 'assertion' => $assertion],
            )->throw();

            return $response->json('access_token');
        });
    }

    /** @return array{client_email: string, private_key: string, token_uri?: string} */
    private function credentials(): array
    {
        $path = config('services.firebase.credentials');
        if (! is_string($path) || ! is_readable($path)) {
            throw new RuntimeException('Firebase credentials file is not readable.');
        }
        $credentials = json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
        if (! is_string($credentials['client_email'] ?? null) || ! is_string($credentials['private_key'] ?? null)) {
            throw new RuntimeException('Firebase credentials file is invalid.');
        }

        return $credentials;
    }

    private function configured(): bool
    {
        return filled(config('services.firebase.project_id')) && filled(config('services.firebase.credentials'));
    }

    private function base64Url(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}
