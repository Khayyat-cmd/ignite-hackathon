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
        // No advisor of either kind: this file covers the demo API alone.
        config(['services.openai.key' => null, 'aman.agent.url' => null]);
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
        $this->getJson('/api/v1/demo/responders')->assertOk()
            ->assertJsonCount(4, 'data')
            ->assertJsonStructure(['data' => [['id', 'name', 'role', 'available', 'event' => ['id', 'name', 'number', 'status']]]])
            ->assertJsonPath('data.0.event.number', $run->id)
            ->assertJsonMissingPath('data.0.phone_number');
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

    public function test_attendees_move_at_walking_speed_without_changing_the_position_shape(): void
    {
        $definition = json_decode(
            file_get_contents(resource_path('simulation/stadium.json')),
            true,
            512,
            JSON_THROW_ON_ERROR
        );
        $provider = app(LocationProvider::class);
        $attendeeCount = 1000;
        $sampleSeconds = 5;
        $maximumStep = $definition['maxWalkingSpeedMetersPerSecond'] * $sampleSeconds;
        $largestStep = 0.0;
        $zoneCountsAt = function (int $elapsed, array $interventions) use ($attendeeCount, $definition, $provider): array {
            $counts = array_fill_keys(array_column($definition['zones'], 'key'), 0);

            for ($index = 0; $index < $attendeeCount; $index++) {
                $point = $provider->point($definition, $index, $attendeeCount, $elapsed, $interventions);
                foreach ($definition['zones'] as $zone) {
                    [$left, $bottom, $right, $top] = $zone['bounds'];
                    if ($point['x'] > $left && $point['x'] < $right && $point['y'] > $bottom && $point['y'] < $top) {
                        $counts[$zone['key']]++;

                        break;
                    }
                }
            }

            return $counts;
        };

        for ($index = 0; $index < $attendeeCount; $index++) {
            $previous = $provider->point($definition, $index, $attendeeCount, 0);

            for ($elapsed = $sampleSeconds; $elapsed <= 220; $elapsed += $sampleSeconds) {
                $interventions = $elapsed >= 65 ? ['east' => 65] : [];
                if ($elapsed >= 150) {
                    $interventions['south'] = 150;
                }

                $current = $provider->point($definition, $index, $attendeeCount, $elapsed, $interventions);
                $largestStep = max($largestStep, hypot(
                    $current['x'] - $previous['x'],
                    $current['y'] - $previous['y']
                ));
                $previous = $current;
            }
        }

        $this->assertSame(['x', 'y'], array_keys($previous));
        $this->assertLessThanOrEqual($maximumStep + 0.001, $largestStep);

        $bottleneck = $zoneCountsAt(65, []);
        $eastRecovered = $zoneCountsAt(150, ['east' => 65]);
        $southRecovered = $zoneCountsAt(205, ['east' => 65, 'south' => 150]);
        $this->assertGreaterThan($bottleneck['north'], $bottleneck['east']);
        $this->assertGreaterThan($bottleneck['south'], $bottleneck['east']);
        $this->assertLessThan($bottleneck['east'], $eastRecovered['east']);
        $this->assertGreaterThan($bottleneck['south'], $eastRecovered['south']);
        $this->assertLessThan($eastRecovered['south'], $southRecovered['south']);

        $northCorridor = $provider->point($definition, 7, $attendeeCount, 100, ['east' => 65]);
        $southConcourse = $provider->point($definition, 7, $attendeeCount, 145, ['east' => 65]);
        $this->assertGreaterThan(0, $northCorridor['x']);
        $this->assertLessThan(80, $northCorridor['x']);
        $this->assertGreaterThan(-15, $northCorridor['y']);
        $this->assertLessThan(15, $northCorridor['y']);
        $this->assertGreaterThan(15, $southConcourse['x']);
        $this->assertLessThan(65, $southConcourse['x']);
        $this->assertGreaterThan(-45, $southConcourse['y']);
        $this->assertLessThan(-25, $southConcourse['y']);
    }
}
