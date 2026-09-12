<?php

namespace App\Services\Ai;

use App\Models\Incident;
use App\Models\Responder;
use App\Models\Zone;
use App\Services\Simulation\LocationProvider;
use Carbon\CarbonImmutable;
use RuntimeException;

/**
 * Normalizes AMAN's network evidence into the single CAMARA-shaped record the
 * orchestration agent consumes. Phone numbers, provider credentials and raw
 * coordinates never leave this class.
 *
 * Provenance is declared per operation and never upgraded: device reachability
 * is a live Nokia Network-as-Code call, while location verification and
 * congestion insights are served by AMAN's venue simulation for the demo.
 */
final class CamaraEvidenceGateway
{
    public const OPERATIONS = ['device_reachability', 'location_verification', 'congestion_insights'];

    private const LIVE_PROVIDER = 'nokia_network_as_code';

    private const SIMULATED_PROVIDER = 'aman_venue_simulation';

    public function __construct(private LocationProvider $locations) {}

    /**
     * @return array<string, mixed>
     */
    public function evidence(Incident $incident, string $operation, ?string $responderId): array
    {
        $zone = Zone::where('organization_id', $incident->organization_id)->findOrFail($incident->zone_id);
        $responder = $responderId === null ? null : $this->eligibleResponder($incident, $responderId);

        return match ($operation) {
            'device_reachability' => $this->reachability($responder),
            'location_verification' => $this->locationVerification($responder, $zone),
            'congestion_insights' => $this->congestion($incident),
            default => throw new RuntimeException('Unsupported CAMARA operation.'),
        };
    }

    /**
     * The agent may only ask about a responder the backend already ranked as
     * eligible for this incident, so a hallucinated ID cannot reach a provider.
     */
    private function eligibleResponder(Incident $incident, string $responderId): Responder
    {
        $eligible = collect(data_get($incident->decision, 'candidates', []))->pluck('responderId')->all();
        if (! in_array($responderId, $eligible, true)) {
            throw new RuntimeException('Responder is outside the eligible candidate allowlist.');
        }

        return Responder::where('organization_id', $incident->organization_id)->findOrFail($responderId);
    }

    /**
     * @return array<string, mixed>
     */
    private function reachability(?Responder $responder): array
    {
        if ($responder === null) {
            throw new RuntimeException('Device reachability requires a responder.');
        }
        $live = data_get($responder->signals, 'reachability');
        if (data_get($live, 'status') === 'ok') {
            return $this->record('device_reachability', self::LIVE_PROVIDER, 'device-status/device-reachability-status/v1', 'live_camara', [
                'responderId' => $responder->id,
                'status' => 'ok',
                'dataReachable' => (bool) data_get($live, 'dataReachable'),
                'observedAt' => $this->time(data_get($live, 'observedAt')),
            ]);
        }

        // The Nokia sandbox is rate limited and occasionally returns nothing. The
        // venue simulation then stands in, labelled as the simulation it is.
        $simulated = data_get($responder->signals, 'rawResponses.reachability');
        if (! is_array($simulated) || ! is_bool($simulated['reachable'] ?? null)) {
            return $this->record('device_reachability', self::LIVE_PROVIDER, 'device-status/device-reachability-status/v1', 'live_camara', [
                'responderId' => $responder->id,
                'status' => 'unavailable',
            ]);
        }

        return $this->record('device_reachability', self::SIMULATED_PROVIDER, 'device-status/device-reachability-status/v1', 'simulated_fixture', [
            'responderId' => $responder->id,
            'status' => 'ok',
            'dataReachable' => $simulated['reachable'] && in_array('DATA', $simulated['connectivity'] ?? [], true),
            'observedAt' => $this->time($simulated['lastStatusTime'] ?? null),
        ]);
    }

    /**
     * Verifies the responder against the incident zone rather than against a
     * mission they have not been given yet.
     *
     * @return array<string, mixed>
     */
    private function locationVerification(?Responder $responder, Zone $zone): array
    {
        if ($responder === null) {
            throw new RuntimeException('Location verification requires a responder.');
        }
        $location = data_get($responder->signals, 'location');
        $accuracy = (float) data_get($location, 'accuracyMeters', 0);
        $observedAt = $this->time(data_get($location, 'observedAt'));
        $api = 'location-verification/v1';
        if (! is_array($location) || ! isset($location['latitude'], $location['longitude']) || $observedAt === null) {
            return $this->record('location_verification', self::SIMULATED_PROVIDER, $api, 'simulated_fixture', [
                'responderId' => $responder->id,
                'status' => 'ok',
                'verificationResult' => 'UNKNOWN',
            ]);
        }

        // A zone is a polygon on the schematic; CAMARA verifies against a circle,
        // so the zone is compared as the circle covering the same floor area.
        $radius = max(1.0, sqrt($zone->area_sqm / M_PI));
        $verified = $this->locations->verify(
            ['lastLocationTime' => $observedAt, 'area' => [
                'areaType' => 'CIRCLE',
                'center' => ['latitude' => $location['latitude'], 'longitude' => $location['longitude']],
                'radius' => max(1.0, $accuracy),
            ]],
            ['areaType' => 'CIRCLE', 'center' => ['latitude' => $zone->latitude, 'longitude' => $zone->longitude], 'radius' => $radius],
        );
        $stale = CarbonImmutable::parse($observedAt)->lt(now()->subSeconds((int) config('aman.signal_max_age_seconds')));

        return $this->record('location_verification', self::SIMULATED_PROVIDER, $api, 'simulated_fixture', [
            'responderId' => $responder->id,
            'status' => 'ok',
            'verificationResult' => $stale ? 'UNKNOWN' : $verified['verificationResult'],
            'accuracyMeters' => round($accuracy, 1),
            'observedAt' => $observedAt,
        ]);
    }

    /**
     * Zone-level congestion. The simulation drives one congestion state for the
     * venue, so the freshest responder sample represents the incident zone.
     *
     * @return array<string, mixed>
     */
    private function congestion(Incident $incident): array
    {
        $api = 'network-insights/congestion-insights/v0';
        $sample = Responder::where('organization_id', $incident->organization_id)
            ->when($incident->venue_event_id !== null, fn ($query) => $query->where('venue_event_id', $incident->venue_event_id))
            ->orderBy('id')->get()
            ->map(fn (Responder $responder): mixed => data_get($responder->signals, 'congestion.0'))
            ->first(fn (mixed $entry): bool => is_array($entry) && isset($entry['congestionLevel']));
        if ($sample === null) {
            return $this->record('congestion_insights', self::SIMULATED_PROVIDER, $api, 'simulated_fixture', ['status' => 'unavailable']);
        }
        $level = strtolower((string) $sample['congestionLevel']);

        return $this->record('congestion_insights', self::SIMULATED_PROVIDER, $api, 'simulated_fixture', [
            'status' => 'ok',
            'congestionLevel' => in_array($level, ['low', 'medium', 'high'], true) ? $level : 'unknown',
            'confidence' => isset($sample['confidenceLevel']) ? round($sample['confidenceLevel'] / 100, 2) : null,
            'observedAt' => $this->time($sample['timeIntervalStop'] ?? null),
        ]);
    }

    /**
     * @param  array<string, mixed>  $fields
     * @return array<string, mixed>
     */
    private function record(string $operation, string $provider, string $api, string $source, array $fields): array
    {
        return array_filter([
            'operation' => $operation,
            'provider' => $provider,
            'api' => $api,
            'source' => $source,
            'checkedAt' => now()->toISOString(),
            ...$fields,
        ], fn (mixed $value): bool => $value !== null);
    }

    private function time(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? CarbonImmutable::parse($value)->toISOString() : null;
    }
}
