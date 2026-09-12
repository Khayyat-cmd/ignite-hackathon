<?php

use App\Jobs\MonitorUnacknowledgedDispatches;
use App\Services\Simulation\EventSimulation;
use Illuminate\Support\Facades\Schedule;

Schedule::call(fn () => app(EventSimulation::class)->tick())
    ->name('event-location-simulation')
    ->everyFiveSeconds()
    ->withoutOverlapping(1);

Schedule::job(new MonitorUnacknowledgedDispatches)
    ->name('unacknowledged-dispatch-monitor')
    ->everyFiveSeconds()
    ->withoutOverlapping(1);
