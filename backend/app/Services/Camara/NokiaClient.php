<?php

namespace App\Services\Camara;

use App\Exceptions\IntegrationUnavailable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

final class NokiaClient
{
    /** Read-only operations only. Never retry session creation using this method. */
    public function query(string $operation, array $payload): array
    {
        $mode = config('camara.mode');
        $key = config('camara.api_key');
        $base = rtrim((string) config('camara.base_url'), '/');
        $path = config('camara.paths.'.$operation);

        if (! in_array($mode, ['sandbox', 'live'], true) || ! is_string($key) || $key === '') {
            throw new IntegrationUnavailable('not_configured');
        }
        if (! is_string($path) || ! str_starts_with($base, 'https://')) {
            throw new IntegrationUnavailable('invalid_configuration');
        }
        $phone = data_get($payload, 'device.phoneNumber');
        if (! is_string($phone) || ! preg_match('/^\+[1-9][0-9]{4,14}$/', $phone)) {
            throw new IntegrationUnavailable('invalid_device');
        }
        if ($mode === 'sandbox' && ! str_starts_with($phone, '+9999')) {
            throw new IntegrationUnavailable('sandbox_requires_test_device');
        }

        $fingerprint = hash('sha256', $base.$path.$key.json_encode($payload, JSON_THROW_ON_ERROR));
        $backoffKey = 'camara:backoff:'.hash('sha256', $base.$key);
        if (Cache::has($backoffKey)) {
            throw new IntegrationUnavailable('provider_backoff');
        }

        return Cache::remember('camara:query:'.$fingerprint, config('camara.cache_seconds'), function () use ($base, $path, $payload, $key, $backoffKey) {
            for ($attempt = 0; $attempt < 2; $attempt++) {
                try {
                    $response = Http::acceptJson()->asJson()
                        ->withHeaders(['X-RapidAPI-Key' => $key, 'X-RapidAPI-Host' => config('camara.host')])
                        ->withOptions(['allow_redirects' => false])
                        ->connectTimeout(config('camara.connect_timeout_seconds'))
                        ->timeout(config('camara.timeout_seconds'))
                        ->post($base.$path, $payload);
                } catch (ConnectionException) {
                    if ($attempt === 0) {
                        usleep(150_000);

                        continue;
                    }
                    throw new IntegrationUnavailable('timeout_or_connection');
                }
                if ($response->status() === 429) {
                    $seconds = ctype_digit((string) $response->header('Retry-After')) ? (int) $response->header('Retry-After') : 30;
                    Cache::put($backoffKey, true, max(1, min(300, $seconds)));
                    throw new IntegrationUnavailable('rate_limited', 429);
                }
                if ($response->serverError() && $attempt === 0) {
                    usleep(150_000);

                    continue;
                }
                if (! $response->successful()) {
                    throw new IntegrationUnavailable('provider_rejected', $response->status());
                }
                $body = $response->json();
                if (! is_array($body)) {
                    throw new IntegrationUnavailable('malformed_response');
                }

                return $body;
            }
            throw new IntegrationUnavailable('provider_unavailable');
        });
    }
}
