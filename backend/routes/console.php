<?php

use App\Jobs\PublishDomainEvent;
use App\Models\DomainEvent;
use App\Models\Zone;
use App\Services\StadiumDemo;
use Illuminate\Support\Facades\Schedule;

Schedule::call(fn () => app(StadiumDemo::class)->tick())->name('stadium-demo-tick')->everyFiveSeconds()->withoutOverlapping();
Schedule::call(function () {
    if (config('aman.demo_enabled') && ! app()->environment('production') && config('camara.mode') === 'sandbox'
        && Zone::where('demo_key', 'like', StadiumDemo::VENUE.':%')->where('scenario->active', true)->exists()) {
        app(StadiumDemo::class)->refreshRoster();
    }
})->name('stadium-demo-network')->everyMinute()->withoutOverlapping();

Schedule::call(function () {
    if (in_array(config('broadcasting.default'), ['null', 'log'], true)) {
        return;
    }
    DomainEvent::whereNull('published_at')->orderBy('id')->limit(100)->get(['id'])->each(
        fn (DomainEvent $event) => PublishDomainEvent::dispatch($event->id)
    );
})->name('publish-outbox')->everySecond()->withoutOverlapping();
