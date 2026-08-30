<?php

namespace Tests\Feature;

use App\Exceptions\IntegrationUnavailable;
use App\Services\Camara\NokiaClient;
use App\Services\Camara\NokiaNetwork;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class NokiaNetworkTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['camara.mode' => 'sandbox', 'camara.api_key' => 'test-secret']);
        Http::preventStrayRequests();
    }

    public function test_retrieval_matches_nokia_contract_and_preserves_accuracy(): void
    {
        Http::fake(['*/location-retrieval/v0/retrieve' => Http::response(['lastLocationTime' => now()->toISOString(), 'area' => ['areaType' => 'CIRCLE', 'center' => ['latitude' => 33.9, 'longitude' => 35.5], 'radius' => 800]])]);
        $result = app(NokiaNetwork::class)->location('+99999991001');
        $this->assertSame(800.0, $result['accuracyMeters']);
        Http::assertSent(fn ($r) => $r->hasHeader('X-RapidAPI-Key', 'test-secret') && $r['device']['phoneNumber'] === '+99999991001' && $r['maxAge'] === 120);
        app(NokiaNetwork::class)->location('+99999991001');
        Http::assertSentCount(1);
    }

    public function test_no_requests_without_credentials(): void
    {
        config(['camara.api_key' => null]);
        try {
            app(NokiaNetwork::class)->location('+99999991001');
            $this->fail('Expected unavailable integration.');
        } catch (IntegrationUnavailable $e) {
            $this->assertSame('not_configured', $e->reason);
        }
        Http::assertNothingSent();
    }

    public function test_sandbox_rejects_real_devices(): void
    {
        $this->expectException(IntegrationUnavailable::class);
        app(NokiaNetwork::class)->location('+96181123456');
    }

    public function test_sms_reachability_is_not_data_reachability(): void
    {
        Http::fake(['*' => Http::response(['reachable' => true, 'connectivity' => ['SMS']])]);
        $result = app(NokiaNetwork::class)->reachability('+99999991001');
        $this->assertFalse($result['dataReachable']);
        $this->assertNull($result['observedAt']);
    }

    public function test_rate_limit_causes_backoff_not_a_retry_storm(): void
    {
        Http::fake(['*' => Http::response(['message' => 'private-provider-detail'], 429, ['Retry-After' => '30'])]);
        foreach (['rate_limited', 'provider_backoff'] as $reason) {
            try {
                app(NokiaNetwork::class)->reachability('+99999991001');
                $this->fail('Expected rate limit.');
            } catch (IntegrationUnavailable $e) {
                $this->assertSame($reason, $e->reason);
                $this->assertStringNotContainsString('private-provider-detail', $e->getMessage());
            }
        }
        Http::assertSentCount(1);
    }

    public function test_server_failure_retries_only_once(): void
    {
        Http::fake(['*' => Http::sequence()->push([], 503)->push(['reachable' => false], 200)]);
        $this->assertFalse(app(NokiaNetwork::class)->reachability('+99999991001')['reachable']);
        Http::assertSentCount(2);
    }

    public function test_authentication_failure_is_not_retried_or_exposed(): void
    {
        Http::fake(['*' => Http::response(['message' => 'test-secret'], 401)]);
        try {
            app(NokiaNetwork::class)->location('+99999991001');
            $this->fail('Expected provider rejection.');
        } catch (IntegrationUnavailable $e) {
            $this->assertSame(401, $e->providerStatus);
            $this->assertStringNotContainsString('test-secret', $e->getMessage());
        }
        Http::assertSentCount(1);
    }

    public function test_polygon_location_is_not_invented_as_an_exact_point(): void
    {
        Http::fake(['*' => Http::response(['area' => ['areaType' => 'POLYGON'], 'lastLocationTime' => now()->toISOString()])]);
        $this->expectException(IntegrationUnavailable::class);
        app(NokiaNetwork::class)->location('+99999991001');
    }

    public function test_non_json_response_is_rejected(): void
    {
        Http::fake(['*' => Http::response('not-json', 200)]);
        $this->expectException(IntegrationUnavailable::class);
        app(NokiaClient::class)->query('location', ['device' => ['phoneNumber' => '+99999991001']]);
    }

    public function test_verification_preserves_unknown_and_congestion_is_not_crowd_density(): void
    {
        Http::fake([
            '*/verify' => Http::response(['verificationResult' => 'UNKNOWN']),
            '*/query' => Http::response([['timeIntervalStart' => now()->toISOString(), 'timeIntervalStop' => now()->addMinutes(15)->toISOString(), 'congestionLevel' => 'High']]),
        ]);
        $network = app(NokiaNetwork::class);
        $this->assertSame('UNKNOWN', $network->verify('+99999991001', 33.9, 35.5, 100)['verificationResult']);
        $this->assertSame('High', $network->congestion('+99999991001')[0]['congestionLevel']);
    }
}
