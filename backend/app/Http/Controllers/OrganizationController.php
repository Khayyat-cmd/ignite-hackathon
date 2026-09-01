<?php

namespace App\Http\Controllers;

use App\Models\Incident;
use App\Models\Responder;
use App\Models\User;
use App\Models\VenueEvent;
use App\Models\Zone;
use Illuminate\Http\Request;

class OrganizationController extends Controller
{
    public function dashboard(Request $request): array
    {
        $organizationId = $request->user()->organization_id;

        return [
            'organization' => $request->user()->organization->only(['id', 'name', 'slug', 'status']),
            'user' => $request->user()->only(['id', 'name', 'email', 'role']),
            'counts' => [
                'members' => User::where('organization_id', $organizationId)->count(),
                'events' => VenueEvent::where('organization_id', $organizationId)->count(),
                'zones' => Zone::where('organization_id', $organizationId)->count(),
                'responders' => Responder::where('organization_id', $organizationId)->count(),
                'activeIncidents' => Incident::where('organization_id', $organizationId)->whereNull('resolved_at')->count(),
            ],
        ];
    }

    public function members(Request $request): array
    {
        abort_unless(in_array($request->user()->role, ['owner', 'admin'], true), 403);

        return ['data' => User::where('organization_id', $request->user()->organization_id)
            ->select(['id', 'name', 'email', 'role', 'status', 'created_at'])
            ->orderBy('name')->paginate(50)];
    }
}
