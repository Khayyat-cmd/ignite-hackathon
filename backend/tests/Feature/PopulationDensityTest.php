<?php

namespace Tests\Feature;

use App\Exceptions\IntegrationUnavailable;
use App\Services\Population\OrangePopulationDensity;
use DateTimeImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class PopulationDensityTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config([
            'population.provider' => 'orange_playground',
            'population.orange.client_id' => 'client-id',
            'population.orange.client_secret' => 'client-secret',
            'population.orange.token_url' => 'https://api.orange.test/token',
            'population.orange.api_url' => 'https://api.orange.test/density',
        ]);
        Cache::flush();
        Http::preventStrayRequests();
    }

    public function test_it_authenticates_and_retrieves_mocked_density(): void
    {
        Http::fake([
            'https://api.orange.test/token' => Http::response(['access_token' => 'short-lived-token', 'expires_in' => 3600]),
            'https://api.orange.test/density' => Http::response([
                'status' => 'success',
                'timedPopulationDensityData' => [['cellPopulationDensityData' => [['geohash' => 'sy1zg4x', 'dataType' => 'DENSITY_ESTIMATION', 'pplDensity' => 100]]]],
            ]),
        ]);

        $result = $this->retrieve();

        $this->assertSame('orange_playground', $result['source']);
        $this->assertSame(100, $result['timedPopulationDensityData'][0]['cellPopulationDensityData'][0]['pplDensity']);
        Http::assertSent(fn ($request) => $request->url() === 'https://api.orange.test/token'
            && $request->hasHeader('Authorization', 'Basic '.base64_encode('client-id:client-secret'))
            && $request['grant_type'] === 'client_credentials');
        Http::assertSent(fn ($request) => $request->url() === 'https://api.orange.test/density'
            && $request->hasHeader('Authorization', 'Bearer short-lived-token')
            && $request['area']['areaType'] === 'POLYGON'
            && $request['precision'] === 7);
    }

    public function test_it_caches_the_short_lived_access_token(): void
    {
        Http::fake([
            'https://api.orange.test/token' => Http::response(['access_token' => 'cached-token', 'expires_in' => 3600]),
            'https://api.orange.test/density' => Http::response(['status' => 'success', 'timedPopulationDensityData' => []]),
        ]);

        $this->retrieve();
        $this->retrieve();

        Http::assertSentCount(3);
    }

    public function test_it_does_not_send_requests_without_credentials(): void
    {
        config(['population.orange.client_secret' => null]);

        try {
            $this->retrieve();
            $this->fail('Expected unavailable integration.');
        } catch (IntegrationUnavailable $exception) {
            $this->assertSame('population_not_configured', $exception->reason);
        }

        Http::assertNothingSent();
    }

    public function test_it_rejects_invalid_boundaries_before_network_access(): void
    {
        $this->expectException(IntegrationUnavailable::class);

        $start = new DateTimeImmutable('2026-08-30T19:00:00Z');
        app(OrangePopulationDensity::class)->retrieve([
            ['latitude' => 91, 'longitude' => 35.5],
            ['latitude' => 33.9, 'longitude' => 35.6],
            ['latitude' => 33.8, 'longitude' => 35.5],
        ], $start, $start->modify('+30 minutes'));

        Http::assertNothingSent();
    }

    /** @return array{source: string, status: string, timedPopulationDensityData: array<int, mixed>} */
    private function retrieve(): array
    {
        $start = new DateTimeImmutable('2026-08-30T19:00:00Z');

        return app(OrangePopulationDensity::class)->retrieve([
            ['latitude' => 33.9, 'longitude' => 35.5],
            ['latitude' => 33.91, 'longitude' => 35.5],
            ['latitude' => 33.91, 'longitude' => 35.51],
        ], $start, $start->modify('+30 minutes'));
    }
}
