<?php

namespace App\Services\Ai;

use App\Models\Incident;
use App\Models\Responder;
use App\Models\SimulationRun;
use App\Models\Zone;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Validator;
use RuntimeException;

class OpenAiOperatorCopilot
{
    /** @param array<int, array{role: string, content: string}> $history
     * @return array<string, mixed>
     */
    public function respond(SimulationRun $run, string $question, array $history): array
    {
        $zones = Zone::where('venue_event_id', $run->venue_event_id)->get();
        $responders = Responder::where('venue_event_id', $run->venue_event_id)->get();
        $incidents = Incident::where('venue_event_id', $run->venue_event_id)
            ->whereNotNull('active_zone_id')->latest()->limit(20)->get();
        $allowedSuggestions = $incidents->flatMap(function (Incident $incident): array {
            return collect(data_get($incident->decision, 'candidates', []))->map(fn (array $candidate): array => [
                'incidentId' => $incident->id,
                'zoneId' => $incident->zone_id,
                'responderId' => $candidate['responderId'],
            ])->all();
        })->values();
        $snapshot = [
            'event' => [
                'status' => $run->status,
                'elapsedSeconds' => $run->elapsed_seconds,
                'observedAt' => data_get($run->snapshot, 'observedAt'),
                'stale' => ! data_get($run->snapshot, 'observedAt')
                    || CarbonImmutable::parse(data_get($run->snapshot, 'observedAt'))->lt(now()->subSeconds(15)),
                'quality' => data_get($run->snapshot, 'quality', []),
            ],
            'zones' => $zones->map(fn (Zone $zone): array => [
                'id' => $zone->id,
                'name' => $zone->name,
                'riskLevel' => $zone->risk_level,
                'areaSquareMeters' => $zone->area_sqm,
                'lastObservedAt' => $zone->last_observed_at?->toISOString(),
                'latestReading' => $zone->latest_reading,
            ])->values()->all(),
            'responders' => $responders->map(fn (Responder $responder): array => [
                'id' => $responder->id,
                'name' => $responder->name,
                'role' => $responder->role,
                'available' => $responder->available,
                'dataReachable' => data_get($responder->signals, 'reachability.dataReachable'),
                'position' => data_get($responder->signals, 'simulationPoint'),
            ])->values()->all(),
            'activeIncidents' => $incidents->map(fn (Incident $incident): array => [
                'id' => $incident->id,
                'zoneId' => $incident->zone_id,
                'status' => $incident->status->value,
                'recommendedResponderId' => $incident->responder_id,
                'eligibleCandidates' => data_get($incident->decision, 'candidates', []),
                'existingAdvice' => data_get($incident->decision, 'advice'),
            ])->values()->all(),
        ];

        $response = Http::baseUrl(config('services.openai.base_url'))
            ->withToken(config('services.openai.key'))
            ->acceptJson()
            ->timeout((int) config('services.openai.timeout_seconds'))
            ->retry(2, 200)
            ->post('/responses', [
                'model' => config('services.openai.model'),
                'instructions' => 'You are AMAN Copilot, a concise control-room assistant. Answer the operator using only the supplied live situation. Clearly distinguish facts, simulation assumptions, and uncertainty. When action is warranted, you may propose sending one eligible responder to the incident zone, but only using an exact incidentId, zoneId, and responderId combination supplied in eligibleCandidates. Never say an action was dispatched or approved. The operator must review the route and explicitly approve dispatch. If evidence is stale, warn the operator and do not make a dispatch suggestion.',
                'input' => json_encode([
                    'conversation' => $history,
                    'operatorQuestion' => $question,
                    'liveSituation' => $snapshot,
                ], JSON_THROW_ON_ERROR),
                'reasoning' => ['effort' => config('services.openai.reasoning_effort')],
                'max_output_tokens' => (int) config('services.openai.max_output_tokens'),
                'store' => false,
                'text' => [
                    'verbosity' => 'low',
                    'format' => [
                        'type' => 'json_schema',
                        'name' => 'aman_operator_copilot',
                        'strict' => true,
                        'schema' => $this->schema(),
                    ],
                ],
            ])->throw()->json();

        $content = collect($response['output'] ?? [])->flatMap(fn (array $item): array => $item['content'] ?? []);
        if ($content->contains(fn (array $item): bool => ($item['type'] ?? null) === 'refusal')) {
            throw new RuntimeException('The AI provider declined to answer this question.');
        }
        $text = $content->firstWhere('type', 'output_text')['text'] ?? null;
        if (! is_string($text)) {
            throw new RuntimeException('The AI provider returned no structured response.');
        }
        $result = Validator::make(json_decode($text, true, flags: JSON_THROW_ON_ERROR), [
            'answer' => ['required', 'string', 'max:1200'],
            'suggestion' => ['nullable', 'array'],
            'suggestion.incidentId' => ['required_with:suggestion', 'uuid'],
            'suggestion.zoneId' => ['required_with:suggestion', 'uuid'],
            'suggestion.responderId' => ['required_with:suggestion', 'uuid'],
            'suggestion.reason' => ['required_with:suggestion', 'string', 'max:500'],
            'followUpPrompt' => ['nullable', 'string', 'max:240'],
        ])->validate();

        if ($result['suggestion'] !== null) {
            $valid = $allowedSuggestions->contains(fn (array $allowed): bool => $allowed['incidentId'] === $result['suggestion']['incidentId']
                && $allowed['zoneId'] === $result['suggestion']['zoneId']
                && $allowed['responderId'] === $result['suggestion']['responderId']);
            if (! $valid) {
                $result['suggestion'] = null;
            }
        }

        return [
            ...$result,
            'model' => config('services.openai.model'),
            'generatedAt' => now()->toISOString(),
            'responseId' => $response['id'] ?? null,
        ];
    }

    /** @return array<string, mixed> */
    private function schema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'answer' => ['type' => 'string'],
                'suggestion' => [
                    'type' => ['object', 'null'],
                    'properties' => [
                        'incidentId' => ['type' => 'string'],
                        'zoneId' => ['type' => 'string'],
                        'responderId' => ['type' => 'string'],
                        'reason' => ['type' => 'string'],
                    ],
                    'required' => ['incidentId', 'zoneId', 'responderId', 'reason'],
                    'additionalProperties' => false,
                ],
                'followUpPrompt' => ['type' => ['string', 'null']],
            ],
            'required' => ['answer', 'suggestion', 'followUpPrompt'],
            'additionalProperties' => false,
        ];
    }
}
