<?php

use App\Jobs\PublishDomainEvent;
use App\Models\DomainEvent;
use Illuminate\Support\Facades\Schedule;

Schedule::call(function () {
    if (in_array(config('broadcasting.default'), ['null', 'log'], true)) {
        return;
    }
    DomainEvent::whereNull('published_at')->orderBy('id')->limit(100)->get(['id'])->each(
        fn (DomainEvent $event) => PublishDomainEvent::dispatch($event->id)
    );
})->name('publish-outbox')->everySecond()->withoutOverlapping();
