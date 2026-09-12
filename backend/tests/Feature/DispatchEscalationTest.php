<?php

namespace Tests\Feature;

use App\Enums\IncidentStatus;
use App\Jobs\GenerateIncidentAdvice;
use App\Jobs\MonitorUnacknowledgedDispatches;
use App\Jobs\SendResponderPushNotification;
use App\Models\Incident;
use App\Models\Responder;
use App\Models\User;
use App\Models\Zone;
use App\Services\IncidentWorkflow;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class DispatchEscalationTest extends TestCase
{
    use RefreshDatabase;

    private User $operator;

    private Zone $zone;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'app.key' => 'base64:'.base64_encode(str_repeat('a', 32)),
            'aman.agent.url' => null,
            'services.openai.key' => null,
            'aman.dispatch_ack_timeout_seconds' => 20,
        ]);
        $this->travelTo(CarbonImmutable::parse('2026-09-12T12:00:00Z'));
        $this->operator = User::factory()->create();
        $this->zone = Zone::create([
            'organization_id' => $this->operator->organization_id,
            'name' => 'East Entrance',
            'area_sqm' => 100,
            'warning_density' => 1,
            'critical_density' => 2,
            'latitude' => 33.9,
            'longitude' => 35.5,
        ]);
        $this->zone->forceFill(['risk_level' => 'critical', 'last_observed_at' => now()])->save();
    }

    public function test_a_reachable_responder_receives_one_reminder_after_twenty_seconds(): void
    {
        Queue::fake();
        [$incident] = $this->dispatchedIncident(true);

        app(MonitorUnacknowledgedDispatches::class)->handle(app(IncidentWorkflow::class));
        Queue::assertNothingPushed();

        $this->travel(20)->seconds();
        app(MonitorUnacknowledgedDispatches::class)->handle(app(IncidentWorkflow::class));
        app(MonitorUnacknowledgedDispatches::class)->handle(app(IncidentWorkflow::class));

        Queue::assertPushed(SendResponderPushNotification::class, 1);
        Queue::assertPushed(SendResponderPushNotification::class,
            fn (SendResponderPushNotification $job): bool => $job->data['type'] === 'mission_reminder');
        $this->assertSame('dispatched', $incident->fresh()->status->value);
        $this->assertSame('reminder_sent', data_get($incident->fresh()->decision, 'escalation.outcome'));
    }

    public function test_an_unreachable_responder_is_replaced_by_a_new_recommendation(): void
    {
        Queue::fake();
        [$incident, $assigned] = $this->dispatchedIncident(false);
        $alternate = $this->responder('Alternate Responder', true, 33.9007);
        $this->travel(20)->seconds();

        app(MonitorUnacknowledgedDispatches::class)->handle(app(IncidentWorkflow::class));

        $incident->refresh();
        $this->assertSame(IncidentStatus::AwaitingApproval, $incident->status);
        $this->assertSame($alternate->id, $incident->responder_id);
        $this->assertNull($incident->assigned_responder_id);
        $this->assertTrue($assigned->fresh()->available);
        $this->assertSame('deterministic_fallback', data_get($incident->decision, 'recommendationSource'));
        $this->assertSame('reassignment_requested', data_get($incident->decision, 'escalation.outcome'));
        Queue::assertNotPushed(SendResponderPushNotification::class);

        app(IncidentWorkflow::class)->approve($incident, $this->operator->id);
        $this->assertNull(data_get($incident->fresh()->decision, 'escalation'));
    }

    public function test_an_unreachable_responder_returns_to_the_agent_when_it_is_enabled(): void
    {
        Queue::fake();
        config(['aman.agent.url' => 'http://127.0.0.1:8091']);
        [$incident, $assigned] = $this->dispatchedIncident(false);
        $this->responder('Alternate Responder', true, 33.9007);
        $this->travel(20)->seconds();

        app(MonitorUnacknowledgedDispatches::class)->handle(app(IncidentWorkflow::class));

        $incident->refresh();
        $this->assertSame(IncidentStatus::Detected, $incident->status);
        $this->assertNull($incident->responder_id);
        $this->assertNull($incident->assigned_responder_id);
        $this->assertTrue($assigned->fresh()->available);
        $this->assertSame('pending', data_get($incident->decision, 'adviceStatus'));
        $this->assertSame('pending_agent', data_get($incident->decision, 'recommendationSource'));
        $this->assertSame('reassignment_requested', data_get($incident->decision, 'escalation.outcome'));
        Queue::assertPushed(GenerateIncidentAdvice::class, 1);
        Queue::assertNotPushed(SendResponderPushNotification::class);
    }

    /** @return array{Incident, Responder} */
    private function dispatchedIncident(bool $reachable): array
    {
        $responder = $this->responder('Assigned Responder', $reachable, 33.9002);
        $responder->update(['available' => false]);
        $incident = Incident::create([
            'organization_id' => $this->operator->organization_id,
            'zone_id' => $this->zone->id,
            'active_zone_id' => $this->zone->id,
            'responder_id' => $responder->id,
            'assigned_responder_id' => $responder->id,
            'status' => IncidentStatus::Dispatched,
            'required_role' => 'crowd_marshal',
            'source' => 'demo',
            'decision' => [],
            'approved_at' => now(),
        ]);

        return [$incident, $responder];
    }

    private function responder(string $name, bool $reachable, float $latitude): Responder
    {
        $responder = Responder::create([
            'organization_id' => $this->operator->organization_id,
            'name' => $name,
            'role' => 'crowd_marshal',
            'phone_number' => '+99999991001',
            'authorized' => true,
        ]);
        $responder->forceFill(['signals' => [
            'source' => 'demo',
            'location' => ['latitude' => $latitude, 'longitude' => 35.5,
                'accuracyMeters' => 5, 'observedAt' => now()->toISOString()],
            'reachability' => ['dataReachable' => $reachable, 'observedAt' => now()->toISOString()],
        ]])->save();

        return $responder;
    }
}
