<?php

namespace Tests\Feature;

use App\Models\DomainEvent;
use App\Models\SimulationRun;
use App\Models\Zone;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class UnityFocusTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['aman.demo_enabled' => true]);
        config(['aman.reachability.key' => 'test', 'aman.reachability.enabled' => true]);
        config(['services.openai.key' => null]);
        Http::preventStrayRequests();
        Http::fake(fn ($request) => Http::response([
            'reachable' => true,
            'connectivity' => ['DATA'],
            'lastStatusTime' => now()->toISOString(),
        ]));
        $this->travelTo(CarbonImmutable::parse('2026-09-08T12:00:00Z'));
    }

    public function test_the_operator_focus_reaches_unity_as_a_camera_target(): void
    {
        $runId = $this->postJson('/api/v1/demo/simulations', ['attendeeCount' => 600])
            ->assertCreated()->json('id');
        $run = SimulationRun::findOrFail($runId);
        $zone = Zone::where('venue_event_id', $run->venue_event_id)->firstOrFail();

        $response = $this->postJson("/api/v1/demo/simulations/{$run->id}/focus", ['zoneId' => $zone->id])
            ->assertOk();
        $this->assertSame($zone->id, $response->json('focus.zoneId'));
        $this->assertSame(1, $response->json('focus.sequence'));
        $this->assertSame('metres', $response->json('focus.camera.units'));
        $this->assertNotNull($response->json('focus.camera.x'));
        $this->assertNotNull($response->json('focus.camera.z'));
        $this->assertEqualsWithDelta($zone->area_sqm,
            $response->json('focus.camera.width') * $response->json('focus.camera.depth'), 0.001);

        $unity = $this->getJson("/api/v1/demo/simulations/{$run->id}?client=unity&limit=1")->assertOk();
        $this->assertSame($zone->id, $unity->json('focus.zoneId'));
        $this->assertSame($zone->name, $unity->json('focus.zoneName'));
        $this->assertSame(1, DomainEvent::where('type', 'zone_focused')->count());
    }

    public function test_reselecting_the_same_zone_does_not_advance_the_sequence(): void
    {
        $runId = $this->postJson('/api/v1/demo/simulations', ['attendeeCount' => 600])
            ->assertCreated()->json('id');
        $run = SimulationRun::findOrFail($runId);
        $zone = Zone::where('venue_event_id', $run->venue_event_id)->firstOrFail();

        $this->postJson("/api/v1/demo/simulations/{$run->id}/focus", ['zoneId' => $zone->id])->assertOk();
        $this->postJson("/api/v1/demo/simulations/{$run->id}/focus", ['zoneId' => $zone->id])
            ->assertOk()->assertJsonPath('focus.sequence', 1);
        $this->assertSame(1, DomainEvent::where('type', 'zone_focused')->count());

        // Clearing the selection is Unity's cue to frame the whole venue again.
        $this->postJson("/api/v1/demo/simulations/{$run->id}/focus", ['zoneId' => null])
            ->assertOk()
            ->assertJsonPath('focus.zoneId', null)
            ->assertJsonPath('focus.camera', null)
            ->assertJsonPath('focus.sequence', 2);
    }

    public function test_a_zone_from_another_event_is_rejected(): void
    {
        $runId = $this->postJson('/api/v1/demo/simulations', ['attendeeCount' => 600])
            ->assertCreated()->json('id');
        // Only one run may be active at a time, so the first is stopped before the second.
        $this->postJson("/api/v1/demo/simulations/{$runId}/control", ['action' => 'stop'])->assertOk();
        $otherRunId = $this->postJson('/api/v1/demo/simulations', ['attendeeCount' => 600])
            ->assertCreated()->json('id');
        $otherRun = SimulationRun::findOrFail($otherRunId);
        $foreignZone = Zone::where('venue_event_id', $otherRun->venue_event_id)->firstOrFail();

        $this->postJson("/api/v1/demo/simulations/{$runId}/focus", ['zoneId' => $foreignZone->id])
            ->assertNotFound();
        $this->postJson("/api/v1/demo/simulations/{$runId}/focus", ['zoneId' => Str::uuid()->toString()])
            ->assertNotFound();
        $this->postJson("/api/v1/demo/simulations/{$runId}/focus", [])->assertUnprocessable();
    }
}
