<?php

namespace App\Jobs;

use App\Enums\EventType;
use App\Exceptions\IntegrationUnavailable;
use App\Models\Zone;
use App\Services\EventJournal;
use App\Services\Population\OrangePopulationDensity;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;

class RefreshPopulationContext implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 40;

    public int $uniqueFor = 120;

    public function __construct(public string $zoneId, public ?int $actorId) {}

    public function uniqueId(): string
    {
        return $this->zoneId;
    }

    /**
     * Execute the job.
     */
    public function handle(OrangePopulationDensity $provider, EventJournal $journal): void
    {
        $zone = Zone::find($this->zoneId);
        if (! $zone || ! $zone->boundary) {
            return;
        }
        $start = now()->toImmutable()->utc();
        $context = ['source' => 'orange_playground', 'usage' => 'area_context_only_not_risk_input', 'checkedAt' => $start->toISOString()];
        try {
            $context['result'] = $provider->retrieve($zone->boundary, $start, $start->addMinutes(30));
            $context['state'] = 'received';
        } catch (IntegrationUnavailable $exception) {
            $context['state'] = 'unavailable';
            $context['error'] = $exception->reason;
            $context['result'] = null;
        }
        DB::transaction(function () use ($context, $journal) {
            $zone = Zone::whereKey($this->zoneId)->lockForUpdate()->firstOrFail();
            if (($zone->population_context['checkedAt'] ?? '') > $context['checkedAt']) {
                return;
            }
            $zone->forceFill(['population_context' => $context])->save();
            $journal->append(EventType::PopulationUpdated, $context, $zone->id, actorId: $this->actorId);
        });
    }
}
