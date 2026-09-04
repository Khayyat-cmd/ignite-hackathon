<?php

namespace App\Console\Commands;

use App\Services\Simulation\NokiaReachability;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('aman:reachability-check')]
#[Description('Check the four linked Nokia sandbox reachability scenarios.')]
class CheckNokiaReachability extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(NokiaReachability $service): int
    {
        $service->refresh();
        $rows = [];
        foreach (range(0, 3) as $index) {
            $result = $service->forResponder($index);
            $rows[] = [$index + 1, $result['sandboxDevice'], $result['status'],
                $result['dataReachable'] ? 'DATA' : 'No confirmed data', $result['error'] ?? ''];
        }
        $this->table(['Responder', 'Sandbox device', 'Check', 'Reachability', 'Details'], $rows);

        return collect($rows)->contains(fn ($row) => $row[2] !== 'ok') ? self::FAILURE : self::SUCCESS;
    }
}
