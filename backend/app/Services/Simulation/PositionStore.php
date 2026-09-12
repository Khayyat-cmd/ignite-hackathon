<?php

namespace App\Services\Simulation;

use Illuminate\Support\Facades\Storage;

/**
 * Attendee positions for one simulation run, held on disk instead of in the run row.
 *
 * A tick rewrites the position of every attendee, and at 6,000 attendees that is about
 * 1.4 MB. Keeping it in `simulation_runs.snapshot` meant MySQL wrote the whole blob —
 * twice, because row-format binary logging records the before and after image — every
 * five seconds, which filled the server's disk with binary logs. Only the Unity screen
 * reads positions, and only ever the latest one, so a file per run is the right store:
 * the reads are the same slice they always were and the writes never reach the binlog.
 */
final class PositionStore
{
    /**
     * Replace the stored positions for a run.
     *
     * @param  list<array<string, mixed>>  $positions
     */
    public function put(int $runId, array $positions): void
    {
        Storage::disk('local')->put($this->path($runId), json_encode($positions, JSON_THROW_ON_ERROR));
    }

    /**
     * One page of a run's positions, empty when the run has not been sampled yet.
     *
     * @return list<array<string, mixed>>
     */
    public function slice(int $runId, int $offset, int $limit): array
    {
        return array_slice($this->all($runId), $offset, $limit);
    }

    /**
     * Every stored position for a run.
     *
     * @return list<array<string, mixed>>
     */
    public function all(int $runId): array
    {
        $stored = Storage::disk('local')->get($this->path($runId));
        if ($stored === null) {
            return [];
        }
        $positions = json_decode($stored, true);

        return is_array($positions) ? $positions : [];
    }

    public function forget(int $runId): void
    {
        Storage::disk('local')->delete($this->path($runId));
    }

    private function path(int $runId): string
    {
        return "simulation/positions/{$runId}.json";
    }
}
