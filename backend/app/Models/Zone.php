<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class Zone extends Model
{
    use BelongsToOrganization, HasUuids;

    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected $fillable = ['organization_id', 'venue_event_id', 'name', 'area_sqm', 'warning_density', 'critical_density', 'people_per_device', 'calibration_note', 'latitude', 'longitude'];

    protected function casts(): array
    {
        return ['area_sqm' => 'float', 'warning_density' => 'float', 'critical_density' => 'float', 'people_per_device' => 'float', 'latitude' => 'float', 'longitude' => 'float', 'latest_reading' => 'array', 'last_observed_at' => 'immutable_datetime', 'noncritical_since' => 'immutable_datetime', 'boundary' => 'array', 'scenario' => 'array', 'population_context' => 'array'];
    }
}
