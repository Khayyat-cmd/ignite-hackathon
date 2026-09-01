<?php

namespace Tests\Feature;

use App\Mail\OrganizationInvitationMail;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class OrganizationAuthenticationTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_can_register_login_view_dashboard_and_logout(): void
    {
        $registration = $this->postJson('/api/v1/auth/register', [
            'organizationName' => 'Safe Events', 'name' => 'Owner User',
            'email' => 'OWNER@example.test', 'password' => 'very-secure-password',
            'password_confirmation' => 'very-secure-password',
        ])->assertCreated()->assertJsonPath('user.role', 'owner')->assertJsonPath('organization.status', 'active');

        $token = $registration->json('token');
        $this->withToken($token)->getJson('/api/v1/organization/dashboard')->assertOk()
            ->assertJsonPath('counts.members', 1)->assertJsonPath('user.role', 'owner');
        $this->withToken($token)->postJson('/api/v1/auth/logout')->assertOk();
        $this->app['auth']->forgetGuards();
        $this->withToken($token)->getJson('/api/v1/auth/me')->assertUnauthorized();

        $this->postJson('/api/v1/auth/login', ['email' => 'owner@example.test', 'password' => 'wrong-password'])->assertUnprocessable();
        $this->postJson('/api/v1/auth/login', ['email' => 'owner@example.test', 'password' => 'very-secure-password'])->assertOk()->assertJsonPath('organization.name', 'Safe Events');
        $this->assertTrue(Hash::check('very-secure-password', User::first()->password));
    }

    public function test_owner_can_invite_member_and_invitation_is_single_use(): void
    {
        Mail::fake();
        $organization = Organization::factory()->create();
        $owner = User::factory()->create(['organization_id' => $organization->id, 'role' => 'owner']);
        $token = $owner->createToken('test', ['read', 'manage'])->plainTextToken;
        $invitation = $this->withToken($token)->postJson('/api/v1/organization/invitations', ['email' => 'operator@example.test', 'role' => 'operator'])
            ->assertCreated()->assertJsonPath('delivery', 'email_sent')->assertJsonMissingPath('acceptanceToken');

        $acceptanceToken = null;
        Mail::assertSent(OrganizationInvitationMail::class, function (OrganizationInvitationMail $mail) use (&$acceptanceToken): bool {
            parse_str((string) parse_url($mail->acceptanceUrl, PHP_URL_QUERY), $query);
            $acceptanceToken = $query['token'] ?? null;

            return $mail->hasTo('operator@example.test')
                && str_starts_with($mail->acceptanceUrl, 'http://localhost:3000/invite/accept?token=');
        });
        $this->assertIsString($acceptanceToken);

        $this->postJson('/api/v1/auth/invitations/accept', [
            'token' => $acceptanceToken, 'name' => 'Event Operator',
            'password' => 'another-secure-password', 'password_confirmation' => 'another-secure-password',
        ])->assertCreated()->assertJsonPath('user.role', 'operator')->assertJsonPath('organization.id', $organization->id);
        $this->postJson('/api/v1/auth/invitations/accept', [
            'token' => $acceptanceToken, 'name' => 'Replay',
            'password' => 'another-secure-password', 'password_confirmation' => 'another-secure-password',
        ])->assertUnprocessable();
    }

    public function test_non_admin_cannot_invite_or_create_events(): void
    {
        $user = User::factory()->create(['role' => 'viewer']);
        $token = $user->createToken('test', ['read'])->plainTextToken;

        $this->withToken($token)->postJson('/api/v1/organization/invitations', ['email' => 'new@example.test', 'role' => 'operator'])->assertForbidden();
        $this->withToken($token)->postJson('/api/v1/organization/events', ['name' => 'Match', 'venueName' => 'Arena'])->assertForbidden();
    }
}
