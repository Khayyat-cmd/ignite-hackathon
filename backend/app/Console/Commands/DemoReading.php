<?php

namespace App\Console\Commands;

use App\Models\Responder;
use App\Models\Zone;
use App\Services\CrowdMonitor;
use App\Services\ResponderSignals;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

class DemoReading extends Command
{
    protected $signature = 'aman:demo {--count=60 : Simulated device count}';

    protected $description = 'Seed a clearly labelled local demo zone/responders and publish one synthetic crowd reading.';

    public function handle(CrowdMonitor $monitor, ResponderSignals $signals): int
    {
        if (! config('aman.demo_enabled') || app()->environment('production')) {
            $this->error('Enable AMAN_DEMO_ENABLED=true in a non-production environment.');

            return self::FAILURE;
        }
        $count = filter_var($this->option('count'), FILTER_VALIDATE_INT);
        if ($count === false || $count < 0 || $count > 10000000) {
            $this->error('Count must be an integer between 0 and 10000000.');

            return self::FAILURE;
        }
        $zone = Zone::firstOrCreate(['name' => 'DEMO - North Concourse'], [
            'area_sqm' => 100, 'warning_density' => 1, 'critical_density' => 2,
            'people_per_device' => 1, 'calibration_note' => 'DEMO ONLY: fictional 1:1 mapping and thresholds; not a venue safety standard.',
            'latitude' => 33.9, 'longitude' => 35.5,
        ]);
        foreach ([['DEMO - R-04', false, 33.9001], ['DEMO - R-02', true, 33.9007]] as [$name, $reachable, $latitude]) {
            $responder = Responder::firstOrCreate(['name' => $name], ['role' => 'crowd_marshal', 'phone_number' => $reachable ? '+99999991001' : '+99999991003', 'authorized' => true]);
            $now = now()->toISOString();
            $signals->store($responder, ['source' => 'demo', 'checkedAt' => $now, 'location' => ['latitude' => $latitude, 'longitude' => 35.5, 'accuracyMeters' => 5, 'observedAt' => $now], 'reachability' => ['reachable' => $reachable, 'dataReachable' => $reachable, 'connectivity' => $reachable ? ['DATA'] : [], 'observedAt' => $now]], null);
        }
        $monitor->ingest($zone, ['sampleId' => (string) Str::uuid(), 'deviceCount' => $count, 'observedAt' => now()->toISOString()], 'demo');
        $this->info('Synthetic reading stored. Zone: '.$zone->id);
        $this->line('No Nokia API was called. Use GET /api/v1/events to inspect the event stream.');

        return self::SUCCESS;
    }
}
