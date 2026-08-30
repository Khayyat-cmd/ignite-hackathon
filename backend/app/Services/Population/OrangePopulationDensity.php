<?php

namespace App\Services\Population;

use App\Exceptions\IntegrationUnavailable;
use DateTimeInterface;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

final class OrangePopulationDensity
{
    /**
     * @param  array<int, array{latitude: float|int, longitude: float|int}>  $boundary
     * @return array{source: string, status: string, timedPopulationDensityData: array<int, mixed>}
     */
    public function retrieve(array $boundary, DateTimeInterface $start, DateTimeInterface $end): array
    {
        $this->assertConfigured();
        $this->assertRequest($boundary, $start, $end);

        try {
            $response = Http::acceptJson()
                ->asJson()
                ->withToken($this->accessToken())
                ->timeout(config('population.timeout_seconds'))
                ->post(config('population.orange.api_url'), [
                    'area' => ['areaType' => 'POLYGON', 'boundary' => $boundary],
                    'startTime' => $start->format('Y-m-d\TH:i:s.v\Z'),
                    'endTime' => $end->format('Y-m-d\TH:i:s.v\Z'),
                    'precision' => 7,
                ]);
        } catch (ConnectionException) {
            throw new IntegrationUnavailable('population_timeout_or_connection');
        }

        if (! $response->successful()) {
            throw new IntegrationUnavailable('population_provider_rejected', $response->status());
        }

        $body = $response->json();
        if (! is_array($body) || ! is_string($body['status'] ?? null) || ! is_array($body['timedPopulationDensityData'] ?? null)) {
            throw new IntegrationUnavailable('population_malformed_response');
        }

        return [
            'source' => 'orange_playground',
            'status' => $body['status'],
            'timedPopulationDensityData' => $body['timedPopulationDensityData'],
        ];
    }

    private function accessToken(): string
    {
        $clientId = (string) config('population.orange.client_id');
        $cacheKey = 'population:orange:token:'.hash('sha256', $clientId);
        $cached = Cache::get($cacheKey);
        if (is_string($cached) && $cached !== '') {
            return $cached;
        }

        try {
            $response = Http::acceptJson()
                ->asForm()
                ->withBasicAuth($clientId, (string) config('population.orange.client_secret'))
                ->timeout(config('population.timeout_seconds'))
                ->post(config('population.orange.token_url'), ['grant_type' => 'client_credentials']);
        } catch (ConnectionException) {
            throw new IntegrationUnavailable('population_auth_timeout_or_connection');
        }

        $token = $response->json('access_token');
        $expiresIn = $response->json('expires_in');
        if (! $response->successful() || ! is_string($token) || $token === '') {
            throw new IntegrationUnavailable('population_auth_rejected', $response->status());
        }

        $ttl = is_numeric($expiresIn) ? max(30, min(3300, (int) $expiresIn - 60)) : 3300;
        Cache::put($cacheKey, $token, $ttl);

        return $token;
    }

    private function assertConfigured(): void
    {
        if (config('population.provider') !== 'orange_playground'
            || blank(config('population.orange.client_id'))
            || blank(config('population.orange.client_secret'))
            || ! str_starts_with((string) config('population.orange.token_url'), 'https://')
            || ! str_starts_with((string) config('population.orange.api_url'), 'https://')) {
            throw new IntegrationUnavailable('population_not_configured');
        }
    }

    /** @param array<int, array{latitude: float|int, longitude: float|int}> $boundary */
    private function assertRequest(array $boundary, DateTimeInterface $start, DateTimeInterface $end): void
    {
        if (count($boundary) < 3 || count($boundary) > 15 || $end <= $start || $end->getTimestamp() - $start->getTimestamp() > 604800) {
            throw new IntegrationUnavailable('population_invalid_request');
        }

        foreach ($boundary as $point) {
            $latitude = $point['latitude'] ?? null;
            $longitude = $point['longitude'] ?? null;
            if (! is_numeric($latitude) || ! is_numeric($longitude) || $latitude < -90 || $latitude > 90 || $longitude < -180 || $longitude > 180) {
                throw new IntegrationUnavailable('population_invalid_request');
            }
        }
    }
}
