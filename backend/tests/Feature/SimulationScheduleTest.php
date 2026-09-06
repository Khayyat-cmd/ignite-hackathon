<?php

namespace Tests\Feature;

use Illuminate\Console\Scheduling\Schedule;
use Tests\TestCase;

class SimulationScheduleTest extends TestCase
{
    public function test_simulation_mutex_expires_quickly_after_an_interrupted_tick(): void
    {
        $event = collect(app(Schedule::class)->events())
            ->firstWhere('description', 'event-location-simulation');

        $this->assertNotNull($event);
        $this->assertTrue($event->withoutOverlapping);
        $this->assertSame(1, $event->expiresAt);
    }
}
