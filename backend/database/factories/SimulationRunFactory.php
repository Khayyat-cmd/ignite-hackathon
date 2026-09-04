<?php

namespace Database\Factories;

use App\Models\Organization;
use App\Models\SimulationRun;
use App\Models\User;
use App\Models\VenueEvent;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SimulationRun>
 */
class SimulationRunFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'organization_id' => Organization::factory(),
            'venue_event_id' => fn (array $attributes) => VenueEvent::factory()->create(['organization_id' => $attributes['organization_id']])->id,
            'created_by' => fn (array $attributes) => User::factory()->create(['organization_id' => $attributes['organization_id']])->id,
            'attendee_count' => 3000,
            'definition' => json_decode(file_get_contents(resource_path('simulation/stadium.json')), true, flags: JSON_THROW_ON_ERROR),
        ];
    }
}
