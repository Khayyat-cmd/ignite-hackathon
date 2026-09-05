<?php

namespace App\Jobs;

use App\Enums\EventType;
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

    public int $timeout = 25;

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
            $decision['adviceStatus'] = 'ready';
            $decision['advice'] = $advice;
            unset($decision['adviceError']);
            $incident->update(['decision' => $decision]);
            $journal->append(EventType::IncidentAdviceReady, [
                'urgency' => $advice['urgency'],
                'confidence' => $advice['confidence'],
                'recommendedResponderId' => $advice['recommendedResponderId'],
            ], $incident->zone_id, $incident->id);
        });
    }

    public function failed(?Throwable $exception): void
    {
        $incident = Incident::find($this->incidentId);
        if (! $incident || data_get($incident->decision, 'adviceStatus') === 'ready') {
            return;
        }
        $decision = $incident->decision ?? [];
        $decision['adviceStatus'] = 'failed';
        $decision['adviceError'] = 'AI advice is temporarily unavailable. Use the deterministic responder ranking.';
        $incident->update(['decision' => $decision]);
    }
}
