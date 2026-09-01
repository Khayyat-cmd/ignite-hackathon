<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\User;
use App\Models\VenueEvent;
use App\Models\Zone;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TenantIsolationTest extends TestCase
{
    use RefreshDatabase;

    public function test_organization_only_sees_its_own_events_zones_and_members(): void
    {
        $first = Organization::factory()->create();
        $second = Organization::factory()->create();
        $owner = User::factory()->create(['organization_id' => $first->id, 'role' => 'owner']);
        User::factory()->create(['organization_id' => $second->id]);
        VenueEvent::factory()->create(['organization_id' => $first->id, 'name' => 'Visible Event']);
        VenueEvent::factory()->create(['organization_id' => $second->id, 'name' => 'Hidden Event']);
        Zone::create(['organization_id' => $first->id, 'name' => 'Visible Zone', 'area_sqm' => 100, 'warning_density' => 1, 'critical_density' => 2, 'latitude' => 1, 'longitude' => 1]);
        $hidden = Zone::create(['organization_id' => $second->id, 'name' => 'Hidden Zone', 'area_sqm' => 100, 'warning_density' => 1, 'critical_density' => 2, 'latitude' => 2, 'longitude' => 2]);
        $token = $owner->createToken('test', ['read', 'operate', 'manage'])->plainTextToken;

        $this->withToken($token)->getJson('/api/v1/organization/events')->assertOk()->assertSee('Visible Event')->assertDontSee('Hidden Event');
        $this->withToken($token)->getJson('/api/v1/zones')->assertOk()->assertSee('Visible Zone')->assertDontSee('Hidden Zone');
        $this->withToken($token)->getJson('/api/v1/organization/members')->assertOk()->assertJsonPath('data.total', 1);
        $this->withToken($token)->postJson('/api/v1/demo/zones/'.$hidden->id.'/scenario', ['action' => 'crowded'])->assertNotFound();
    }
}
