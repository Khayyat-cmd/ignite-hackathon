<?php

namespace App\Jobs;

use App\Enums\EventType;
use App\Enums\IncidentStatus;
use App\Models\Incident;
use App\Services\Ai\IncidentAdvisor;
use App\Services\EventJournal;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Throwable;

class GenerateIncidentAdvice implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 55;

    public int $uniqueFor = 300;

    public bool $failOnTimeout = true;

    public function __construct(public readonly string $incidentId) {}

    public function uniqueId(): string
    {
        return $this->incidentId;
    }

    public function handle(IncidentAdvisor $advisor, EventJournal $journal): void
    {
        $incident = Incident::findOrFail($this->incidentId);
        if (data_get($incident->decision, 'adviceStatus') === 'ready' || ! $incident->active_zone_id) {
            return;
        }
        $candidateIds = collect(data_get($incident->decision, 'candidates', []))->pluck('responderId')->values()->all();
        $advice = $advisor->advise($incident);

        DB::transaction(function () use ($candidateIds, $advice, $journal): void {
            $incident = Incident::whereKey($this->incidentId)->lockForUpdate()->firstOrFail();
            $currentCandidates = collect(data_get($incident->decision, 'candidates', []))->pluck('responderId')->values()->all();
            if ($candidateIds !== $currentCandidates || ! $incident->active_zone_id) {
                return;
            }
            $decision = $incident->decision ?? [];
            // The legacy one-shot advisor remains available only for an
            // explicit retry and predates the orchestration agent status.
            $agentStatus = $advice['agentStatus'] ?? 'completed';
            $recommendedResponderId = $agentStatus === 'completed'
                ? $advice['recommendedResponderId']
                : data_get($decision, 'fallbackResponderId');
            if ($recommendedResponderId !== null && ! in_array($recommendedResponderId, $currentCandidates, true)) {
                throw new \RuntimeException('The selected responder is outside the current candidate list.');
            }
            $decision['adviceStatus'] = 'ready';
            $decision['advice'] = $advice;
            $decision['recommendationSource'] = $agentStatus === 'completed'
                ? ($recommendedResponderId ? 'agent' : 'agent_no_recommendation')
                : ($recommendedResponderId ? 'deterministic_fallback' : 'none');
            $decision['reason'] = match ($decision['recommendationSource']) {
                'agent' => 'agent_recommended_responder',
                'deterministic_fallback' => 'nearest_eligible_reachable_responder',
                default => 'no_eligible_responder',
            };
            unset($decision['adviceError']);
            $incident->update([
                'responder_id' => $recommendedResponderId,
                'decision' => $decision,
                'status' => $recommendedResponderId ? IncidentStatus::AwaitingApproval : IncidentStatus::Detected,
            ]);
            if ($recommendedResponderId !== null) {
                $journal->append(EventType::ResponderSelected, [
                    'responderId' => $recommendedResponderId,
                    'decision' => $decision,
                ], $incident->zone_id, $incident->id);
            }
            $journal->append(EventType::IncidentAdviceReady, [
                'urgency' => $advice['urgency'],
                'confidence' => $advice['confidence'],
                'recommendedResponderId' => $advice['recommendedResponderId'],
            ], $incident->zone_id, $incident->id);
        });
    }

    public function failed(?Throwable $exception): void
    {
        DB::transaction(function (): void {
            $incident = Incident::whereKey($this->incidentId)->lockForUpdate()->first();
            if (! $incident || data_get($incident->decision, 'adviceStatus') === 'ready') {
                return;
            }
            $decision = $incident->decision ?? [];
            $candidateIds = collect(data_get($decision, 'candidates', []))->pluck('responderId')->all();
            $fallbackResponderId = data_get($decision, 'fallbackResponderId');
            if (! in_array($fallbackResponderId, $candidateIds, true)) {
                $fallbackResponderId = null;
            }
            $decision['adviceStatus'] = 'failed';
            $decision['adviceError'] = 'AI advice is temporarily unavailable. The safe deterministic fallback is active.';
            $decision['recommendationSource'] = $fallbackResponderId ? 'deterministic_fallback' : 'none';
            $decision['reason'] = $fallbackResponderId ? 'nearest_eligible_reachable_responder' : 'no_eligible_responder';
            $incident->update([
                'responder_id' => $fallbackResponderId,
                'decision' => $decision,
                'status' => $fallbackResponderId ? IncidentStatus::AwaitingApproval : IncidentStatus::Detected,
            ]);
            if ($fallbackResponderId !== null) {
                app(EventJournal::class)->append(EventType::ResponderSelected, [
                    'responderId' => $fallbackResponderId,
                    'decision' => $decision,
                ], $incident->zone_id, $incident->id);
            }
        });
    }
}
