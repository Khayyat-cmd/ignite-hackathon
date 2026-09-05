<?php

namespace Tests\Feature;

use App\Models\DomainEvent;
use App\Models\Incident;
use App\Models\SimulationRun;
use App\Services\Simulation\EventSimulation;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class IncidentAdviceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'aman.demo_enabled' => true,
            'aman.reachability.key' => 'test',
            'aman.reachability.enabled' => true,
            'services.openai.key' => 'test-openai-key',
            'services.openai.base_url' => 'https://api.openai.test/v1',
            'services.openai.model' => 'gpt-5.6-luna',
        ]);
        $this->travelTo(CarbonImmutable::parse('2026-09-05T12:00:00Z'));
        Http::preventStrayRequests();
        Http::fake(function (Request $request) {
            if (str_contains($request->url(), 'api.openai.test')) {
                $snapshot = json_decode($request['input'], true, flags: JSON_THROW_ON_ERROR);
                $responderId = $snapshot['deterministicRecommendation'];

                return Http::response([
                    'id' => 'resp_demo_advice',
                    'output' => [['type' => 'message', 'content' => [[
                        'type' => 'output_text',
                        'text' => json_encode([
                            'summary' => 'Crowd density is above the critical threshold at the East Entrance.',
                            'urgency' => 'critical',
                            'confidence' => 'high',
                            'recommendedResponderId' => $responderId,
                            'evidence' => ['The latest density exceeds the configured critical threshold.'],
                            'uncertainties' => ['Counts are based on simulated devices.'],
                            'proposedAction' => 'Review the route and dispatch the nearest eligible marshal.',
                        ], JSON_THROW_ON_ERROR),
                    ]]]],
                    'usage' => ['input_tokens' => 600, 'output_tokens' => 120],
                ]);
            }

            return Http::response([
                'reachable' => $request['device']['phoneNumber'] !== '+99999991003',
                'connectivity' => $request['device']['phoneNumber'] === '+99999991003' ? [] : ['DATA'],
                'lastStatusTime' => now()->toISOString(),
            ]);
        });
    }

    public function test_an_incident_receives_validated_ai_advice_without_dispatching(): void
    {
        $runId = $this->postJson('/api/v1/demo/simulations', ['attendeeCount' => 6000])->assertCreated()->json('id');
        $run = SimulationRun::findOrFail($runId);
        $this->postJson("/api/v1/demo/simulations/{$run->id}/control", ['action' => 'start'])->assertOk();

        for ($tick = 0; $tick < 13; $tick++) {
            $this->travel(5)->seconds();
            app(EventSimulation::class)->advance($run->id);
        }

        $incident = Incident::where('venue_event_id', $run->venue_event_id)->firstOrFail();
        $this->assertSame('ready', data_get($incident->decision, 'adviceStatus'));
        $this->assertSame($incident->responder_id, data_get($incident->decision, 'advice.recommendedResponderId'));
        $this->assertSame('gpt-5.6-luna', data_get($incident->decision, 'advice.model'));
        $this->assertSame('awaiting_approval', $incident->status->value);
        $this->assertNull($incident->assigned_responder_id);
        $this->assertSame(1, DomainEvent::where('type', 'incident_advice_ready')->count());
        $alternate = collect($incident->decision['candidates'])->firstWhere('responderId', '!=', $incident->responder_id)['responderId'];
        $this->postJson("/api/v1/demo/incidents/{$incident->id}/approve", [
            'routeReviewed' => true,
            'responderId' => (string) Str::uuid(),
        ])->assertUnprocessable();
        $this->postJson("/api/v1/demo/incidents/{$incident->id}/approve", [
            'routeReviewed' => true,
            'responderId' => $alternate,
        ])->assertOk();
        $this->assertSame($alternate, $incident->fresh()->assigned_responder_id);
        $this->assertFalse(data_get($incident->fresh()->decision, 'operatorReview.advisorAccepted'));
        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), 'api.openai.test')
            && $request['store'] === false
            && data_get($request->data(), 'text.format.type') === 'json_schema');
    }
}
