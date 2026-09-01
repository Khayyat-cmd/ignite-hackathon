<?php

namespace App\Http\Controllers;

use App\Http\Requests\CreateVenueEventRequest;
use App\Models\VenueEvent;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class VenueEventController extends Controller
{
    public function index(Request $request): array
    {
        return ['data' => VenueEvent::where('organization_id', $request->user()->organization_id)->orderByDesc('starts_at')->paginate(50)];
    }

    public function store(CreateVenueEventRequest $request): JsonResponse
    {
        $data = $request->validated();
        $event = VenueEvent::create([
            'organization_id' => $request->user()->organization_id,
            'name' => $data['name'], 'venue_name' => $data['venueName'],
            'starts_at' => $data['startsAt'] ?? null, 'ends_at' => $data['endsAt'] ?? null,
        ]);

        return response()->json($event, 201);
    }
}
