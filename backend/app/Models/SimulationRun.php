<?php

namespace App\Models;

use Database\Factories\SimulationRunFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SimulationRun extends Model
{
    /** @use HasFactory<SimulationRunFactory> */
    use HasFactory;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['definition' => 'array', 'snapshot' => 'array', 'interventions' => 'array', 'last_tick_at' => 'immutable_datetime'];
    }
}
