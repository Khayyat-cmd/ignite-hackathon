<?php

namespace App\Services\Ai;

use App\Models\Incident;
use App\Models\Responder;
use App\Models\Zone;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use RuntimeException;

/**
 * Routes incident advice through the CAMARA orchestration agent in `ml/agent`
 * instead of a single model call. The agent chooses which CAMARA APIs to call,
 * and returns the evidence trail alongside its recommendation.
 *
 * The result is mapped onto the advice shape the console already reads, so a
 * degraded agent run still leaves the operator with the deterministic ranking.
 */
class AgentIncidentAdvisor implements IncidentAdvisor
{
    /** @return array<string, mixed> */
    public function advise(Incident $incident): array
    {
        $decision = $incident->decision ?? [];
        $candidates = collect($decision['candidates'] ?? []);
        if ($candidates->isEmpty()) {
            throw new RuntimeException('AI advice requires at least one eligible responder.');
        }

        $zone = Zone::where('organization_id', $incident->organization_id)->findOrFail($incident->zone_id);
        $responders = Responder::where('organization_id', $incident->organization_id)
            ->whereIn('id', $candidates->pluck('responderId')->all())->get()->keyBy('id');
        $eligible = $candidates->map(function (array $candidate) use ($responders): ?array {
            $responder = $responders->get($candidate['responderId']);
            if ($responder === null) {
                return null;
            }

            return [
                'responderId' => $responder->id,
                'name' => $responder->name,
                'role' => $responder->role,
                'distanceMeters' => (float) $candidate['distanceMeters'],
                'accuracyMeters' => isset($candidate['accuracyMeters']) ? (float) $candidate['accuracyMeters'] : null,
            ];
        })->filter()->values();
        if ($eligible->isEmpty()) {
            throw new RuntimeException('AI advice requires at least one eligible responder.');
        }

        $payload = [
            'requestId' => $this->requestId($incident, $eligible->pluck('responderId')->all()),
            'incident' => [
                'id' => $incident->id,
                'status' => $incident->status->value,
                'severity' => $this->severity($zone->risk_level),
                'source' => $incident->source,
            ],
            'zone' => [
                'id' => $zone->id,
                'name' => $zone->name,
                'riskLevel' => $this->severity($zone->risk_level),
                'density' => min(50.0, max(0.0, (float) data_get($zone->latest_reading, 'densityPerSquareMeter', 0))),
                'warningThreshold' => (float) $zone->warning_density,
                'criticalThreshold' => (float) $zone->critical_density,
                'observedAt' => ($zone->last_observed_at ?? now())->toISOString(),
            ],
            'eligibleCandidates' => $eligible->all(),
        ];
        if ($incident->responder_id !== null && $eligible->contains('responderId', $incident->responder_id)) {
            $payload['deterministicRecommendation'] = [
                'responderId' => $incident->responder_id,
                'reason' => (string) ($decision['reason'] ?? 'nearest_eligible_reachable_responder'),
            ];
        }

        $response = Http::baseUrl((string) config('aman.agent.url'))
            ->withToken((string) config('aman.agent.token'))
            ->acceptJson()
            ->timeout((int) config('aman.agent.timeout_seconds'))
            ->post('/v1/incidents/advise', $payload)
            ->throw()
            ->json();

        return $this->mapped($response, $eligible->pluck('responderId')->all(), $payload['incident']['severity']);
    }

    /**
     * The agent replays an identical request, so the key is stable for as long
     * as the incident's candidate set is. A changed roster earns a fresh run.
     */
    private function requestId(Incident $incident, array $candidateIds): string
    {
        return $incident->id.':'.substr(hash('sha256', implode(',', $candidateIds)), 0, 12);
    }

    private function severity(?string $riskLevel): string
    {
        return match ($riskLevel) {
            'critical' => 'critical',
            'warning' => 'high',
            'normal' => 'low',
            default => 'medium',
        };
    }

    /**
     * @param  array<int, string>  $candidateIds
     * @return array<string, mixed>
     */
    private function mapped(mixed $response, array $candidateIds, string $severity): array
    {
        if (! is_array($response)) {
            throw new RuntimeException('The orchestration agent returned no advice.');
        }
        $validated = Validator::make($response, [
            'agentStatus' => ['required', Rule::in(['completed', 'degraded'])],
            'provider' => ['required', 'string', 'max:40'],
            'model' => ['required', 'string', 'max:80'],
            'agentRuntime' => ['required', 'string', 'max:60'],
            'agentVersion' => ['required', 'string', 'max:20'],
            'evidenceMode' => ['required', Rule::in(['live_camara', 'simulated_fixture', 'none'])],
            'recommendedResponderId' => ['nullable', 'uuid', Rule::in($candidateIds)],
            'confidence' => ['required', Rule::in(['low', 'medium', 'high'])],
            'summary' => ['required', 'string', 'max:600'],
            'evidenceSummary' => ['required', 'array', 'min:1', 'max:8'],
            'evidenceSummary.*' => ['required', 'string', 'max:400'],
            'uncertainty' => ['present', 'array', 'max:8'],
            'uncertainty.*' => ['required', 'string', 'max:400'],
            'proposedAction' => ['required', 'string', 'max:500'],
            'requiresHumanApproval' => ['required', 'accepted'],
            'toolTrace' => ['present', 'array', 'max:12'],
            'toolTrace.*.sequence' => ['required', 'integer', 'min:1'],
            'toolTrace.*.tool' => ['required', Rule::in(CamaraEvidenceGateway::OPERATIONS)],
            'toolTrace.*.reason' => ['required', 'string', 'max:300'],
            'toolTrace.*.responderId' => ['nullable', 'uuid'],
            'toolTrace.*.provider' => ['required', 'string', 'max:80'],
            'toolTrace.*.api' => ['required', 'string', 'max:120'],
            'toolTrace.*.source' => ['required', Rule::in(['live_camara', 'simulated_fixture'])],
            'toolTrace.*.status' => ['required', Rule::in(['ok', 'unavailable'])],
            'toolTrace.*.checkedAt' => ['required', 'date'],
            'toolTrace.*.result' => ['present', 'array'],
            'generatedAt' => ['required', 'date'],
        ])->validate();

        return [
            // The console's existing brief keys, so one advisor can replace the other.
            'summary' => $validated['summary'],
            'urgency' => $this->urgency($severity, $validated['toolTrace']),
            'confidence' => $validated['confidence'],
            'recommendedResponderId' => $validated['recommendedResponderId'],
            'evidence' => $validated['evidenceSummary'],
            'uncertainties' => $validated['uncertainty'],
            'proposedAction' => $validated['proposedAction'],
            'provider' => $validated['provider'],
            'model' => $validated['model'],
            'generatedAt' => $validated['generatedAt'],
            // What the agent adds over a single model call.
            'agentStatus' => $validated['agentStatus'],
            'agentRuntime' => $validated['agentRuntime'],
            'agentVersion' => $validated['agentVersion'],
            'evidenceMode' => $validated['evidenceMode'],
            'requiresHumanApproval' => true,
            'toolTrace' => $validated['toolTrace'],
        ];
    }

    /**
     * The agent reports decision confidence, not incident urgency. Urgency stays
     * the zone risk that opened the incident, escalated when the agent's own
     * congestion evidence says communications are degraded.
     *
     * @param  array<int, array<string, mixed>>  $toolTrace
     */
    private function urgency(string $severity, array $toolTrace): string
    {
        $congested = collect($toolTrace)
            ->contains(fn (array $entry): bool => data_get($entry, 'result.congestionLevel') === 'high');

        return $congested && $severity !== 'low' ? 'critical' : $severity;
    }
}
