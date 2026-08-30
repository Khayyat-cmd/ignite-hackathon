<?php

namespace App\Console\Commands;

use App\Exceptions\IntegrationUnavailable;
use App\Services\Camara\NokiaNetwork;
use Illuminate\Console\Command;

class ProbeNokia extends Command
{
    protected $signature = 'aman:camara-probe';

    protected $description = 'Check read-only Nokia APIs using an official sandbox device; never creates subscriptions or QoD sessions.';

    public function handle(NokiaNetwork $network): int
    {
        if (config('camara.mode') !== 'sandbox') {
            $this->error('Set CAMARA_MODE=sandbox and CAMARA_API_KEY in your private .env first.');

            return self::FAILURE;
        }
        $failed = false;
        foreach (['location', 'reachability', 'congestion', 'verify'] as $operation) {
            try {
                if ($operation === 'verify') {
                    $network->verify('+99999991001', 50.735851, 7.10066, 50000);
                } else {
                    $network->{$operation}('+99999991001');
                }
                $this->info($operation.': API responded with a supported payload.');
            } catch (IntegrationUnavailable $e) {
                $failed = true;
                $this->warn($operation.': '.$e->reason.($e->providerStatus ? ' (HTTP '.$e->providerStatus.')' : ''));
            }
        }
        $this->line('Crowd-count APIs were not probed: their Nokia availability has not been confirmed.');

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
