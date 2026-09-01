<?php

namespace App\Services;

use App\Enums\EventType;
use App\Enums\IncidentStatus;
use App\Models\Incident;
use App\Models\Zone;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class CrowdMonitor
{
    public function __construct(private EventJournal $journal) {}

    /** The source is set by trusted server code, never by a browser-supplied field. */
    public function ingest(Zone $zone, array $input, string $source, ?int $actorId = null): array
    {
        $observedAt = CarbonImmutable::parse($input['observedAt'])->utc();
        $now = CarbonImmutable::now();
        if ($observedAt->lt($now->subSeconds(config('aman.observation_max_age_seconds'))) || $observedAt->gt($now->addSeconds(config('aman.future_tolerance_seconds')))) {
            throw ValidationException::withMessages(['observedAt' => 'Observation is stale or too far in the future.']);
        }
        $hash = hash('sha256', json_encode([$zone->id, $input['deviceCount'], $observedAt->toISOString(), $source], JSON_THROW_ON_ERROR));

        try {
            return DB::transaction(function () use ($zone, $input, $source, $observedAt, $hash, $actorId) {
                $zone = Zone::whereKey($zone->id)->lockForUpdate()->firstOrFail();
                $existing = DB::table('crowd_observations')->where('sample_id', $input['sampleId'])->first();
                if ($existing) {
                    abort_unless(hash_equals($existing->payload_hash, $hash), 409, 'Sample ID was already used for different data.');

                    return ['accepted' => false, 'duplicate' => true, 'zoneId' => $zone->id];
                }
                abort_if($zone->last_observed_at && $observedAt->lte($zone->last_observed_at), 409, 'Out-of-order observation; newer data is already stored.');
                DB::table('crowd_observations')->insert([
                    'zone_id' => $zone->id, 'sample_id' => $input['sampleId'], 'payload_hash' => $hash,
                    'device_count' => $input['deviceCount'], 'source' => $source, 'observed_at' => $observedAt->format('Y-m-d H:i:s.u'),
                ]);

                $people = $zone->people_per_device === null ? null : $input['deviceCount'] * $zone->people_per_device;
                $density = $people === null ? null : $people / $zone->area_sqm;
                $risk = $density === null ? 'unknown' : ($density >= $zone->critical_density ? 'critical' : ($density >= $zone->warning_density ? 'warning' : 'normal'));
                // Hysteresis prevents a tiny oscillation from immediately clearing the critical indication.
                if ($risk !== 'unknown' && $zone->risk_level === 'critical' && $density >= $zone->critical_density * config('aman.recovery_fraction')) {
                    $risk = 'critical';
                }
                $reading = [
                    'sampleId' => $input['sampleId'], 'source' => $source, 'observedAt' => $observedAt->toISOString(),
                    'deviceCount' => $input['deviceCount'], 'estimatedPeople' => $people,
                    'areaSquareMeters' => $zone->area_sqm, 'densityPerSquareMeter' => $density === null ? null : round($density, 4),
                    'criticalDensityThreshold' => $zone->critical_density, 'riskLevel' => $risk,
                    'estimateBasis' => $people === null ? 'device_count_only' : 'configured_people_per_device',
                ];
                $zone->forceFill(['latest_reading' => $reading, 'last_observed_at' => $observedAt, 'risk_level' => $risk])->save();
                $event = $this->journal->append(EventType::DensityUpdated, $reading, $zone->id, actorId: $actorId);

                if ($risk === 'critical' && ! Incident::where('active_zone_id', $zone->id)->exists()) {
                    $incident = Incident::create(['organization_id' => $zone->organization_id, 'venue_event_id' => $zone->venue_event_id, 'zone_id' => $zone->id, 'active_zone_id' => $zone->id, 'source' => $source, 'status' => IncidentStatus::Detected]);
                    $this->journal->append(EventType::DangerDetected, ['source' => $source, 'reason' => 'configured_density_threshold_breached', 'requiresHumanReview' => true, 'reading' => $reading], $zone->id, $incident->id, $actorId);
                }

                return ['accepted' => true, 'duplicate' => false, 'event' => $event->envelope()];
            }, attempts: 3);
        } catch (UniqueConstraintViolationException) {
            abort(409, 'Concurrent duplicate observation. Retry with the same sample ID.');
        }
    }
}
