<?php

namespace App\Http\Controllers;

use App\Http\Requests\CreateInvitationRequest;
use App\Mail\OrganizationInvitationMail;
use App\Models\OrganizationInvitation;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Throwable;

class InvitationController extends Controller
{
    public function store(CreateInvitationRequest $request): JsonResponse
    {
        $data = $request->validated();
        $email = Str::lower($data['email']);
        abort_if(User::where('email', $email)->exists(), 409, 'An account already exists for this email.');
        $plainToken = Str::random(64);
        $invitation = OrganizationInvitation::updateOrCreate(
            ['organization_id' => $request->user()->organization_id, 'email' => $email],
            ['invited_by' => $request->user()->id, 'role' => $data['role'], 'token_hash' => hash('sha256', $plainToken), 'expires_at' => now()->addDays(7), 'accepted_at' => null],
        );

        $acceptanceUrl = rtrim((string) config('app.frontend_url'), '/').'/invite/accept?token='.urlencode($plainToken);

        try {
            Mail::to($invitation->email)->send(new OrganizationInvitationMail(
                organizationName: $request->user()->organization->name,
                inviterName: $request->user()->name,
                role: $invitation->role,
                acceptanceUrl: $acceptanceUrl,
                expiresAt: $invitation->expires_at->toDayDateTimeString(),
            ));
        } catch (Throwable $exception) {
            Log::error('Organization invitation email delivery failed.', [
                'invitation_id' => $invitation->id,
                'exception' => $exception,
            ]);

            abort(503, 'The invitation could not be emailed. Check the mail configuration and try again.');
        }

        return response()->json([
            'invitation' => $invitation->only(['id', 'email', 'role', 'expires_at']),
            'delivery' => 'email_sent',
        ], 201);
    }
}
