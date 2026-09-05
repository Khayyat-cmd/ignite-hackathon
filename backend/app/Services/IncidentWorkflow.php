<?php

namespace App\Services;

use App\Enums\EventType;
use App\Enums\IncidentStatus;
use App\Jobs\GenerateIncidentAdvice;
use App\Models\Incident;
use App\Models\Responder;
use App\Models\SimulationRun;
use App\Models\Zone;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

final class IncidentWorkflow
{
    public function __construct(private EventJournal $journal) {}

    public function recommend(Incident $incident, int $actorId): Incident
    {
        $incident = DB::transaction(function () use ($incident, $actorId) {
            $incident = Incident::where('organization_id', $incident->organization_id)->whereKey($incident->id)->lockForUpdate()->firstOrFail();
            $this->requireActiveSimulation($incident);
            abort_unless(in_array($incident->status, [IncidentStatus::Detected, IncidentStatus::AwaitingApproval], true), 409, 'Incident is not awaiting a recommendation.');
            $zone = Zone::where('organization_id', $incident->organization_id)->findOrFail($incident->zone_id);
            $this->requireFreshZone($zone);
            $candidates = [];
            $excluded = [];
            $responders = Responder::where('organization_id', $incident->organization_id)
                ->when($incident->venue_event_id !== null, fn ($query) => $query->where('venue_event_id', $incident->venue_event_id))
                ->orderBy('id')->limit(config('aman.max_candidates') + 1)->get();
            abort_if($responders->count() > config('aman.max_candidates'), 422, 'Roster exceeds the prototype candidate limit; scope the roster before ranking.');
            foreach ($responders as $responder) {
                $reason = $this->ineligibleReason($responder, $incident);
                if ($reason !== null) {
                    $excluded[] = ['responderId' => $responder->id, 'reason' => $reason];

                    continue;
                }
                $location = $responder->signals['location'];
                $candidates[] = [
                    'responderId' => $responder->id,
                    'distanceMeters' => round($this->distance($zone->latitude, $zone->longitude, $location['latitude'], $location['longitude']), 1),
                    'accuracyMeters' => $location['accuracyMeters'],
                    'locationSource' => $responder->signals['source'],
                    'reachabilitySource' => data_get($responder->signals, 'reachability.source', $responder->signals['source']),
                ];
            }
            usort($candidates, fn ($a, $b) => ($a['distanceMeters'] <=> $b['distanceMeters']) ?: strcmp($a['responderId'], $b['responderId']));
            $selected = $candidates[0] ?? null;
            $previousAdvice = data_get($incident->decision, 'advice');
            $previousAdviceStatus = data_get($incident->decision, 'adviceStatus');
            $previousCandidateIds = collect(data_get($incident->decision, 'candidates', []))->pluck('responderId')->values()->all();
            $candidateIds = collect($candidates)->pluck('responderId')->values()->all();
            $sameCandidates = $previousCandidateIds === $candidateIds;
            $adviceStatus = $selected && filled(config('services.openai.key'))
                ? ($sameCandidates && in_array($previousAdviceStatus, ['pending', 'ready'], true) ? $previousAdviceStatus : 'pending')
                : 'disabled';
            $decision = ['method' => 'deterministic_simulated_distance', 'source' => $incident->source, 'candidates' => $candidates, 'excluded' => $excluded, 'requiresRouteReview' => true, 'reason' => $selected ? 'nearest_eligible_reachable_responder' : 'no_eligible_responder', 'adviceStatus' => $adviceStatus];
            if ($previousAdvice && $sameCandidates && $adviceStatus === 'ready') {
                $decision['advice'] = $previousAdvice;
            }
            $incident->update(['responder_id' => $selected['responderId'] ?? null, 'decision' => $decision, 'status' => $selected ? IncidentStatus::AwaitingApproval : IncidentStatus::Detected]);
            if ($selected) {
                $this->journal->append(EventType::ResponderSelected, ['responderId' => $selected['responderId'], 'decision' => $decision], $zone->id, $incident->id, $actorId);
            }

            return $incident;
        }, attempts: 3);

        if (data_get($incident->decision, 'adviceStatus') === 'pending') {
            GenerateIncidentAdvice::dispatch($incident->id)->afterCommit();
        }

        return $incident;
    }

    public function approve(Incident $incident, int $actorId, ?string $selectedResponderId = null): Incident
    {
        return DB::transaction(function () use ($incident, $actorId, $selectedResponderId) {
            $incident = Incident::where('organization_id', $incident->organization_id)->whereKey($incident->id)->lockForUpdate()->firstOrFail();
            $this->requireActiveSimulation($incident);
            if (in_array($incident->status, [IncidentStatus::Dispatched, IncidentStatus::Acknowledged], true)) {
                return $incident;
            }
            abort_unless($incident->status === IncidentStatus::AwaitingApproval && $incident->responder_id, 409, 'A recommendation is required before approval.');
            if ($selectedResponderId !== null) {
                $candidateIds = collect(data_get($incident->decision, 'candidates', []))->pluck('responderId');
                abort_unless($candidateIds->containsStrict($selectedResponderId), 422, 'Select an eligible responder from the current recommendation.');
                $incident->responder_id = $selectedResponderId;
            }
            $zone = Zone::where('organization_id', $incident->organization_id)->findOrFail($incident->zone_id);
            $this->requireFreshZone($zone);
            $responder = Responder::where('organization_id', $incident->organization_id)->whereKey($incident->responder_id)->lockForUpdate()->firstOrFail();
            $reason = $this->ineligibleReason($responder, $incident);
            abort_if($reason !== null, 409, 'Recommendation is no longer valid: '.$reason);
            $responder->update(['available' => false]);
            $decision = $incident->decision ?? [];
            $decision['operatorReview'] = [
                'selectedResponderId' => $responder->id,
                'advisorAccepted' => data_get($decision, 'advice.recommendedResponderId') === $responder->id,
                'reviewedAt' => now()->toISOString(),
            ];
            $incident->update(['responder_id' => $responder->id, 'assigned_responder_id' => $responder->id, 'decision' => $decision, 'status' => IncidentStatus::Dispatched, 'approved_at' => now()]);
            $this->journal->append(EventType::ResponseStarted, ['responderId' => $responder->id, 'source' => $incident->source, 'deliveryStatus' => 'awaiting_acknowledgement', 'qodStatus' => 'not_requested'], $incident->zone_id, $incident->id, $actorId);

            return $incident;
        }, attempts: 3);
    }

    public function acknowledge(Incident $incident, int $actorId): Incident
    {
        return DB::transaction(function () use ($incident, $actorId) {
            $incident = Incident::where('organization_id', $incident->organization_id)->whereKey($incident->id)->lockForUpdate()->firstOrFail();
            $this->requireActiveSimulation($incident);
            if ($incident->status === IncidentStatus::Acknowledged) {
                return $incident;
            }
            abort_unless($incident->status === IncidentStatus::Dispatched, 409, 'Incident must be dispatched first.');
            $incident->update(['status' => IncidentStatus::Acknowledged]);
            $this->journal->append(EventType::ResponseAcknowledged, ['responderId' => $incident->responder_id], $incident->zone_id, $incident->id, $actorId);

            return $incident;
        });
    }

    public function resolve(Incident $incident, int $actorId, string $note): Incident
    {
        return DB::transaction(function () use ($incident, $actorId, $note) {
            // Same zone-before-incident ordering as ingestion avoids a lock inversion.
            $zone = Zone::where('organization_id', $incident->organization_id)->whereKey($incident->zone_id)->lockForUpdate()->firstOrFail();
            $incident = Incident::where('organization_id', $incident->organization_id)->whereKey($incident->id)->lockForUpdate()->firstOrFail();
            if ($incident->status === IncidentStatus::Resolved) {
                return $incident;
            }
            $this->requireActiveSimulation($incident);
            $this->requireFreshZone($zone);
            abort_if(in_array($zone->risk_level, ['critical', 'unknown'], true), 409, 'Fresh non-critical evidence is required before resolution.');
            abort_unless($zone->noncritical_since && $zone->noncritical_since->lte(now()->subSeconds(config('aman.resolution_stable_seconds'))),
                409, 'Zone must remain non-critical for 15 seconds before resolution.');
            if ($incident->assigned_responder_id) {
                Responder::where('organization_id', $incident->organization_id)->whereKey($incident->assigned_responder_id)->update(['available' => true]);
            }
            $incident->update(['status' => IncidentStatus::Resolved, 'active_zone_id' => null, 'assigned_responder_id' => null, 'resolved_at' => now()]);
            $this->journal->append(EventType::IncidentResolved, ['note' => $note, 'source' => $incident->source], $zone->id, $incident->id, $actorId);

            return $incident;
        }, attempts: 3);
    }

    private function ineligibleReason(Responder $responder, Incident $incident): ?string
    {
        if ($incident->venue_event_id !== null && $responder->venue_event_id !== $incident->venue_event_id) {
            return 'event_mismatch';
        }
        if (! $responder->authorized) {
            return 'device_not_authorized';
        }
        if (! $responder->available) {
            return 'unavailable';
        }
        if ($responder->role !== $incident->required_role) {
            return 'role_mismatch';
        }
        $signals = $responder->signals ?? [];
        if (($signals['source'] ?? null) !== $incident->source) {
            return 'evidence_source_mismatch';
        }
        if (! data_get($signals, 'reachability.dataReachable')) {
            return 'data_reachability_unconfirmed';
        }
        if (! $this->fresh(data_get($signals, 'reachability.observedAt'))) {
            return 'reachability_stale_or_unknown';
        }
        if (! $this->fresh(data_get($signals, 'location.observedAt'))) {
            return 'location_stale_or_unknown';
        }
        if (! is_numeric(data_get($signals, 'location.accuracyMeters')) || data_get($signals, 'location.accuracyMeters') > config('aman.max_location_accuracy_meters')) {
            return 'location_too_imprecise';
        }

        return null;
    }

    private function requireActiveSimulation(Incident $incident): void
    {
        if ($incident->source === 'simulated_network') {
            abort_unless(config('aman.demo_enabled') && SimulationRun::where('venue_event_id', $incident->venue_event_id)->where('status', '!=', 'stopped')->exists(), 409, 'Simulation has stopped or is disabled.');
        }
    }

    private function requireFreshZone(Zone $zone): void
    {
        abort_unless($zone->last_observed_at && $this->fresh($zone->last_observed_at->toISOString()), 409, 'Zone evidence is stale or missing; refresh it first.');
    }

    private function fresh(?string $timestamp): bool
    {
        if ($timestamp === null) {
            return false;
        }
        $time = CarbonImmutable::parse($timestamp);

        return $time->gte(now()->subSeconds(config('aman.signal_max_age_seconds'))) && $time->lte(now()->addSeconds(config('aman.future_tolerance_seconds')));
    }

    private function distance(float $lat1, float $lon1, float $lat2, float $lon2): float
    {
        $a = sin(deg2rad($lat2 - $lat1) / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin(deg2rad($lon2 - $lon1) / 2) ** 2;

        return 6371000 * 2 * asin(sqrt(min(1, max(0, $a))));
    }
}
