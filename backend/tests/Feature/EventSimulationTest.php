<?php

namespace Tests\Feature;

use App\Enums\IncidentStatus;
use App\Models\Incident;
use App\Models\Responder;
use App\Models\SimulationRun;
use App\Models\User;
use App\Models\Zone;
use App\Services\Camara\NokiaNetwork;
use App\Services\Simulation\EventSimulation;
use App\Services\Simulation\LocationProvider;
use App\Services\Simulation\ZoneLocator;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class EventSimulationTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private string $operatorToken;

    protected function setUp(): void
    {
        parent::setUp();
        config(['aman.demo_enabled' => true, 'camara.mode' => 'disabled']);
        Http::preventStrayRequests();
        $this->travelTo(CarbonImmutable::parse('2026-09-04T12:00:00Z'));
        $this->owner = User::factory()->create(['role' => 'owner']);
        $this->operatorToken = $this->owner->createToken('operator', ['read', 'operate', 'manage'])->plainTextToken;
        $this->withToken($this->operatorToken);
    }

    private function createRun(int $count = 3000): SimulationRun
    {
        $id = $this->postJson('/api/v1/simulations', ['attendeeCount' => $count])->assertCreated()->json('id');

        return SimulationRun::findOrFail($id);
    }

    private function tick(SimulationRun $run, int $times = 1): void
    {
        for ($i = 0; $i < $times; $i++) {
            $this->travel(5)->seconds();
            app(EventSimulation::class)->advance($run->id);
        }
    }

    public function test_simulation_defaults_to_six_thousand_attendees(): void
    {
        $id = $this->postJson('/api/v1/simulations')->assertCreated()->json('id');

        $this->assertSame(6000, SimulationRun::findOrFail($id)->attendee_count);
    }

    public function test_full_operator_and_mobile_flow_uses_only_local_network_data(): void
    {
        $run = $this->createRun(6000);
        $this->postJson("/api/v1/simulations/{$run->id}/control", ['action' => 'start'])->assertOk();
        $this->tick($run, 13);
        $eastZone = Zone::where('venue_event_id', $run->venue_event_id)->where('name', 'East Entrance')->firstOrFail();
        $southZone = Zone::where('venue_event_id', $run->venue_event_id)->where('name', 'South Concourse')->firstOrFail();
        $incident = Incident::where('venue_event_id', $run->venue_event_id)->where('zone_id', $eastZone->id)->firstOrFail();
        $this->assertSame(IncidentStatus::AwaitingApproval, $incident->status);
        $this->assertSame('critical', $eastZone->risk_level);
        $this->assertNotSame('critical', $southZone->risk_level);
        $east = Responder::where('venue_event_id', $run->venue_event_id)->where('name', 'East Marshal')->firstOrFail();
        $this->assertSame('High', $east->signals['congestion'][0]['congestionLevel']);

        $this->postJson("/api/v1/incidents/{$incident->id}/approve", ['routeReviewed' => true])->assertOk();
        $this->assertFalse(Responder::findOrFail($incident->responder_id)->available);
        $assigned = Responder::findOrFail($incident->responder_id);
        $beforeDispatchMove = $assigned->signals['simulationPoint'];
        $this->tick($run);
        $afterDispatchMove = $assigned->fresh()->signals['simulationPoint'];
        $this->assertNotSame($beforeDispatchMove, $afterDispatchMove);
        $mobileUser = User::factory()->create(['organization_id' => $this->owner->organization_id, 'role' => 'responder']);
        $this->postJson("/api/v1/responders/{$incident->responder_id}/account", ['userId' => $mobileUser->id])->assertOk();
        $messageId = (string) Str::uuid();
        $instruction = ['clientMessageId' => $messageId, 'kind' => 'instruction', 'body' => 'Open the west exit and guide attendees away from the entrance.'];
        $this->postJson("/api/v1/missions/{$incident->id}/messages", $instruction)->assertOk()->assertJsonPath('duplicate', false);
        $this->postJson("/api/v1/missions/{$incident->id}/messages", $instruction)->assertOk()->assertJsonPath('duplicate', true);
        $this->withToken($mobileUser->createToken('mobile', ['respond'])->plainTextToken);
        $this->app['auth']->forgetGuards();
        $this->getJson('/api/v1/missions')->assertOk()->assertJsonPath('data.0.id', $incident->id);
        $this->getJson("/api/v1/missions/{$incident->id}/messages")->assertOk()->assertSee('Open the west exit');
        $this->postJson("/api/v1/missions/{$incident->id}/acknowledge")->assertOk();
        $this->postJson("/api/v1/missions/{$incident->id}/messages", ['clientMessageId' => (string) Str::uuid(), 'kind' => 'on_scene', 'body' => 'On scene. Opening exit.'])->assertOk();
        $this->tick($run);
        $eastCenter = ['x' => -15.0, 'y' => 0.0];
        $this->assertEquals($eastCenter, Responder::findOrFail($incident->responder_id)->signals['simulationPoint']);
        $this->tick($run, 16);
        $snapshot = $run->fresh()->snapshot;
        $northZone = Zone::where('venue_event_id', $run->venue_event_id)->where('name', 'North Concourse')->firstOrFail();
        $this->assertSame('east_recovered', $snapshot['phase']);
        $this->assertLessThan(100, $snapshot['zoneCounts'][$eastZone->id]);
        $this->assertGreaterThan(900, $snapshot['zoneCounts'][$northZone->id]);
        $this->assertGreaterThan(700, $snapshot['zoneCounts'][$southZone->id]);
        $this->assertLessThan(200, $snapshot['quality']['outside']);
        $this->assertSame('normal', $eastZone->fresh()->risk_level);
        $this->assertSame('critical', $southZone->fresh()->risk_level);
        $this->assertNotNull(Incident::where('venue_event_id', $run->venue_event_id)->where('zone_id', $southZone->id)->whereNotNull('active_zone_id')->first());
        $this->tick($run, 3);
        $this->withToken($this->operatorToken);
        $this->app['auth']->forgetGuards();
        $this->postJson("/api/v1/incidents/{$incident->id}/resolve", ['note' => 'Exit open and crowd dispersed.'])->assertOk();
        $this->assertSame(IncidentStatus::Resolved, $incident->fresh()->status);
        $this->assertTrue(Responder::find($incident->responder_id)->available);
        $southIncident = Incident::where('venue_event_id', $run->venue_event_id)->where('zone_id', $southZone->id)->whereNotNull('active_zone_id')->firstOrFail();
        $this->assertSame(IncidentStatus::AwaitingApproval, $southIncident->status);
        $this->postJson("/api/v1/incidents/{$southIncident->id}/approve", ['routeReviewed' => true])->assertOk();
        $southIncident->refresh();
        $southResponder = Responder::findOrFail($southIncident->assigned_responder_id);
        $southMobile = User::factory()->create(['organization_id' => $this->owner->organization_id, 'role' => 'responder']);
        $this->postJson("/api/v1/responders/{$southResponder->id}/account", ['userId' => $southMobile->id])->assertOk();
        $this->withToken($southMobile->createToken('mobile', ['respond'])->plainTextToken);
        $this->app['auth']->forgetGuards();
        $this->postJson("/api/v1/missions/{$southIncident->id}/acknowledge")->assertOk();
        $this->postJson("/api/v1/missions/{$southIncident->id}/messages", ['clientMessageId' => (string) Str::uuid(), 'kind' => 'on_scene', 'body' => 'On scene at South Concourse. Redirecting the overflow.'])->assertOk();
        $this->tick($run, 11);
        $this->assertSame('south_recovered', $run->fresh()->snapshot['phase']);
        $this->assertSame('normal', $southZone->fresh()->risk_level);
        $this->withToken($this->operatorToken);
        $this->app['auth']->forgetGuards();
        $this->tick($run, 3);
        $this->postJson("/api/v1/incidents/{$southIncident->id}/resolve")->assertOk();
        $this->assertTrue($southResponder->fresh()->available);
        Http::assertNothingSent();
    }

    public function test_snapshot_is_paged_anonymous_and_isolated_and_old_revisions_are_rejected(): void
    {
        $run = $this->createRun();
        $this->getJson('/api/v1/integrations')->assertOk()->assertJsonPath('simulatedNetwork.enabled', true)->assertJsonPath('simulatedNetwork.externalRequests', 0);
        $this->getJson("/api/v1/simulations/{$run->id}?client=unity&limit=50")->assertOk()
            ->assertJsonCount(50, 'positions')->assertJsonCount(4, 'responderPositions')
            ->assertJsonStructure(['responderPositions' => [['id', 'x', 'z', 'available', 'missionStatus']]])
            ->assertJsonPath('nextOffset', 50)->assertDontSee('phoneNumber');
        $this->postJson("/api/v1/simulations/{$run->id}/control", ['action' => 'start'])->assertOk();
        $this->tick($run);
        $this->getJson("/api/v1/simulations/{$run->id}?client=unity&revision=1")->assertConflict();
        $other = User::factory()->create();
        $this->withToken($other->createToken('other', ['read', 'operate'])->plainTextToken);
        $this->app['auth']->forgetGuards();
        $this->getJson("/api/v1/simulations/{$run->id}")->assertNotFound();
        $this->postJson("/api/v1/simulations/{$run->id}/control", ['action' => 'reset'])->assertNotFound();
    }

    public function test_pause_duplicate_ticks_and_reset_preserve_history(): void
    {
        $run = $this->createRun();
        $this->postJson('/api/v1/simulations')->assertConflict();
        app(EventSimulation::class)->advance($run->id);
        $this->assertSame(1, $run->fresh()->revision);
        $this->postJson("/api/v1/simulations/{$run->id}/control", ['action' => 'start'])->assertOk();
        $this->tick($run);
        app(EventSimulation::class)->advance($run->id);
        $this->assertSame(2, $run->fresh()->revision);
        $this->postJson("/api/v1/simulations/{$run->id}/control", ['action' => 'pause'])->assertOk();
        $this->tick($run);
        $this->assertSame(5, $run->fresh()->elapsed_seconds);
        $next = $this->postJson("/api/v1/simulations/{$run->id}/control", ['action' => 'reset'])->assertOk()->json('id');
        $this->assertNotEquals($run->id, $next);
        $this->assertSame('stopped', $run->fresh()->status);
        $this->assertSame(8, Zone::count());
    }

    public function test_missing_stale_and_ambiguous_locations_are_not_counted(): void
    {
        $run = $this->createRun();
        $this->postJson("/api/v1/simulations/{$run->id}/control", ['action' => 'start'])->assertOk();
        $this->tick($run, 8);
        $snapshot = $run->fresh()->snapshot;
        foreach (['missing', 'stale', 'ambiguous'] as $kind) {
            $this->assertGreaterThan(0, $snapshot['quality'][$kind]);
        }
        $this->assertSame([], Zone::where('venue_event_id', $run->venue_event_id)->where('risk_level', 'unknown')->pluck('id')->all());
        $this->assertSame(3000, array_sum($snapshot['quality']));
        $this->assertSame($snapshot['quality']['located'], array_sum($snapshot['zoneCounts']));
        $this->tick($run, 5);
        $this->assertSame(0, $run->fresh()->snapshot['quality']['missing']);
    }

    public function test_provider_contract_and_boundaries(): void
    {
        $run = $this->createRun(100);
        $provider = app(LocationProvider::class);
        $this->postJson("/api/v1/simulations/{$run->id}/location-retrieval/v0/retrieve",
            ['device' => ['phoneNumber' => $provider->phone(1)], 'maxAge' => 5])
            ->assertOk()->assertJsonStructure(['lastLocationTime', 'area' => ['areaType', 'center' => ['latitude', 'longitude'], 'radius']]);
        $this->postJson("/api/v1/simulations/{$run->id}/location-retrieval/v0/retrieve",
            ['device' => ['phoneNumber' => '+12345']])->assertNotFound();
        $body = ['lastLocationTime' => now()->toISOString(), 'area' => ['areaType' => 'CIRCLE',
            'center' => $provider->coordinates($run->definition, 0, 0), 'radius' => 1]];
        $result = app(ZoneLocator::class)->classify(['status' => 200, 'body' => $body], $run->definition, CarbonImmutable::now());
        $this->assertSame('ambiguous', $result['status']);
        $this->assertNull($result['zoneKey']);
    }

    public function test_demo_gate_and_mobile_permissions(): void
    {
        config(['aman.demo_enabled' => false]);
        $this->postJson('/api/v1/simulations')->assertForbidden();
        config(['aman.demo_enabled' => true]);
        $run = $this->createRun(6000);
        $this->postJson("/api/v1/simulations/{$run->id}/control", ['action' => 'start'])->assertOk();
        $this->tick($run, 13);
        $incident = Incident::firstOrFail();
        $unassigned = User::factory()->create(['organization_id' => $this->owner->organization_id, 'role' => 'responder']);
        $this->withToken($unassigned->createToken('mobile', ['respond'])->plainTextToken);
        $this->app['auth']->forgetGuards();
        $this->getJson("/api/v1/missions/{$incident->id}/messages")->assertForbidden();
        $this->postJson("/api/v1/simulations/{$run->id}/control", ['action' => 'pause'])->assertForbidden();
    }

    public function test_south_concourse_has_a_safe_baseline_before_redirection(): void
    {
        $run = $this->createRun();
        $south = Zone::where('venue_event_id', $run->venue_event_id)->where('name', 'South Concourse')->firstOrFail();
        $this->assertCount(4, $run->definition['zones']);
        $this->assertGreaterThan(500, $run->snapshot['zoneCounts'][$south->id]);
        $this->assertLessThan(700, $run->snapshot['zoneCounts'][$south->id]);
        $initial = collect($run->snapshot['positions'])->firstWhere('zoneId', $south->id);
        $this->postJson("/api/v1/simulations/{$run->id}/control", ['action' => 'start'])->assertOk();
        $this->tick($run, 13);
        $snapshot = $run->fresh()->snapshot;
        $moved = collect($snapshot['positions'])->firstWhere('id', $initial['id']);
        $this->assertSame($south->id, $moved['zoneId']);
        $this->assertSame($initial['x'], $moved['x']);
        $this->assertGreaterThan(500, $snapshot['zoneCounts'][$south->id]);
        $this->assertLessThan(700, $snapshot['zoneCounts'][$south->id]);
        $this->assertSame(3000, array_sum($snapshot['quality']));
        $this->assertSame($snapshot['quality']['located'], array_sum($snapshot['zoneCounts']));
    }

    public function test_ten_thousand_attendees_have_unique_ids_and_conserved_counts(): void
    {
        $run = $this->createRun(10000);
        $snapshot = $run->snapshot;
        $this->assertCount(10000, array_unique(array_column($snapshot['positions'], 'id')));
        $this->assertSame(10000, array_sum($snapshot['quality']));
        $this->assertSame(0, $snapshot['externalRequests']);
    }

    public function test_simulated_responder_responses_match_existing_nokia_parser(): void
    {
        $run = $this->createRun(100);
        $member = Responder::where('venue_event_id', $run->venue_event_id)->firstOrFail();
        $raw = $member->signals['rawResponses'];
        config(['camara.mode' => 'sandbox', 'camara.api_key' => 'fixture-key']);
        Http::fake([
            '*/location-retrieval/*' => Http::response($raw['location']),
            '*/device-status/*' => Http::response($raw['reachability']),
            '*/congestion-insights/*' => Http::response($raw['congestion']),
            '*/location-verification/*' => Http::response($raw['verification']),
        ]);
        $network = app(NokiaNetwork::class);
        $this->assertSame(1.0, $network->location('+99999991001')['accuracyMeters']);
        $this->assertTrue($network->reachability('+99999991001')['dataReachable']);
        $this->assertSame('Low', $network->congestion('+99999991001')[0]['congestionLevel']);
        $this->assertContains($network->verify('+99999991001', 33.9, 35.5, 14)['verificationResult'], ['TRUE', 'FALSE', 'PARTIAL']);
    }

    public function test_demo_mobile_access_expires_on_stop_and_cannot_impersonate_real_accounts(): void
    {
        $run = $this->createRun(100);
        $responder = Responder::where('venue_event_id', $run->venue_event_id)->firstOrFail();
        $token = $this->postJson("/api/v1/simulations/{$run->id}/responder-access", ['responderId' => $responder->id])
            ->assertOk()->json('token');
        $this->postJson("/api/v1/simulations/{$run->id}/control", ['action' => 'stop'])->assertOk();
        $this->withToken($token);
        $this->app['auth']->forgetGuards();
        $this->getJson('/api/v1/missions')->assertUnauthorized();
        $this->withToken($this->operatorToken);
        $this->app['auth']->forgetGuards();
        $next = $this->createRun(100);
        $responder = Responder::where('venue_event_id', $next->venue_event_id)->firstOrFail();
        User::factory()->create(['organization_id' => $this->owner->organization_id, 'role' => 'responder', 'responder_id' => $responder->id]);
        $this->postJson("/api/v1/simulations/{$next->id}/responder-access", ['responderId' => $responder->id])->assertConflict();
    }

    public function test_desktop_clients_have_independent_sessions(): void
    {
        $this->owner->update(['password' => 'demo-test-password']);
        $operator = $this->postJson('/api/v1/auth/login', ['email' => $this->owner->email, 'password' => 'demo-test-password', 'client' => 'operator'])
            ->assertOk()->json('token');
        $this->postJson('/api/v1/auth/login', ['email' => $this->owner->email, 'password' => 'demo-test-password', 'client' => 'unity'])->assertOk();
        $this->withToken($operator);
        $this->app['auth']->forgetGuards();
        $this->getJson('/api/v1/auth/me')->assertOk();
    }
}
