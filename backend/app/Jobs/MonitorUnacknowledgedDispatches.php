<?php

namespace App\Jobs;

use App\Enums\IncidentStatus;
use App\Models\Incident;
use App\Models\Responder;
use App\Models\Zone;
use App\Services\IncidentWorkflow;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;

class MonitorUnacknowledgedDispatches implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 30;

    public int $uniqueFor = 15;

    public function uniqueId(): string
    {
        return 'unacknowledged-dispatch-monitor';
    }

    public function handle(IncidentWorkflow $workflow): void
    {
        $cutoff = now()->subSeconds((int) config('aman.dispatch_ack_timeout_seconds'));

        Incident::query()
            ->where('status', IncidentStatus::Dispatched)
            ->whereNotNull('active_zone_id')
            ->where('approved_at', '<=', $cutoff)
            ->select('id')
            ->eachById(fn (Incident $incident) => $this->process($incident->id, $workflow));
    }

    private function process(string $incidentId, IncidentWorkflow $workflow): void
    {
        $action = DB::transaction(function () use ($incidentId, $workflow): ?array {
            $incident = Incident::whereKey($incidentId)->lockForUpdate()->first();
            if (! $incident
                || $incident->status !== IncidentStatus::Dispatched
                || ! $incident->active_zone_id
                || ! $incident->approved_at?->lte(now()->subSeconds((int) config('aman.dispatch_ack_timeout_seconds')))
                || data_get($incident->decision, 'escalation.handledAt')) {
                return null;
            }

            $responder = Responder::whereKey($incident->assigned_responder_id)->lockForUpdate()->first();
            if (! $responder) {
                return null;
            }
            $reachability = data_get($responder->signals, 'reachability', []);
            $observedAt = data_get($reachability, 'observedAt');
            $fresh = is_string($observedAt)
                && CarbonImmutable::parse($observedAt)->gte(now()->subSeconds((int) config('aman.signal_max_age_seconds')))
                && CarbonImmutable::parse($observedAt)->lte(now()->addSeconds((int) config('aman.future_tolerance_seconds')));

            $decision = $incident->decision ?? [];
            if ($fresh && data_get($reachability, 'dataReachable') === true) {
                $decision['escalation'] = [
                    'outcome' => 'reminder_sent',
                    'responderId' => $responder->id,
                    'handledAt' => now()->toISOString(),
                    'reachabilityObservedAt' => $observedAt,
                ];
                $decision['escalationHistory'][] = $decision['escalation'];
                $incident->update(['decision' => $decision]);

                return ['type' => 'reminder', 'incidentId' => $incident->id,
                    'responderId' => $responder->id, 'zoneId' => $incident->zone_id];
            }

            $decision['escalation'] = [
                'outcome' => 'reassignment_requested',
                'responderId' => $responder->id,
                'handledAt' => now()->toISOString(),
                'reason' => $fresh ? 'mobile_data_unreachable' : 'reachability_unavailable_or_stale',
            ];
            $decision['escalationHistory'][] = $decision['escalation'];
            $responder->update(['available' => true]);
            $incident->update([
                'responder_id' => null,
                'assigned_responder_id' => null,
                'status' => IncidentStatus::Detected,
                'approved_at' => null,
                'decision' => $decision,
            ]);
            $workflow->recommend($incident, null);

            return ['type' => 'reassignment'];
        }, attempts: 3);

        if (data_get($action, 'type') === 'reminder') {
            $zoneName = Zone::whereKey($action['zoneId'])->value('name') ?? 'assigned zone';
            SendResponderPushNotification::dispatch(
                $action['responderId'],
                '🚨 REMINDER — '.$zoneName,
                'Your response is still required. Open AMAN and acknowledge now.',
                ['type' => 'mission_reminder', 'incidentId' => $action['incidentId']],
            );
        }
    }
}
