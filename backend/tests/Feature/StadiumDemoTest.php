<?php

namespace Tests\Feature;

use App\Jobs\RefreshResponder;
use App\Models\Incident;
use App\Models\Responder;
use App\Models\User;
use App\Models\Zone;
use App\Services\ResponderSignals;
use App\Services\StadiumDemo;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class StadiumDemoTest extends TestCase
{
    use RefreshDatabase;

    private User $operator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->freezeTime();
        config(['aman.demo_enabled' => true, 'camara.mode' => 'sandbox', 'camara.api_key' => 'test-key',
            'camara.base_url' => 'https://nokia.test', 'population.provider' => 'orange_playground',
            'population.orange.client_id' => 'test', 'population.orange.client_secret' => 'secret',
            'population.orange.token_url' => 'https://orange.test/token',
            'population.orange.api_url' => 'https://orange.test/density']);
        $this->operator = User::factory()->create();
        Sanctum::actingAs($this->operator, ['read', 'operate', 'ingest']);
        Http::preventStrayRequests();
    }

    private function setupDemo(): array
    {
        $this->postJson('/api/v1/demo/setup')->assertOk()->assertJsonCount(3, 'zones')->assertJsonCount(4, 'responders');

        return [Zone::whereNotNull('demo_key')->orderBy('created_at')->first(), Responder::where('demo_key', StadiumDemo::VENUE.':responder:0')->first()];
    }

    private function fakeProviders(bool $reachable = true): void
    {
        Http::fake(function ($request) use ($reachable) {
            if (str_contains($request->url(), 'orange.test/token')) {
                return Http::response(['access_token' => 'temporary', 'expires_in' => 3600]);
            }
            if (str_contains($request->url(), 'orange.test/density')) {
                return Http::response(['status' => 'SUPPORTED_AREA', 'timedPopulationDensityData' => [['cellPopulationDensityData' => [['pplDensity' => 123]]]]]);
            }
            if (str_contains($request->url(), 'location-retrieval')) {
                return Http::response(['lastLocationTime' => now()->toISOString(), 'area' => ['areaType' => 'CIRCLE', 'center' => ['latitude' => 47.486276, 'longitude' => 19.079156], 'radius' => 1000]]);
            }
            if (str_contains($request->url(), 'location-verification')) {
                return Http::response(['verificationResult' => 'TRUE']);
            }
            if (str_contains($request->url(), 'reachability')) {
                return Http::response(['reachable' => $reachable, 'connectivity' => $reachable ? ['DATA'] : [], 'lastStatusTime' => now()->toISOString()]);
            }

            return Http::response([['congestionLevel' => 'High', 'confidenceLevel' => 60, 'timeIntervalStart' => now()->subMinutes(5)->toISOString(), 'timeIntervalStop' => now()->toISOString()]]);
        });
    }

    private function trigger(Zone $zone): Incident
    {
        $this->postJson('/api/v1/demo/zones/'.$zone->id.'/scenario', ['action' => 'crowded'])->assertOk();
        for ($i = 0; $i < 8; $i++) {
            $this->travel(5)->seconds();
            app(StadiumDemo::class)->tick();
        }

        return Incident::where('active_zone_id', $zone->id)->firstOrFail();
    }

    public function test_setup_is_idempotent_and_does_not_fabricate_network_evidence(): void
    {
        [$zone, $responder] = $this->setupDemo();
        $this->postJson('/api/v1/demo/setup')->assertOk();
        $this->assertSame(3, Zone::count());
        $this->assertSame(4, Responder::count());
        $this->assertCount(4, $zone->boundary);
        $this->assertNull($responder->signals);
        $this->assertSame('simulated_stadium_position', $responder->demo_position['source']);
        Http::assertNothingSent();
    }

    public function test_full_demo_uses_nokia_evidence_orange_context_and_scoped_responder_acknowledgement(): void
    {
        [$zone, $responder] = $this->setupDemo();
        $this->fakeProviders();
        $this->postJson('/api/v1/responders/'.$responder->id.'/refresh')->assertAccepted();
        $this->assertSame(1000.0, (float) $responder->fresh()->signals['location']['accuracyMeters']);
        $this->assertSame('TRUE', $responder->fresh()->signals['verification']['verificationResult']);
        $this->postJson('/api/v1/demo/zones/'.$zone->id.'/population/refresh')->assertAccepted();
        $this->assertSame('received', $zone->fresh()->population_context['state']);
        $this->assertNull($zone->fresh()->latest_reading);
        $incident = $this->trigger($zone);
        $base = '/api/v1/incidents/'.$incident->id;
        $this->postJson($base.'/recommend')->assertOk()
            ->assertJsonPath('responder_id', $responder->id)
            ->assertJsonPath('decision.method', 'stadium_demo_simulated_distance_nokia_reachability')
            ->assertJsonPath('decision.candidates.0.locationSource', 'simulated_stadium_position')
            ->assertJsonPath('decision.candidates.0.reachabilitySource', 'nokia_sandbox');
        $this->postJson($base.'/approve', ['routeReviewed' => true])->assertOk();
        $account = User::factory()->create();
        $account->forceFill(['responder_id' => $responder->id])->save();
        Sanctum::actingAs($account, ['respond']);
        $this->getJson('/api/v1/missions')->assertOk()->assertJsonPath('data.0.destination.zoneId', $zone->id);
        $this->getJson('/api/v1/responders')->assertForbidden();
        $this->postJson('/api/v1/missions/'.$incident->id.'/acknowledge')->assertOk()->assertJsonPath('status', 'acknowledged');
        Sanctum::actingAs($this->operator, ['read', 'operate']);
        $this->postJson('/api/v1/demo/zones/'.$zone->id.'/scenario', ['action' => 'recover'])->assertOk();
        for ($i = 0; $i < 8; $i++) {
            $this->travel(5)->seconds();
            app(StadiumDemo::class)->tick();
        }
        $this->postJson($base.'/resolve', ['note' => 'Operator confirmed demo recovery.'])->assertOk()->assertJsonPath('status', 'resolved');
        $this->assertTrue($responder->fresh()->available);
        Http::assertSent(fn ($request) => str_contains($request->url(), 'orange.test/density') && $request['area']['boundary'] === $zone->boundary);
        $this->getJson('/api/v1/events')->assertOk()->assertSee('population_updated')->assertSee('response_acknowledged');
    }

    public function test_demo_controls_are_blocked_without_permissions_or_in_production_or_live_mode(): void
    {
        config(['aman.demo_enabled' => false]);
        $this->postJson('/api/v1/demo/setup')->assertForbidden();
        config(['aman.demo_enabled' => true, 'camara.mode' => 'live']);
        $this->postJson('/api/v1/demo/setup')->assertForbidden();
        config(['camara.mode' => 'sandbox']);
        $this->app->detectEnvironment(fn () => 'production');
        $this->postJson('/api/v1/demo/setup')->assertForbidden();
        $this->app->detectEnvironment(fn () => 'testing');
        Sanctum::actingAs($this->operator, ['read']);
        $this->postJson('/api/v1/demo/setup')->assertForbidden();
    }

    public function test_provider_failure_never_becomes_fake_reachability_and_blocks_selection(): void
    {
        [$zone, $responder] = $this->setupDemo();
        Http::fake(['*' => Http::response([], 503)]);
        app(ResponderSignals::class)->refresh($responder, $this->operator->id);
        $this->assertNull($responder->fresh()->signals['reachability']);
        $incident = $this->trigger($zone);
        $this->postJson('/api/v1/incidents/'.$incident->id.'/recommend')->assertOk()->assertJsonPath('responder_id', null);
        $this->postJson('/api/v1/incidents/'.$incident->id.'/approve', ['routeReviewed' => true])->assertConflict();
    }

    public function test_stale_nokia_evidence_blocks_approval_even_with_a_simulated_position(): void
    {
        [$zone, $responder] = $this->setupDemo();
        $this->fakeProviders();
        app(ResponderSignals::class)->refresh($responder, $this->operator->id);
        $incident = $this->trigger($zone);
        $this->postJson('/api/v1/incidents/'.$incident->id.'/recommend')->assertOk()->assertJsonPath('responder_id', $responder->id);
        $this->travel(3)->minutes();
        app(StadiumDemo::class)->tick();
        $this->postJson('/api/v1/incidents/'.$incident->id.'/approve', ['routeReviewed' => true])->assertConflict();
    }

    public function test_another_responder_cannot_read_or_acknowledge_the_mission(): void
    {
        [$zone, $responder] = $this->setupDemo();
        $this->fakeProviders();
        app(ResponderSignals::class)->refresh($responder, $this->operator->id);
        $incident = $this->trigger($zone);
        $this->postJson('/api/v1/incidents/'.$incident->id.'/recommend')->assertOk();
        $this->postJson('/api/v1/incidents/'.$incident->id.'/approve', ['routeReviewed' => true])->assertOk();
        $other = User::factory()->create();
        $other->forceFill(['responder_id' => Responder::where('id', '!=', $responder->id)->first()->id])->save();
        Sanctum::actingAs($other, ['respond']);
        $this->getJson('/api/v1/missions')->assertOk()->assertJsonCount(0, 'data');
        $this->postJson('/api/v1/missions/'.$incident->id.'/acknowledge')->assertForbidden();
    }

    public function test_pause_stops_updates_and_expired_scenarios_stop_automatically(): void
    {
        [$zone] = $this->setupDemo();
        $this->postJson('/api/v1/demo/zones/'.$zone->id.'/scenario', ['action' => 'calm'])->assertOk();
        $this->postJson('/api/v1/demo/zones/'.$zone->id.'/scenario', ['action' => 'pause'])->assertOk();
        $before = $zone->fresh()->last_observed_at;
        $this->travel(10)->seconds();
        app(StadiumDemo::class)->tick();
        $this->assertTrue($before->eq($zone->fresh()->last_observed_at));
        $this->postJson('/api/v1/demo/zones/'.$zone->id.'/scenario', ['action' => 'crowded'])->assertOk();
        $this->travel(31)->minutes();
        app(StadiumDemo::class)->tick();
        $this->assertFalse($zone->fresh()->scenario['active']);
    }

    public function test_orange_failure_is_reported_without_modifying_crowd_risk(): void
    {
        [$zone] = $this->setupDemo();
        Http::fake(['*' => Http::response([], 429)]);
        $this->postJson('/api/v1/demo/zones/'.$zone->id.'/population/refresh')->assertAccepted();
        $this->assertSame('unavailable', $zone->fresh()->population_context['state']);
        $this->assertNull($zone->fresh()->latest_reading);
    }

    public function test_roster_refresh_dispatches_only_stadium_responders(): void
    {
        $this->setupDemo();
        Queue::fake();
        $this->postJson('/api/v1/demo/network/refresh')->assertAccepted();
        Queue::assertPushed(RefreshResponder::class, 4);
    }

    public function test_recent_orange_context_is_reused_without_another_provider_call(): void
    {
        [$zone] = $this->setupDemo();
        $this->fakeProviders();
        $this->postJson('/api/v1/demo/zones/'.$zone->id.'/population/refresh')->assertAccepted();
        $this->postJson('/api/v1/demo/zones/'.$zone->id.'/population/refresh')->assertOk()->assertJsonPath('status', 'cached');
        Http::assertSentCount(2);
    }

    public function test_unreachable_nokia_device_is_not_selected_despite_a_nearby_demo_position(): void
    {
        [$zone, $responder] = $this->setupDemo();
        $this->fakeProviders(false);
        app(ResponderSignals::class)->refresh($responder, $this->operator->id);
        $incident = $this->trigger($zone);
        $this->postJson('/api/v1/incidents/'.$incident->id.'/recommend')->assertOk()->assertJsonPath('responder_id', null);
    }

    public function test_stadium_scenario_rejects_arbitrary_zones_and_unknown_actions(): void
    {
        [$zone] = $this->setupDemo();
        $this->postJson('/api/v1/demo/zones/'.$zone->id.'/scenario', ['action' => 'invalid'])->assertUnprocessable();
        $zone->forceFill(['demo_key' => null])->save();
        $this->postJson('/api/v1/demo/zones/'.$zone->id.'/scenario', ['action' => 'crowded'])->assertUnprocessable();
    }

    public function test_overdue_acknowledgement_is_reported_without_faking_responder_confirmation(): void
    {
        [$zone, $responder] = $this->setupDemo();
        $this->fakeProviders();
        app(ResponderSignals::class)->refresh($responder, $this->operator->id);
        $incident = $this->trigger($zone);
        $this->postJson('/api/v1/incidents/'.$incident->id.'/recommend')->assertOk();
        $this->postJson('/api/v1/incidents/'.$incident->id.'/approve', ['routeReviewed' => true])->assertOk();
        $this->travel(61)->seconds();
        $this->getJson('/api/v1/incidents')->assertOk()->assertJsonPath('data.0.acknowledgementOverdue', true)->assertJsonPath('data.0.status', 'dispatched');
    }

    public function test_live_mode_does_not_bypass_network_location_accuracy(): void
    {
        [$zone, $responder] = $this->setupDemo();
        $this->fakeProviders();
        app(ResponderSignals::class)->refresh($responder, $this->operator->id);
        $incident = $this->trigger($zone);
        config(['camara.mode' => 'live']);
        $this->postJson('/api/v1/incidents/'.$incident->id.'/recommend')->assertOk()->assertJsonPath('responder_id', null);
    }

    public function test_a_responder_token_can_only_be_created_for_a_dedicated_account(): void
    {
        [, $responder] = $this->setupDemo();
        $this->artisan('aman:token', ['email' => 'responder@example.test', '--abilities' => 'respond', '--responder' => $responder->id])->assertSuccessful();
        $this->assertSame($responder->id, User::where('email', 'responder@example.test')->first()->responder_id);
        $this->artisan('aman:token', ['email' => 'responder@example.test', '--abilities' => 'operate'])->assertFailed();
        $this->artisan('aman:token', ['email' => $this->operator->email, '--abilities' => 'respond', '--responder' => $responder->id])->assertFailed();
    }
}
