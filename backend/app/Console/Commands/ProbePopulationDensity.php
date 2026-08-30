<?php

namespace App\Console\Commands;

use App\Exceptions\IntegrationUnavailable;
use App\Services\Population\OrangePopulationDensity;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Console\Command;

class ProbePopulationDensity extends Command
{
    protected $signature = 'aman:population-probe';

    protected $description = 'Check the configured Population Density provider using a small fictional sample area.';

    public function handle(OrangePopulationDensity $populationDensity): int
    {
        $start = new DateTimeImmutable('now', new DateTimeZone('UTC'));

        try {
            $result = $populationDensity->retrieve([
                ['latitude' => 33.8938, 'longitude' => 35.5018],
                ['latitude' => 33.8948, 'longitude' => 35.5018],
                ['latitude' => 33.8948, 'longitude' => 35.5028],
                ['latitude' => 33.8938, 'longitude' => 35.5028],
            ], $start, $start->modify('+30 minutes'));
        } catch (IntegrationUnavailable $exception) {
            $this->error($exception->reason.($exception->providerStatus ? ' (HTTP '.$exception->providerStatus.')' : ''));

            return self::FAILURE;
        }

        $periods = $result['timedPopulationDensityData'];
        $cells = is_array($periods[0]['cellPopulationDensityData'] ?? null) ? count($periods[0]['cellPopulationDensityData']) : 0;
        $this->info('Population Density responded successfully. Periods: '.count($periods).'; first-period cells: '.$cells.'.');
        $this->warn('Source: Orange Playground mocked data, not live crowd measurement.');

        return self::SUCCESS;
    }
}
