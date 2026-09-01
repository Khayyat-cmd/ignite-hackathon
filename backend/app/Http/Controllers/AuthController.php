<?php

namespace App\Http\Controllers;

use App\Http\Requests\AcceptInvitationRequest;
use App\Http\Requests\LoginRequest;
use App\Http\Requests\RegisterOrganizationRequest;
use App\Models\Organization;
use App\Models\OrganizationInvitation;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function register(RegisterOrganizationRequest $request): JsonResponse
    {
        $data = $request->validated();
        [$organization, $user] = DB::transaction(function () use ($data) {
            $baseSlug = Str::slug($data['organizationName']) ?: 'organization';
            $slug = $baseSlug;
            for ($suffix = 2; Organization::where('slug', $slug)->exists(); $suffix++) {
                $slug = $baseSlug.'-'.$suffix;
            }
            $organization = Organization::create(['name' => $data['organizationName'], 'slug' => $slug, 'status' => app()->environment('production') ? 'pending' : 'active']);
            $user = User::create([
                'organization_id' => $organization->id, 'name' => $data['name'],
                'email' => Str::lower($data['email']), 'password' => $data['password'],
                'role' => 'owner', 'status' => 'active',
            ]);

            return [$organization, $user];
        }, attempts: 3);

        if ($organization->status !== 'active') {
            return response()->json(['status' => 'pending', 'message' => 'Your organization request is awaiting approval.'], 202);
        }

        return response()->json($this->sessionPayload($user, $user->createToken('web', $this->abilitiesFor($user))->plainTextToken), 201);
    }

    public function login(LoginRequest $request): JsonResponse
    {
        $request->ensureIsNotRateLimited();
        $user = User::with('organization')->where('email', Str::lower($request->string('email')->toString()))->first();
        if (! $user || ! Hash::check($request->string('password')->toString(), $user->password)) {
            $request->recordFailedAttempt();
            throw ValidationException::withMessages(['email' => 'The provided credentials are incorrect.']);
        }
        $request->clearRateLimit();
        abort_unless($user->status === 'active', 403, 'This account is not active.');
        abort_unless($user->organization?->status === 'active', 403, 'This organization is awaiting approval or disabled.');
        $user->tokens()->where('name', 'web')->delete();

        return response()->json($this->sessionPayload($user, $user->createToken('web', $this->abilitiesFor($user))->plainTextToken));
    }

    public function acceptInvitation(AcceptInvitationRequest $request): JsonResponse
    {
        $data = $request->validated();
        $invitation = OrganizationInvitation::with('organization')->where('token_hash', hash('sha256', $data['token']))->first();
        abort_unless($invitation && ! $invitation->accepted_at && $invitation->expires_at->isFuture(), 422, 'This invitation is invalid or expired.');
        abort_if(User::where('email', $invitation->email)->exists(), 409, 'An account already exists for this email.');
        abort_unless($invitation->organization->status === 'active', 403, 'This organization is not active.');

        $user = DB::transaction(function () use ($data, $invitation) {
            $locked = OrganizationInvitation::whereKey($invitation->id)->lockForUpdate()->firstOrFail();
            abort_unless(! $locked->accepted_at && $locked->expires_at->isFuture(), 422, 'This invitation is invalid or expired.');
            $user = User::create([
                'organization_id' => $locked->organization_id, 'name' => $data['name'],
                'email' => $locked->email, 'password' => $data['password'],
                'role' => $locked->role, 'status' => 'active',
            ]);
            $locked->update(['accepted_at' => now()]);

            return $user;
        }, attempts: 3);

        return response()->json($this->sessionPayload($user, $user->createToken('web', $this->abilitiesFor($user))->plainTextToken), 201);
    }

    public function me(Request $request): array
    {
        return $this->sessionPayload($request->user()->load('organization'));
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()?->delete();

        return response()->json(['message' => 'Signed out.']);
    }

    private function abilitiesFor(User $user): array
    {
        return match ($user->role) {
            'owner', 'admin' => ['read', 'operate', 'ingest', 'manage'],
            'operator' => ['read', 'operate'],
            'responder' => ['respond'],
            default => ['read'],
        };
    }

    private function sessionPayload(User $user, ?string $token = null): array
    {
        return array_filter([
            'token' => $token,
            'user' => $user->only(['id', 'name', 'email', 'role', 'status']),
            'organization' => $user->organization?->only(['id', 'name', 'slug', 'status']),
        ], fn ($value) => $value !== null);
    }
}
