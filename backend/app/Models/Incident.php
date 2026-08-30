<?php

namespace App\Models;

use App\Enums\IncidentStatus;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class Incident extends Model
{
    use HasUuids;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['status' => IncidentStatus::class, 'decision' => 'array', 'approved_at' => 'immutable_datetime', 'resolved_at' => 'immutable_datetime'];
    }
}
