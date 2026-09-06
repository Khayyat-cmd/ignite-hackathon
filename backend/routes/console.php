<?php

use App\Services\Simulation\EventSimulation;
use Illuminate\Support\Facades\Schedule;

Schedule::call(fn () => app(EventSimulation::class)->tick())
    ->name('event-location-simulation')
    ->everyFiveSeconds()
    ->withoutOverlapping(1);
