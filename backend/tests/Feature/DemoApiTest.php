<?php

namespace Tests\Feature;

use App\Models\Incident;
use App\Models\Responder;
use App\Models\SimulationRun;
use App\Services\Simulation\EventSimulation;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class DemoApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['aman.demo_enabled' => true, 'camara.mode' => 'disabled']);
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
        $this->postJson("/api/v1/demo/missions/{$incident->id}/acknowledge", ['responderId' => $responder->id])->assertOk();
        $this->postJson("/api/v1/demo/missions/{$incident->id}/messages", [
            'responderId' => $responder->id,
            'clientMessageId' => (string) Str::uuid(),
            'kind' => 'on_scene',
            'body' => 'On scene. Directing attendees to the exit.',
        ])->assertOk();

        $this->getJson("/api/v1/demo/simulations/{$run->id}?client=unity&limit=5")
            ->assertOk()->assertJsonCount(5, 'positions')
            ->assertJsonStructure(['responderPositions' => [['id', 'x', 'z', 'available', 'missionStatus']]]);
    }
}
