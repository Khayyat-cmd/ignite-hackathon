<?php

namespace App\Services\Simulation;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Validator;

final class NokiaReachability
{
    public function refresh(): void
    {
        if (! config('aman.reachability.enabled') || ! filled(config('aman.reachability.key'))) {
            return;
        }
        foreach (array_unique(config('aman.reachability.devices')) as $device) {
            $cacheKey = $this->cacheKey($device);
            if (Cache::has($cacheKey.':refresh-after')) {
                continue;
            }
            $lock = Cache::lock($cacheKey.':lock', 15);
            if (! $lock->get()) {
                continue;
            }
            try {
                if (Cache::has($cacheKey.':refresh-after')) {
                    continue;
                }
                $response = Http::acceptJson()->withHeaders([
                    'X-RapidAPI-Key' => config('aman.reachability.key'),
                    'X-RapidAPI-Host' => 'network-as-code.nokia.rapidapi.com',
                ])->connectTimeout(2)->timeout(4)->withoutRedirecting()
                    ->post(config('aman.reachability.url'), ['device' => ['phoneNumber' => $device]]);
                $body = $response->json();
                $valid = $response->successful() && is_array($body) && is_bool($body['reachable'] ?? null)
                    && is_array($body['connectivity'] ?? [])
                    && ! array_diff($body['connectivity'] ?? [], ['DATA', 'SMS'])
                    && Validator::make($body, ['lastStatusTime' => 'sometimes|nullable|date'])->passes();
                $result = $valid ? [
                    'reachable' => $body['reachable'],
                    'connectivity' => $body['connectivity'] ?? [],
                    'dataReachable' => $body['reachable'] && in_array('DATA', $body['connectivity'] ?? [], true),
                    'observedAt' => $body['lastStatusTime'] ?? null,
                    'checkedAt' => now()->toISOString(),
                    'status' => 'ok',
                ] : $this->unknown('Nokia returned an invalid response (HTTP '.$response->status().').');
            } catch (\Throwable) {
                $result = $this->unknown('Nokia reachability check failed. Retrying automatically.');
            } finally {
                if (isset($result)) {
                    $previous = Cache::get($cacheKey);
                    if ($result['status'] === 'ok' || ($previous['status'] ?? null) !== 'ok') {
                        Cache::forever($cacheKey, $result);
                    }
                    Cache::put($cacheKey.':refresh-after', true, 30);
                    unset($result);
                }
                $lock->release();
            }
        }
    }

    public function forResponder(int $index): array
    {
        $device = config('aman.reachability.devices')[$index] ?? null;
        $result = $device && config('aman.reachability.enabled') && filled(config('aman.reachability.key'))
            ? Cache::get($this->cacheKey($device)) : null;

        return [...($result ?? $this->unknown('Nokia reachability is not configured or is awaiting refresh.')),
            'source' => 'nokia_sandbox', 'sandboxDevice' => $device];
    }

    private function cacheKey(string $device): string
    {
        return 'nokia-reachability:'.hash('sha256', config('aman.reachability.key').$device);
    }

    private function unknown(string $message): array
    {
        return ['reachable' => null, 'dataReachable' => false, 'connectivity' => [],
            'observedAt' => null, 'checkedAt' => now()->toISOString(), 'status' => 'unknown', 'error' => $message];
    }
}
