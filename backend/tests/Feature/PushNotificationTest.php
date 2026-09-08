<?php

namespace Tests\Feature;

use App\Jobs\SendResponderPushNotification;
use App\Models\DevicePushToken;
use App\Models\Incident;
use App\Models\Responder;
use App\Models\SimulationRun;
use App\Services\Push\PushNotifier;
use App\Services\Simulation\EventSimulation;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

class PushNotificationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['aman.demo_enabled' => true, 'camara.mode' => 'disabled']);
        config(['aman.reachability.key' => 'test', 'aman.reachability.enabled' => true]);
        config(['services.openai.key' => null, 'services.firebase.project_id' => null]);
        Http::preventStrayRequests();
        Http::fake(fn ($request) => Http::response([
            'reachable' => true,
            'connectivity' => ['DATA'],
            'lastStatusTime' => now()->toISOString(),
        ]));
        $this->travelTo(CarbonImmutable::parse('2026-09-04T12:00:00Z'));
    }

    public function test_a_device_token_can_be_registered_refreshed_and_removed(): void
    {
        $runId = $this->postJson('/api/v1/demo/simulations', ['attendeeCount' => 6000])->assertCreated()->json('id');
        $responder = Responder::where('venue_event_id', SimulationRun::findOrFail($runId)->venue_event_id)->firstOrFail();
        $deviceId = (string) Str::uuid();

        $this->putJson("/api/v1/demo/devices/{$deviceId}/push-token", [
            'responderId' => $responder->id,
            'platform' => 'android',
            'token' => 'first-fcm-token',
        ])->assertCreated()->assertJsonPath('registered', true);
        $this->putJson("/api/v1/demo/devices/{$deviceId}/push-token", [
            'responderId' => $responder->id,
            'platform' => 'android',
            'token' => 'refreshed-fcm-token',
        ])->assertOk();

        $registration = DevicePushToken::sole();
        $this->assertSame('refreshed-fcm-token', $registration->token);
        $this->assertSame(hash('sha256', 'refreshed-fcm-token'), $registration->token_hash);

        $this->deleteJson("/api/v1/demo/devices/{$deviceId}/push-token", [
            'responderId' => $responder->id,
        ])->assertNoContent();
        $this->assertDatabaseCount('device_push_tokens', 0);
    }

    public function test_dispatch_and_operator_instruction_queue_responder_pushes(): void
    {
        Queue::fake();
        $runId = $this->postJson('/api/v1/demo/simulations', ['attendeeCount' => 6000])->assertCreated()->json('id');
        $run = SimulationRun::findOrFail($runId);
        $this->postJson("/api/v1/demo/simulations/{$run->id}/control", ['action' => 'start'])->assertOk();
        for ($i = 0; $i < 13; $i++) {
            $this->travel(5)->seconds();
            app(EventSimulation::class)->advance($run->id);
        }
        $incident = Incident::where('venue_event_id', $run->venue_event_id)->firstOrFail();

        $this->postJson("/api/v1/demo/incidents/{$incident->id}/approve", ['routeReviewed' => true])->assertOk();
        Queue::assertPushed(SendResponderPushNotification::class, fn ($job) => $job->data['type'] === 'mission_assigned');

        $this->postJson("/api/v1/demo/missions/{$incident->id}/messages", [
            'clientMessageId' => (string) Str::uuid(),
            'kind' => 'instruction',
            'body' => 'Use the east access lane.',
        ])->assertOk();
        Queue::assertPushed(SendResponderPushNotification::class, fn ($job) => $job->data['type'] === 'mission_message');
    }

    public function test_push_job_uses_the_provider_contract(): void
    {
        $notifier = new class implements PushNotifier
        {
            public array $sent = [];

            public function sendToResponder(string $responderId, array $notification): void
            {
                $this->sent = compact('responderId', 'notification');
            }
        };
        $job = new SendResponderPushNotification('responder-1', 'Assignment', 'Open AMAN', ['type' => 'mission_assigned']);
        $job->handle($notifier);

        $this->assertSame('responder-1', $notifier->sent['responderId']);
        $this->assertSame('mission_assigned', $notifier->sent['notification']['data']['type']);
    }
}
