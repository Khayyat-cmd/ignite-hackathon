<?php

namespace App\Services\Ai;

use App\Models\Incident;
use App\Models\Responder;
use App\Models\Zone;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use RuntimeException;

class OpenAiIncidentAdvisor implements IncidentAdvisor
{
    /** @return array<string, mixed> */
    public function advise(Incident $incident): array
    {
        $decision = $incident->decision ?? [];
        $candidateIds = collect($decision['candidates'] ?? [])->pluck('responderId')->values()->all();
        if ($candidateIds === []) {
            throw new RuntimeException('AI advice requires at least one eligible responder.');
        }

        $zone = Zone::where('organization_id', $incident->organization_id)->findOrFail($incident->zone_id);
        $responders = Responder::where('organization_id', $incident->organization_id)
            ->whereIn('id', $candidateIds)->get()->keyBy('id');
        $candidates = collect($decision['candidates'])->map(function (array $candidate) use ($responders): array {
            $responder = $responders->get($candidate['responderId']);

            return [
                ...$candidate,
                'name' => $responder?->name,
                'role' => $responder?->role,
                'communicationAdvice' => data_get($responder?->signals, 'communicationAdvice'),
            ];
        })->values()->all();
        $snapshot = [
            'incident' => ['id' => $incident->id, 'status' => $incident->status->value, 'requiredRole' => $incident->required_role, 'source' => $incident->source],
            'zone' => [
                'id' => $zone->id, 'name' => $zone->name, 'riskLevel' => $zone->risk_level,
                'warningDensity' => $zone->warning_density, 'criticalDensity' => $zone->critical_density,
                'lastObservedAt' => $zone->last_observed_at?->toISOString(), 'latestReading' => $zone->latest_reading,
            ],
            'deterministicRecommendation' => $incident->responder_id,
            'eligibleCandidates' => $candidates,
        ];

        $response = Http::baseUrl(config('services.openai.base_url'))
            ->withToken(config('services.openai.key'))
            ->acceptJson()
            ->timeout((int) config('services.openai.timeout_seconds'))
            ->retry(2, 200)
            ->post('/responses', [
                'model' => config('services.openai.model'),
                'instructions' => 'You are AMAN\'s control-room incident advisor. Explain the supplied evidence concisely. Recommend only an eligible responder ID from the supplied list, prefer the deterministic recommendation unless evidence supports another candidate, state uncertainty, and never claim that an action has been dispatched. The operator makes the final decision.',
                'input' => json_encode($snapshot, JSON_THROW_ON_ERROR),
                'reasoning' => ['effort' => config('services.openai.reasoning_effort')],
                'max_output_tokens' => (int) config('services.openai.max_output_tokens'),
                'store' => false,
                'text' => [
                    'verbosity' => 'low',
                    'format' => [
                        'type' => 'json_schema',
                        'name' => 'aman_incident_advice',
                        'strict' => true,
                        'schema' => $this->schema(),
                    ],
                ],
            ])->throw()->json();

        $content = collect($response['output'] ?? [])->flatMap(fn (array $item): array => $item['content'] ?? []);
        if ($content->contains(fn (array $item): bool => ($item['type'] ?? null) === 'refusal')) {
            throw new RuntimeException('The AI provider declined to analyze this incident.');
        }
        $text = $content->firstWhere('type', 'output_text')['text'] ?? null;
        if (! is_string($text)) {
            throw new RuntimeException('The AI provider returned no structured advice.');
        }
        $advice = json_decode($text, true, flags: JSON_THROW_ON_ERROR);
        $validated = Validator::make($advice, [
            'summary' => ['required', 'string', 'max:500'],
            'urgency' => ['required', Rule::in(['low', 'medium', 'high', 'critical'])],
            'confidence' => ['required', Rule::in(['low', 'medium', 'high'])],
            'recommendedResponderId' => ['nullable', 'uuid', Rule::in($candidateIds)],
            'evidence' => ['required', 'array', 'min:1', 'max:4'],
            'evidence.*' => ['required', 'string', 'max:240'],
            'uncertainties' => ['required', 'array', 'max:3'],
            'uncertainties.*' => ['required', 'string', 'max:240'],
            'proposedAction' => ['required', 'string', 'max:500'],
        ])->validate();

        return [
            ...$validated,
            'provider' => 'openai',
            'model' => config('services.openai.model'),
            'responseId' => $response['id'] ?? null,
            'generatedAt' => now()->toISOString(),
            'usage' => [
                'inputTokens' => data_get($response, 'usage.input_tokens'),
                'outputTokens' => data_get($response, 'usage.output_tokens'),
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function schema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'summary' => ['type' => 'string'],
                'urgency' => ['type' => 'string', 'enum' => ['low', 'medium', 'high', 'critical']],
                'confidence' => ['type' => 'string', 'enum' => ['low', 'medium', 'high']],
                'recommendedResponderId' => ['type' => ['string', 'null']],
                'evidence' => ['type' => 'array', 'items' => ['type' => 'string'], 'minItems' => 1, 'maxItems' => 4],
                'uncertainties' => ['type' => 'array', 'items' => ['type' => 'string'], 'maxItems' => 3],
                'proposedAction' => ['type' => 'string'],
            ],
            'required' => ['summary', 'urgency', 'confidence', 'recommendedResponderId', 'evidence', 'uncertainties', 'proposedAction'],
            'additionalProperties' => false,
        ];
    }
}
