<?php

namespace Database\Factories;

use App\Models\Organization;
use App\Models\VenueEvent;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<VenueEvent>
 */
class VenueEventFactory extends Factory
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
            'name' => fake()->sentence(3),
            'venue_name' => fake()->company().' Arena',
            'starts_at' => now()->addDay(),
            'ends_at' => now()->addDay()->addHours(4),
            'status' => 'draft',
        ];
    }
}
