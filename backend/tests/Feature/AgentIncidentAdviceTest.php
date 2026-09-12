<?php

namespace Tests\Feature;

use App\Jobs\GenerateIncidentAdvice;
use App\Models\Incident;
use App\Models\SimulationRun;
use App\Services\Simulation\EventSimulation;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class AgentIncidentAdviceTest extends TestCase
{
    use RefreshDatabase;

    private const AGENT_URL = 'http://127.0.0.1:8091';

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'aman.demo_enabled' => true,
            'aman.reachability.key' => 'test',
            'aman.reachability.enabled' => true,
            'aman.agent.url' => self::AGENT_URL,
            'aman.agent.token' => 'agent-service-token',
            'aman.agent.gateway_token' => 'gateway-token',
            // Deliberately absent: the agent holds the provider key, not Laravel.
            'services.openai.key' => null,
        ]);
        $this->travelTo(CarbonImmutable::parse('2026-09-12T12:00:00Z'));
        Http::preventStrayRequests();
    }

    /**
     * @param  callable(array<string, mixed>): array<string, mixed>  $advice
     */
    private function fakeServices(callable $advice): void
    {
        Http::fake(function (Request $request) use ($advice) {
            if (str_starts_with($request->url(), self::AGENT_URL)) {
                return Http::response($advice($request->data()));
            }

            return Http::response([
                'reachable' => $request['device']['phoneNumber'] !== '+99999991003',
                'connectivity' => $request['device']['phoneNumber'] === '+99999991003' ? [] : ['DATA'],
                'lastStatusTime' => now()->toISOString(),
            ]);
        });
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function completedRun(array $payload, array $overrides = []): array
    {
        // Deliberately choose a non-first candidate when available. This proves
        // the agent, rather than Laravel's distance ordering, owns the normal
        // recommendation.
        $responderId = ($payload['eligibleCandidates'][1] ?? $payload['eligibleCandidates'][0])['responderId'];

        return [
            'requestId' => $payload['requestId'],
            'incidentId' => $payload['incident']['id'],
            'agentStatus' => 'completed',
            'provider' => 'openai',
            'model' => 'gpt-5.6-luna',
            'agentRuntime' => 'openai-agents-python',
            'agentVersion' => '2.0.0',
            'evidenceMode' => 'simulated_fixture',
            'recommendedResponderId' => $responderId,
            'confidence' => 'medium',
            'summary' => 'Density is above the critical threshold and the nearest marshal is data reachable.',
            'evidenceSummary' => ['Device Reachability confirms mobile data for the nearest marshal.'],
            'uncertainty' => ['Location verification was partial.'],
            'proposedAction' => 'Dispatch the nearest data-reachable marshal after operator review.',
            'requiresHumanApproval' => true,
            'toolTrace' => [[
                'sequence' => 1,
                'tool' => 'device_reachability',
                'reason' => 'Confirm the nearest marshal can receive a dispatch.',
                'responderId' => $responderId,
                'provider' => 'nokia_network_as_code',
                'api' => 'device-status/device-reachability-status/v1',
                'source' => 'live_camara',
                'status' => 'ok',
                'checkedAt' => now()->toISOString(),
                'result' => ['dataReachable' => true],
            ], [
                'sequence' => 2,
                'tool' => 'congestion_insights',
                'reason' => 'The incident is critical, so communications quality matters.',
                'responderId' => null,
                'provider' => 'aman_venue_simulation',
                'api' => 'network-insights/congestion-insights/v0',
                'source' => 'simulated_fixture',
                'status' => 'ok',
                'checkedAt' => now()->toISOString(),
                'result' => ['congestionLevel' => 'high'],
            ]],
            'generatedAt' => now()->toISOString(),
            ...$overrides,
        ];
    }

    private function incidentAfterDetection(): Incident
    {
        $runId = $this->postJson('/api/v1/demo/simulations', ['attendeeCount' => 6000])->assertCreated()->json('id');
        $run = SimulationRun::findOrFail($runId);
        $this->postJson("/api/v1/demo/simulations/{$run->id}/control", ['action' => 'start'])->assertOk();
        for ($tick = 0; $tick < 13; $tick++) {
            $this->travel(5)->seconds();
            app(EventSimulation::class)->advance($run->id);
        }

        return Incident::where('venue_event_id', $run->venue_event_id)->firstOrFail();
    }

    public function test_advice_comes_from_the_orchestration_agent_with_its_camara_tool_trace(): void
    {
        $this->fakeServices(fn (array $payload): array => $this->completedRun($payload));

        $incident = $this->incidentAfterDetection();

        $this->assertSame('ready', data_get($incident->decision, 'adviceStatus'));
        $advice = data_get($incident->decision, 'advice');
        $this->assertSame('completed', $advice['agentStatus']);
        $this->assertSame('openai-agents-python', $advice['agentRuntime']);
        $this->assertSame('simulated_fixture', $advice['evidenceMode']);
        $this->assertSame($incident->responder_id, $advice['recommendedResponderId']);
        $this->assertNotSame(data_get($incident->decision, 'fallbackResponderId'), $incident->responder_id);
        $this->assertSame('agent', data_get($incident->decision, 'recommendationSource'));
        $this->assertCount(2, $advice['toolTrace']);
        $this->assertSame('device_reachability', $advice['toolTrace'][0]['tool']);
        // High congestion escalates a critical zone's urgency for the operator.
        $this->assertSame('critical', $advice['urgency']);
        $this->assertTrue($advice['requiresHumanApproval']);
        $this->assertNull($incident->assigned_responder_id);

        Http::assertSent(function (Request $request): bool {
            if (! str_starts_with($request->url(), self::AGENT_URL)) {
                return false;
            }

            $payload = $request->data();

            return $request->hasHeader('Authorization', 'Bearer agent-service-token')
                && count($payload['eligibleCandidates']) > 0
                && $payload['zone']['criticalThreshold'] > $payload['zone']['warningThreshold']
                && ! array_key_exists('deterministicRecommendation', $payload)
                && ! array_key_exists('fixtureEvidence', $payload);
        });
    }

    public function test_a_degraded_agent_run_leaves_the_deterministic_ranking_in_place(): void
    {
        $this->fakeServices(fn (array $payload): array => $this->completedRun($payload, [
            'agentStatus' => 'degraded',
            'recommendedResponderId' => null,
            'confidence' => 'low',
            'evidenceMode' => 'none',
            'toolTrace' => [],
        ]));

        $incident = $this->incidentAfterDetection();

        $advice = data_get($incident->decision, 'advice');
        $this->assertSame('degraded', $advice['agentStatus']);
        $this->assertNull($advice['recommendedResponderId']);
        $this->assertNotNull($incident->responder_id);
        $this->assertSame(data_get($incident->decision, 'fallbackResponderId'), $incident->responder_id);
        $this->assertSame('deterministic_fallback', data_get($incident->decision, 'recommendationSource'));
        $this->assertSame('awaiting_approval', $incident->status->value);
    }

    public function test_an_agent_recommendation_outside_the_candidate_list_is_rejected(): void
    {
        $this->fakeServices(fn (array $payload): array => $this->completedRun($payload, [
            'recommendedResponderId' => '11111111-2222-3333-4444-555555555555',
        ]));

        $runId = $this->postJson('/api/v1/demo/simulations', ['attendeeCount' => 6000])->assertCreated()->json('id');
        $run = SimulationRun::findOrFail($runId);
        $this->postJson("/api/v1/demo/simulations/{$run->id}/control", ['action' => 'start'])->assertOk();
        $rejected = false;
        for ($tick = 0; $tick < 13; $tick++) {
            $this->travel(5)->seconds();
            try {
                app(EventSimulation::class)->advance($run->id);
            } catch (ValidationException) {
                $rejected = true;
            }
        }
        $this->assertTrue($rejected, 'The advisor must refuse a responder the backend never ranked.');

        $incident = Incident::where('venue_event_id', $run->venue_event_id)->firstOrFail();
        (new GenerateIncidentAdvice($incident->id))->failed(null);
        $incident->refresh();
        $this->assertSame('failed', data_get($incident->decision, 'adviceStatus'));
        $this->assertNotNull($incident->responder_id);
        $this->assertSame(data_get($incident->decision, 'fallbackResponderId'), $incident->responder_id);
        $this->assertSame('deterministic_fallback', data_get($incident->decision, 'recommendationSource'));
        $this->assertSame('awaiting_approval', $incident->status->value);
    }

    public function test_the_camara_gateway_requires_its_token_and_the_candidate_allowlist(): void
    {
        $this->fakeServices(fn (array $payload): array => $this->completedRun($payload));
        $incident = $this->incidentAfterDetection();
        $body = [
            'requestId' => 'test-request-1',
            'incidentId' => $incident->id,
            'zoneId' => $incident->zone_id,
            'operation' => 'device_reachability',
            'responderId' => $incident->responder_id,
        ];

        $this->postJson('/api/internal/agent/camara', $body)->assertUnauthorized();
        $this->postJson('/api/internal/agent/camara', $body, ['Authorization' => 'Bearer wrong'])->assertUnauthorized();

        $authorized = ['Authorization' => 'Bearer gateway-token'];
        $reachability = $this->postJson('/api/internal/agent/camara', $body, $authorized)
            ->assertOk()->json('evidence');
        $this->assertSame('device_reachability', $reachability['operation']);
        $this->assertSame('live_camara', $reachability['source']);
        $this->assertSame('nokia_network_as_code', $reachability['provider']);
        $this->assertTrue($reachability['dataReachable']);
        $this->assertArrayNotHasKey('sandboxDevice', $reachability);

        $this->postJson('/api/internal/agent/camara', [
            ...$body,
            'responderId' => '11111111-2222-3333-4444-555555555555',
        ], $authorized)->assertUnprocessable();
    }

    public function test_the_gateway_serves_location_verification_and_congestion_as_simulated(): void
    {
        $this->fakeServices(fn (array $payload): array => $this->completedRun($payload));
        $incident = $this->incidentAfterDetection();
        $authorized = ['Authorization' => 'Bearer gateway-token'];

        $location = $this->postJson('/api/internal/agent/camara', [
            'requestId' => 'test-request-2',
            'incidentId' => $incident->id,
            'zoneId' => $incident->zone_id,
            'operation' => 'location_verification',
            'responderId' => $incident->responder_id,
        ], $authorized)->assertOk()->json('evidence');
        $this->assertSame('simulated_fixture', $location['source']);
        $this->assertContains($location['verificationResult'], ['TRUE', 'FALSE', 'PARTIAL', 'UNKNOWN']);
        $this->assertArrayNotHasKey('latitude', $location);

        $congestion = $this->postJson('/api/internal/agent/camara', [
            'requestId' => 'test-request-3',
            'incidentId' => $incident->id,
            'zoneId' => $incident->zone_id,
            'operation' => 'congestion_insights',
        ], $authorized)->assertOk()->json('evidence');
        $this->assertSame('simulated_fixture', $congestion['source']);
        $this->assertContains($congestion['congestionLevel'], ['low', 'medium', 'high', 'unknown']);
        $this->assertArrayNotHasKey('responderId', $congestion);
    }
}
