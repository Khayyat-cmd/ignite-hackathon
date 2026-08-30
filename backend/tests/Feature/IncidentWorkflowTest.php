<?php

namespace Tests\Feature;

use App\Enums\IncidentStatus;
use App\Models\DomainEvent;
use App\Models\Incident;
use App\Models\Responder;
use App\Models\User;
use App\Models\Zone;
use App\Services\CrowdMonitor;
use App\Services\IncidentWorkflow;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class IncidentWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private User $operator;

    private Zone $zone;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:'.base64_encode(str_repeat('a', 32)), 'aman.demo_enabled' => true]);
        $this->freezeTime();
        $this->operator = User::factory()->create();
        Sanctum::actingAs($this->operator, ['read', 'operate', 'ingest']);
        $this->zone = Zone::create(['name' => 'Zone A', 'area_sqm' => 100, 'warning_density' => 1, 'critical_density' => 2, 'people_per_device' => 1, 'latitude' => 33.9, 'longitude' => 35.5]);
        $this->observe(250);
    }

    private function observe(int $count): void
    {
        app(CrowdMonitor::class)->ingest($this->zone, ['sampleId' => (string) Str::uuid(), 'deviceCount' => $count, 'observedAt' => now()->toISOString()], 'demo');
    }

    private function responder(bool $reachable, float $latitude = 33.9007): Responder
    {
        $responder = Responder::create(['name' => 'Responder', 'role' => 'crowd_marshal', 'phone_number' => '+99999991001', 'authorized' => true]);
        $responder->forceFill(['signals' => [
            'source' => 'demo', 'checkedAt' => now()->toISOString(),
            'location' => ['latitude' => $latitude, 'longitude' => 35.5, 'accuracyMeters' => 5, 'observedAt' => now()->toISOString()],
            'reachability' => ['dataReachable' => $reachable, 'observedAt' => now()->toISOString()],
        ]])->save();

        return $responder;
    }

    public function test_nearest_unreachable_responder_is_rejected_and_human_approval_is_required(): void
    {
        $near = $this->responder(false, 33.9001);
        $reachable = $this->responder(true);
        $id = Incident::first()->id;
        $this->postJson('/api/v1/incidents/'.$id.'/recommend')->assertOk()->assertJsonPath('responder_id', $reachable->id)->assertJsonPath('status', 'awaiting_approval');
        $this->assertTrue($reachable->fresh()->available);
        $this->assertSame($near->id, Incident::first()->decision['excluded'][0]['responderId']);
        $this->postJson('/api/v1/incidents/'.$id.'/approve')->assertUnprocessable();
        $this->postJson('/api/v1/incidents/'.$id.'/approve', ['routeReviewed' => true])->assertOk()->assertJsonPath('status', 'dispatched');
        $this->assertFalse($reachable->fresh()->available);
        $this->postJson('/api/v1/incidents/'.$id.'/approve', ['routeReviewed' => true])->assertOk();
        $this->assertSame(1, DomainEvent::where('type', 'response_started')->count());
    }

    public function test_full_lifecycle_resolves_only_after_fresh_recovery_evidence(): void
    {
        $responder = $this->responder(true);
        $id = Incident::first()->id;
        $base = '/api/v1/incidents/'.$id;
        $this->postJson($base.'/recommend')->assertOk();
        $this->postJson($base.'/approve', ['routeReviewed' => true])->assertOk();
        $this->postJson($base.'/acknowledge')->assertOk()->assertJsonPath('status', 'acknowledged');
        $this->postJson($base.'/resolve', ['note' => 'Operator verified clear exits.'])->assertConflict();
        $this->travel(1)->seconds();
        $this->observe(50);
        $this->postJson($base.'/resolve', ['note' => 'Operator verified clear exits.'])->assertOk()->assertJsonPath('status', 'resolved');
        $this->assertTrue($responder->fresh()->available);
        $this->assertNull(Incident::first()->active_zone_id);
        $this->postJson($base.'/resolve', ['note' => 'Duplicate retry.'])->assertOk();
        $this->assertSame(1, DomainEvent::where('type', 'incident_resolved')->count());
    }

    public function test_stale_signals_block_approval(): void
    {
        $this->responder(true);
        $id = Incident::first()->id;
        $this->postJson('/api/v1/incidents/'.$id.'/recommend')->assertOk();
        $this->travel(3)->minutes();
        $this->postJson('/api/v1/incidents/'.$id.'/approve', ['routeReviewed' => true])->assertConflict();
    }

    public function test_no_eligible_responder_keeps_incident_unassigned(): void
    {
        $this->responder(false);
        $this->postJson('/api/v1/incidents/'.Incident::first()->id.'/recommend')->assertOk()->assertJsonPath('responder_id', null)->assertJsonPath('decision.reason', 'no_eligible_responder');
        $this->assertSame(0, DomainEvent::where('type', 'response_started')->count());
    }

    public function test_responder_cannot_be_dispatched_to_two_incidents(): void
    {
        $responder = $this->responder(true);
        $workflow = app(IncidentWorkflow::class);
        $first = $workflow->recommend(Incident::first(), $this->operator->id);
        $otherZone = Zone::create(['name' => 'Zone B', 'area_sqm' => 100, 'warning_density' => 1, 'critical_density' => 2, 'latitude' => 33.9, 'longitude' => 35.5]);
        $otherZone->forceFill(['last_observed_at' => now(), 'risk_level' => 'critical'])->save();
        $second = Incident::create(['zone_id' => $otherZone->id, 'active_zone_id' => $otherZone->id, 'source' => 'demo', 'status' => IncidentStatus::AwaitingApproval, 'responder_id' => $responder->id]);
        $workflow->approve($first, $this->operator->id);
        $this->postJson('/api/v1/incidents/'.$second->id.'/approve', ['routeReviewed' => true])->assertConflict();
    }

    public function test_private_phone_numbers_are_not_returned_or_stored_in_plaintext(): void
    {
        $responder = $this->responder(true);
        $this->getJson('/api/v1/responders')->assertOk()->assertDontSee('+99999991001');
        $this->assertNotSame('+99999991001', $responder->getRawOriginal('phone_number'));
    }

    public function test_broadcast_channel_authorization_requires_read_permission(): void
    {
        config(['broadcasting.default' => 'reverb', 'broadcasting.connections.reverb.key' => 'test', 'broadcasting.connections.reverb.secret' => 'test-secret', 'broadcasting.connections.reverb.app_id' => 'test']);
        $this->postJson('/api/broadcasting/auth', ['socket_id' => '1.2', 'channel_name' => 'private-operations'])->assertOk()->assertJsonStructure(['auth']);
        Sanctum::actingAs($this->operator, ['ingest']);
        $this->postJson('/api/broadcasting/auth', ['socket_id' => '1.2', 'channel_name' => 'private-operations'])->assertForbidden();
    }
}
