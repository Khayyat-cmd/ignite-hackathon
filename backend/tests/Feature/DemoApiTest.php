<?php

namespace Tests\Feature;

use App\Models\Incident;
use App\Models\Responder;
use App\Models\SimulationRun;
use App\Services\Simulation\EventSimulation;
use App\Services\Simulation\LocationProvider;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class DemoApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['aman.demo_enabled' => true, 'camara.mode' => 'disabled']);
        config(['aman.reachability.key' => 'test', 'aman.reachability.enabled' => true]);
        Http::preventStrayRequests();
        Http::fake(fn ($request) => Http::response([
            'reachable' => $request['device']['phoneNumber'] !== '+99999991003',
            'connectivity' => $request['device']['phoneNumber'] === '+99999991003' ? [] : ['DATA'],
            'lastStatusTime' => now()->toISOString(),
        ]));
        $this->travelTo(CarbonImmutable::parse('2026-09-04T12:00:00Z'));
    }

    public function test_the_local_demo_runs_without_login_or_tokens(): void
    {
        $runId = $this->postJson('/api/v1/demo/simulations', ['attendeeCount' => 6000])
            ->assertCreated()->json('id');
        $run = SimulationRun::findOrFail($runId);
        $this->postJson("/api/v1/demo/simulations/{$run->id}/control", ['action' => 'start'])->assertOk();

        for ($i = 0; $i < 13; $i++) {
            $this->travel(5)->seconds();
            app(EventSimulation::class)->advance($run->id);
        }

        $incident = Incident::where('venue_event_id', $run->venue_event_id)->firstOrFail();
        $this->postJson("/api/v1/demo/incidents/{$incident->id}/approve", ['routeReviewed' => true])->assertOk();
        $incident->refresh();
        $responder = Responder::findOrFail($incident->assigned_responder_id);
        $startingPoint = $responder->signals['simulationPoint'];
        $this->travel(5)->seconds();
        app(EventSimulation::class)->advance($run->id);
        $this->assertSame($startingPoint, $responder->fresh()->signals['simulationPoint']);
        $this->assertNull(data_get($incident->fresh()->decision, 'arrivalVerification'));
        $this->postJson("/api/v1/demo/missions/{$incident->id}/acknowledge", ['responderId' => $responder->id])->assertOk();
        $this->travel(5)->seconds();
        app(EventSimulation::class)->advance($run->id);
        $this->assertSame('TRUE', data_get($incident->fresh()->decision, 'arrivalVerification.verificationResult'));
        $this->assertNotSame($startingPoint, $responder->fresh()->signals['simulationPoint']);
        $this->assertEmpty($run->fresh()->interventions);
        $this->getJson('/api/v1/demo/missions?responderId='.$responder->id)->assertOk()
            ->assertJsonPath('data.0.arrivalVerification.verificationResult', 'TRUE')
            ->assertJsonPath('data.0.workStartedAt', null);
        $this->postJson("/api/v1/demo/missions/{$incident->id}/messages", [
            'responderId' => $responder->id,
            'clientMessageId' => (string) Str::uuid(),
            'kind' => 'on_scene',
            'body' => 'On scene. Directing attendees to the exit.',
        ])->assertOk();
        $this->assertSame($incident->zone_id, data_get($incident->fresh()->decision, 'arrivalVerification.zoneId'));

        $this->getJson("/api/v1/demo/simulations/{$run->id}?client=unity&limit=5")
            ->assertOk()->assertJsonCount(5, 'positions')
            ->assertJsonStructure(['responderPositions' => [['id', 'x', 'z', 'available', 'missionStatus']]]);
    }

    public function test_verification_uses_assigned_area_and_rejects_stale_evidence(): void
    {
        $provider = app(LocationProvider::class);
        $definition = ['origin' => ['latitude' => 33.9, 'longitude' => 35.5], 'zones' => [
            ['id' => 'east', 'bounds' => [-30, -15, 0, 15]],
            ['id' => 'south', 'bounds' => [15, -45, 65, -25]],
        ]];
        $location = ['lastLocationTime' => now()->toISOString(), 'area' => [
            'center' => $provider->coordinates($definition, 40, -35), 'radius' => 1]];
        $this->assertSame('TRUE', $provider->verifyAssignedArea($definition, 'south', $location)['verificationResult']);
        $this->assertSame('FALSE', $provider->verifyAssignedArea($definition, 'east', $location)['verificationResult']);
        $this->assertSame('UNKNOWN', $provider->verifyAssignedArea($definition, null, $location)['verificationResult']);
        $location['area']['center'] = $provider->coordinates($definition, 49, -35);
        $this->assertSame('PARTIAL', $provider->verifyAssignedArea($definition, 'south', $location)['verificationResult']);
        $this->travel(16)->seconds();
        $this->assertSame('UNKNOWN', $provider->verifyAssignedArea($definition, 'south', $location)['verificationResult']);
    }
}
