<?php

namespace Tests\Feature;

use App\Models\DomainEvent;
use App\Models\Incident;
use App\Models\User;
use App\Models\Zone;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CrowdMonitoringTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['aman.demo_enabled' => true]);
        $this->freezeTime();
    }

    private function operator(array $abilities = ['read', 'operate', 'ingest']): void
    {
        Sanctum::actingAs(User::factory()->create(), $abilities);
    }

    private function zone(?float $ratio = 1): Zone
    {
        return Zone::create(['name' => 'Test zone', 'area_sqm' => 100, 'warning_density' => 1, 'critical_density' => 2, 'people_per_device' => $ratio, 'latitude' => 33.9, 'longitude' => 35.5]);
    }

    private function sample(int $count = 250): array
    {
        return ['sampleId' => (string) Str::uuid(), 'deviceCount' => $count, 'observedAt' => now()->toISOString()];
    }

    public function test_authentication_and_permissions_are_required(): void
    {
        $this->getJson('/api/v1/zones')->assertUnauthorized();
        $this->operator(['read']);
        $this->postJson('/api/v1/zones', [])->assertForbidden();
        $this->postJson('/api/v1/demo/zones/'.$this->zone()->id.'/observations', $this->sample())->assertForbidden();
    }

    public function test_reading_creates_one_incident_and_a_replayable_event(): void
    {
        $this->operator();
        $zone = $this->zone();
        $sample = $this->sample();
        $url = '/api/v1/demo/zones/'.$zone->id.'/observations';
        $this->postJson($url, $sample)->assertOk()->assertJsonPath('event.event', 'density_updated')->assertJsonPath('event.data.source', 'demo')->assertJsonPath('event.data.densityPerSquareMeter', 2.5);
        $this->postJson($url, $sample)->assertOk()->assertJsonPath('duplicate', true);
        $this->assertDatabaseCount('incidents', 1);
        $this->assertDatabaseCount('domain_events', 2);
        $firstId = DomainEvent::orderBy('id')->first()->id;
        $this->getJson('/api/v1/events?limit=1')->assertOk()->assertJsonPath('data.0.schemaVersion', 1)->assertJsonPath('data.0.sequence', (string) $firstId)->assertJsonPath('hasMore', true);
        $this->getJson('/api/v1/events?after='.$firstId)->assertJsonPath('data.0.event', 'danger_detected')->assertJsonPath('hasMore', false);
    }

    public function test_reused_sample_id_with_changed_data_is_rejected(): void
    {
        $this->operator();
        $url = '/api/v1/demo/zones/'.$this->zone()->id.'/observations';
        $sample = $this->sample();
        $this->postJson($url, $sample)->assertOk();
        $sample['deviceCount'] = 10;
        $this->postJson($url, $sample)->assertConflict();
        $this->assertDatabaseCount('crowd_observations', 1);
    }

    public function test_stale_future_and_out_of_order_samples_are_rejected(): void
    {
        $this->operator();
        $url = '/api/v1/demo/zones/'.$this->zone()->id.'/observations';
        $sample = $this->sample();
        $sample['observedAt'] = now()->subMinutes(3)->toISOString();
        $this->postJson($url, $sample)->assertUnprocessable();
        $sample['observedAt'] = now()->addMinute()->toISOString();
        $this->postJson($url, $sample)->assertUnprocessable();
        $this->postJson($url, $this->sample())->assertOk();
        $sample = $this->sample();
        $sample['observedAt'] = now()->subSecond()->toISOString();
        $this->postJson($url, $sample)->assertConflict();
    }

    public function test_device_count_is_not_silently_converted_to_people(): void
    {
        $this->operator();
        $this->postJson('/api/v1/demo/zones/'.$this->zone(null)->id.'/observations', $this->sample())
            ->assertOk()->assertJsonPath('event.data.estimatedPeople', null)->assertJsonPath('event.data.riskLevel', 'unknown');
        $this->assertDatabaseCount('incidents', 0);
    }

    public function test_demo_requires_explicit_enablement_and_cannot_claim_to_be_live(): void
    {
        $this->operator();
        $url = '/api/v1/demo/zones/'.$this->zone()->id.'/observations';
        $this->postJson($url, [...$this->sample(), 'source' => 'nokia_live'])->assertUnprocessable();
        config(['aman.demo_enabled' => false]);
        $this->postJson($url, $this->sample())->assertForbidden();
        $this->assertDatabaseCount('crowd_observations', 0);
    }

    public function test_invalid_counts_and_zero_area_are_rejected(): void
    {
        $this->operator();
        $url = '/api/v1/demo/zones/'.$this->zone()->id.'/observations';
        $this->postJson($url, $this->sample(-1))->assertUnprocessable();
        $this->postJson($url, [...$this->sample(), 'deviceCount' => 1.5])->assertUnprocessable();
        $this->postJson('/api/v1/zones', ['name' => 'Invalid', 'area_sqm' => 0, 'warning_density' => 2, 'critical_density' => 1, 'latitude' => 0, 'longitude' => 0])->assertUnprocessable();
    }

    public function test_small_density_drop_does_not_clear_critical_status_or_resolve_incident(): void
    {
        $this->operator();
        $zone = $this->zone();
        $url = '/api/v1/demo/zones/'.$zone->id.'/observations';
        $this->postJson($url, $this->sample())->assertOk();
        $this->travel(1)->seconds();
        $this->postJson($url, $this->sample(190))->assertJsonPath('event.data.riskLevel', 'critical');
        $this->travel(1)->seconds();
        $this->postJson($url, $this->sample(50))->assertJsonPath('event.data.riskLevel', 'normal');
        $this->assertNotNull(Incident::first()->active_zone_id);
        $this->assertSame(1, DomainEvent::where('type', 'danger_detected')->count());
    }

    public function test_old_zone_readings_are_labelled_stale(): void
    {
        $this->operator();
        $this->postJson('/api/v1/demo/zones/'.$this->zone()->id.'/observations', $this->sample())->assertOk();
        $this->travel(3)->minutes();
        $this->getJson('/api/v1/zones')->assertOk()->assertJsonPath('data.0.dataFreshness', 'stale');
    }

    public function test_unconfigured_provider_status_is_honest_and_private(): void
    {
        $this->operator(['read']);
        config([
            'camara.api_key' => 'do-not-expose',
            'population.orange.client_id' => 'orange-id',
            'population.orange.client_secret' => 'also-do-not-expose',
        ]);
        $this->getJson('/api/v1/integrations')
            ->assertOk()
            ->assertJsonPath('nokia.credentialsConfigured', true)
            ->assertJsonPath('populationDensity.credentialsConfigured', true)
            ->assertDontSee('do-not-expose')
            ->assertDontSee('also-do-not-expose');
    }
}
