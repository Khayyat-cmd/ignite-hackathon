<?php

namespace App\Services;

use App\Jobs\RefreshResponder;
use App\Models\Responder;
use App\Models\Zone;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class StadiumDemo
{
    public const VENUE = 'aman-stadium-v1';

    public function __construct(private CrowdMonitor $monitor) {}

    public function assertEnabled(): void
    {
        abort_unless(config('aman.demo_enabled') && ! app()->environment('production') && config('camara.mode') === 'sandbox', 403, 'Stadium demo requires demo mode and Nokia sandbox mode outside production.');
    }

    public function assertZone(Zone $zone): void
    {
        $this->assertEnabled();
        abort_unless(str_starts_with($zone->demo_key ?? '', self::VENUE.':'), 422, 'This zone does not belong to the stadium demo.');
    }

    /** Creates a fictional, geographically anchored venue; never changes existing records. */
    public function setup(): array
    {
        $this->assertEnabled();

        return DB::transaction(function () {
            $zones = [];
            foreach (['East Entrance' => 0.0, 'North Concourse' => 0.002, 'West Entrance' => -0.002] as $name => $offset) {
                $key = self::VENUE.':'.$name;
                $zone = Zone::where('demo_key', $key)->first();
                if (! $zone) {
                    $lat = 47.486276;
                    $lon = 19.079156 + $offset;
                    $zone = new Zone([
                        'name' => 'DEMO - '.$name, 'area_sqm' => 1000,
                        'warning_density' => 1, 'critical_density' => 2, 'people_per_device' => 1,
                        'calibration_note' => 'Fictional stadium: simulated 1:1 calibration, usable area and thresholds. Not a surveyed venue or safety standard.',
                        'latitude' => $lat, 'longitude' => $lon,
                    ]);
                    $zone->forceFill([
                        'demo_key' => $key,
                        'boundary' => [
                            ['latitude' => $lat - 0.0003, 'longitude' => $lon - 0.0003],
                            ['latitude' => $lat - 0.0003, 'longitude' => $lon + 0.0003],
                            ['latitude' => $lat + 0.0003, 'longitude' => $lon + 0.0003],
                            ['latitude' => $lat + 0.0003, 'longitude' => $lon - 0.0003],
                        ],
                    ])->save();
                }
                $zones[] = $zone;
            }
            $responders = [];
            $responderDefinitions = [
                ['phone' => '+99999991001', 'zone_index' => 0], // Data connected
                ['phone' => '+99999991003', 'zone_index' => 1], // Disconnected
                ['phone' => '+99999991002', 'zone_index' => 2], // Data and SMS connected
                ['phone' => '+99999991000', 'zone_index' => 0], // SMS connected
            ];
            foreach ($responderDefinitions as $index => $definition) {
                $key = self::VENUE.':responder:'.$index;
                $responder = Responder::where('demo_key', $key)->first();
                if (! $responder) {
                    $zone = $zones[$definition['zone_index']];
                    $responder = new Responder(['name' => 'Stadium Responder '.($index + 1), 'role' => 'crowd_marshal', 'phone_number' => $definition['phone'], 'authorized' => true]);
                    $responder->forceFill(['demo_key' => $key, 'demo_position' => [
                        'source' => 'simulated_stadium_position', 'venueId' => self::VENUE,
                        'zoneId' => $zone->id,
                        'latitude' => $zone->latitude, 'longitude' => $zone->longitude,
                    ]])->save();
                }
                $responders[] = $responder;
            }

            return ['venueId' => self::VENUE, 'locationBasis' => 'fictional_geographic_anchor', 'zones' => $zones, 'responders' => $responders];
        });
    }

    public function control(Zone $zone, string $action, ?int $actorId): Zone
    {
        $this->assertZone($zone);

        return DB::transaction(function () use ($zone, $action, $actorId) {
            $zone = Zone::whereKey($zone->id)->lockForUpdate()->firstOrFail();
            $count = (int) data_get($zone->latest_reading, 'deviceCount', 600);
            $zone->forceFill(['scenario' => [
                'active' => $action !== 'pause', 'action' => $action,
                'target' => $action === 'crowded' ? 2500 : 600,
                'startedAt' => now()->toISOString(), 'actorId' => $actorId,
            ]])->save();
            if ($action !== 'pause') {
                $this->reading($zone, $count, $actorId);
            }

            return $zone->fresh();
        });
    }

    /** One bounded tick; scheduler calls every five seconds without making provider calls. */
    public function tick(): void
    {
        if (! config('aman.demo_enabled') || app()->environment('production') || config('camara.mode') !== 'sandbox') {
            return;
        }
        Zone::where('demo_key', 'like', self::VENUE.':%')->each(function (Zone $zone) {
            DB::transaction(function () use ($zone) {
                $zone = Zone::whereKey($zone->id)->lockForUpdate()->firstOrFail();
                $scenario = $zone->scenario;
                if (! ($scenario['active'] ?? false)) {
                    return;
                }
                if (now()->diffInSeconds(CarbonImmutable::parse($scenario['startedAt']), true) > 1800) {
                    $scenario['active'] = false;
                    $zone->forceFill(['scenario' => $scenario])->save();

                    return;
                }
                $count = (int) data_get($zone->latest_reading, 'deviceCount', 600);
                $target = (int) $scenario['target'];
                $count += max(-250, min(250, $target - $count));
                $this->reading($zone, $count, $scenario['actorId']);
            });
        });
    }

    public function refreshRoster(?int $actorId = null): void
    {
        $this->assertEnabled();
        Responder::where('demo_key', 'like', self::VENUE.':%')->where('authorized', true)->each(
            fn (Responder $responder) => RefreshResponder::dispatch($responder->id, $actorId)
        );
    }

    private function reading(Zone $zone, int $count, ?int $actorId): void
    {
        if ($zone->last_observed_at && $zone->last_observed_at->gte(now())) {
            return;
        }
        $this->monitor->ingest($zone, ['sampleId' => (string) Str::uuid(), 'deviceCount' => $count, 'observedAt' => now()->toISOString()], 'demo', $actorId);
    }
}
