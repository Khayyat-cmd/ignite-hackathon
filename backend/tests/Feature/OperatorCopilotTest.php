<?php

namespace Tests\Feature;

use App\Models\Incident;
use App\Models\SimulationRun;
use App\Services\Simulation\EventSimulation;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class OperatorCopilotTest extends TestCase
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
            if (! str_contains($request->url(), 'api.openai.test')) {
                return Http::response([
                    'reachable' => $request['device']['phoneNumber'] !== '+99999991003',
                    'connectivity' => $request['device']['phoneNumber'] === '+99999991003' ? [] : ['DATA'],
                    'lastStatusTime' => now()->toISOString(),
                ]);
            }

            $input = json_decode($request['input'], true, flags: JSON_THROW_ON_ERROR);
            if (data_get($request->data(), 'text.format.name') === 'aman_incident_advice') {
                $body = [
                    'summary' => 'Critical crowd density needs review.',
                    'urgency' => 'critical',
                    'confidence' => 'high',
                    'recommendedResponderId' => $input['deterministicRecommendation'],
                    'evidence' => ['Density is above the critical threshold.'],
                    'uncertainties' => ['Positions are simulated.'],
                    'proposedAction' => 'Review the route and dispatch the recommended responder.',
                ];
            } else {
                $incident = $input['liveSituation']['activeIncidents'][0];
                $body = [
                    'answer' => 'The East Entrance needs immediate operator attention.',
                    'suggestion' => [
                        'incidentId' => $incident['id'],
                        'zoneId' => $incident['zoneId'],
                        'responderId' => $incident['eligibleCandidates'][0]['responderId'],
                        'reason' => 'This is the nearest eligible data-reachable responder.',
                    ],
                    'followUpPrompt' => 'Show me the evidence.',
                ];
            }

            return Http::response(['id' => 'resp_test', 'output' => [[
                'type' => 'message',
                'content' => [['type' => 'output_text', 'text' => json_encode($body, JSON_THROW_ON_ERROR)]],
            ]]]);
        });
    }

    public function test_operator_can_ask_luna_about_live_conditions_and_receive_a_reviewable_dispatch_proposal(): void
    {
        $runId = $this->postJson('/api/v1/demo/simulations', ['attendeeCount' => 6000])->assertCreated()->json('id');
        $run = SimulationRun::findOrFail($runId);
        $this->postJson("/api/v1/demo/simulations/{$run->id}/control", ['action' => 'start'])->assertOk();
        for ($tick = 0; $tick < 13; $tick++) {
            $this->travel(5)->seconds();
            app(EventSimulation::class)->advance($run->id);
        }
        $incident = Incident::where('venue_event_id', $run->venue_event_id)->firstOrFail();

        $this->postJson("/api/v1/demo/simulations/{$run->id}/copilot", [
            'question' => 'What needs attention and who should respond?',
            'history' => [['role' => 'user', 'content' => 'Give me a concise status.']],
        ])->assertOk()
            ->assertJsonPath('model', 'gpt-5.6-luna')
            ->assertJsonPath('suggestion.incidentId', $incident->id)
            ->assertJsonPath('suggestion.zoneId', $incident->zone_id)
            ->assertJsonPath('suggestion.responderId', data_get($incident->decision, 'candidates.0.responderId'));

        $this->assertNull($incident->fresh()->assigned_responder_id);
        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), 'api.openai.test')
            && data_get($request->data(), 'text.format.name') === 'aman_operator_copilot'
            && $request['store'] === false);
    }
}
