<?php

namespace App\Services;

use App\Enums\EventType;
use App\Enums\IncidentStatus;
use App\Models\Incident;
use App\Models\Responder;
use App\Models\Zone;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

final class IncidentWorkflow
{
    public function __construct(private EventJournal $journal) {}

    public function recommend(Incident $incident, int $actorId): Incident
    {
        return DB::transaction(function () use ($incident, $actorId) {
            $incident = Incident::whereKey($incident->id)->lockForUpdate()->firstOrFail();
            abort_unless(in_array($incident->status, [IncidentStatus::Detected, IncidentStatus::AwaitingApproval], true), 409, 'Incident is not awaiting a recommendation.');
            $zone = Zone::findOrFail($incident->zone_id);
            $hybrid = $this->isStadiumDemo($incident, $zone);
            $this->requireFreshZone($zone);
            $candidates = [];
            $excluded = [];
            $responders = Responder::orderBy('id')->limit(config('aman.max_candidates') + 1)->get();
            abort_if($responders->count() > config('aman.max_candidates'), 422, 'Roster exceeds the prototype candidate limit; scope the roster before ranking.');
            foreach ($responders as $responder) {
                $reason = $this->ineligibleReason($responder, $incident, $hybrid);
                if ($reason !== null) {
                    $excluded[] = ['responderId' => $responder->id, 'reason' => $reason];

                    continue;
                }
                $location = $hybrid ? $responder->demo_position : $responder->signals['location'];
                $candidates[] = [
                    'responderId' => $responder->id,
                    'distanceMeters' => round($this->distance($zone->latitude, $zone->longitude, $location['latitude'], $location['longitude']), 1),
                    'accuracyMeters' => $hybrid ? null : $location['accuracyMeters'],
                    'locationSource' => $hybrid ? 'simulated_stadium_position' : $responder->signals['source'],
                    'reachabilitySource' => $responder->signals['source'],
                ];
            }
            usort($candidates, fn ($a, $b) => ($a['distanceMeters'] <=> $b['distanceMeters']) ?: strcmp($a['responderId'], $b['responderId']));
            $selected = $candidates[0] ?? null;
            $decision = ['method' => $hybrid ? 'stadium_demo_simulated_distance_nokia_reachability' : 'deterministic_policy_v1', 'source' => $incident->source, 'candidates' => $candidates, 'excluded' => $excluded, 'requiresRouteReview' => true, 'reason' => $selected ? ($hybrid ? 'nearest_simulated_position_with_nokia_sandbox_reachability' : 'nearest_eligible_reachable_candidate_by_approximate_location') : 'no_eligible_responder'];
            $incident->update(['responder_id' => $selected['responderId'] ?? null, 'decision' => $decision, 'status' => $selected ? IncidentStatus::AwaitingApproval : IncidentStatus::Detected]);
            if ($selected) {
                $this->journal->append(EventType::ResponderSelected, ['responderId' => $selected['responderId'], 'decision' => $decision], $zone->id, $incident->id, $actorId);
            }

            return $incident;
        }, attempts: 3);
    }

    public function approve(Incident $incident, int $actorId): Incident
    {
        return DB::transaction(function () use ($incident, $actorId) {
            $incident = Incident::whereKey($incident->id)->lockForUpdate()->firstOrFail();
            if (in_array($incident->status, [IncidentStatus::Dispatched, IncidentStatus::Acknowledged], true)) {
                return $incident;
            }
            abort_unless($incident->status === IncidentStatus::AwaitingApproval && $incident->responder_id, 409, 'A recommendation is required before approval.');
            $zone = Zone::findOrFail($incident->zone_id);
            $this->requireFreshZone($zone);
            $responder = Responder::whereKey($incident->responder_id)->lockForUpdate()->firstOrFail();
            $reason = $this->ineligibleReason($responder, $incident, $this->isStadiumDemo($incident, $zone));
            abort_if($reason !== null, 409, 'Recommendation is no longer valid: '.$reason);
            $responder->update(['available' => false]);
            $incident->update(['assigned_responder_id' => $responder->id, 'status' => IncidentStatus::Dispatched, 'approved_at' => now()]);
            $this->journal->append(EventType::ResponseStarted, ['responderId' => $responder->id, 'source' => $incident->source, 'deliveryStatus' => 'awaiting_acknowledgement', 'qodStatus' => 'not_requested'], $incident->zone_id, $incident->id, $actorId);

            return $incident;
        }, attempts: 3);
    }

    public function acknowledge(Incident $incident, int $actorId): Incident
    {
        return DB::transaction(function () use ($incident, $actorId) {
            $incident = Incident::whereKey($incident->id)->lockForUpdate()->firstOrFail();
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
            $zone = Zone::whereKey($incident->zone_id)->lockForUpdate()->firstOrFail();
            $incident = Incident::whereKey($incident->id)->lockForUpdate()->firstOrFail();
            if ($incident->status === IncidentStatus::Resolved) {
                return $incident;
            }
            $this->requireFreshZone($zone);
            abort_if(in_array($zone->risk_level, ['critical', 'unknown'], true), 409, 'Fresh non-critical evidence is required before resolution.');
            if ($incident->assigned_responder_id) {
                Responder::whereKey($incident->assigned_responder_id)->update(['available' => true]);
            }
            $incident->update(['status' => IncidentStatus::Resolved, 'active_zone_id' => null, 'assigned_responder_id' => null, 'resolved_at' => now()]);
            $this->journal->append(EventType::IncidentResolved, ['note' => $note, 'source' => $incident->source], $zone->id, $incident->id, $actorId);

            return $incident;
        }, attempts: 3);
    }

    private function ineligibleReason(Responder $responder, Incident $incident, bool $hybrid): ?string
    {
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
        if ($hybrid) {
            if (($signals['source'] ?? null) !== 'nokia_sandbox') {
                return 'nokia_sandbox_evidence_required';
            }
            $position = $responder->demo_position;
            if (($position['source'] ?? null) !== 'simulated_stadium_position'
                || ($position['venueId'] ?? null) !== StadiumDemo::VENUE
                || ! is_numeric($position['latitude'] ?? null) || ! is_numeric($position['longitude'] ?? null)
                || ! str_starts_with($responder->demo_key ?? '', StadiumDemo::VENUE.':')) {
                return 'stadium_position_missing';
            }
        } elseif (($signals['source'] ?? null) !== $incident->source) {
            return 'evidence_source_mismatch';
        }
        if (! data_get($signals, 'reachability.dataReachable')) {
            return 'data_reachability_unconfirmed';
        }
        if (! $this->fresh(data_get($signals, 'reachability.observedAt'))) {
            return 'reachability_stale_or_unknown';
        }
        if ($hybrid) {
            return null;
        }
        if (! $this->fresh(data_get($signals, 'location.observedAt'))) {
            return 'location_stale_or_unknown';
        }
        if (! is_numeric(data_get($signals, 'location.accuracyMeters')) || data_get($signals, 'location.accuracyMeters') > config('aman.max_location_accuracy_meters')) {
            return 'location_too_imprecise';
        }

        return null;
    }

    private function isStadiumDemo(Incident $incident, Zone $zone): bool
    {
        return config('aman.demo_enabled') && ! app()->environment('production')
            && config('camara.mode') === 'sandbox' && $incident->source === 'demo'
            && str_starts_with($zone->demo_key ?? '', StadiumDemo::VENUE.':');
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
