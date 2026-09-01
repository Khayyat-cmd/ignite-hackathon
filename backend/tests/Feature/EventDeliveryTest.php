<?php

namespace Tests\Feature;

use App\Enums\EventType;
use App\Events\OperationsEvent;
use App\Jobs\PublishDomainEvent;
use App\Models\DomainEvent;
use App\Models\User;
use App\Services\EventJournal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class EventDeliveryTest extends TestCase
{
    use RefreshDatabase;

    public function test_rollback_does_not_leave_an_event_for_a_state_change_that_never_happened(): void
    {
        DB::beginTransaction();
        app(EventJournal::class)->append(EventType::DensityUpdated, ['source' => 'demo']);
        DB::rollBack();
        $this->assertDatabaseCount('domain_events', 0);
    }

    public function test_delivery_is_idempotent_after_success(): void
    {
        config(['broadcasting.default' => 'reverb']);
        Event::fake([OperationsEvent::class]);
        $user = User::factory()->create();
        $event = DB::transaction(fn () => app(EventJournal::class)->append(EventType::DensityUpdated, ['source' => 'demo'], actorId: $user->id));
        $job = new PublishDomainEvent($event->id);
        $job->handle();
        $job->handle();
        Event::assertDispatchedTimes(OperationsEvent::class, 1);
        $this->assertNotNull($event->fresh()->published_at);
    }

    public function test_disabled_broadcasting_does_not_claim_events_were_published(): void
    {
        config(['broadcasting.default' => 'null']);
        $event = DB::transaction(fn () => app(EventJournal::class)->append(EventType::DensityUpdated, []));
        (new PublishDomainEvent($event->id))->handle();
        $this->assertNull(DomainEvent::first()->published_at);
    }
}
