<?php

namespace App\Http\Controllers;

use App\Enums\IncidentStatus;
use App\Models\Incident;
use App\Models\Zone;
use App\Services\IncidentWorkflow;
use Illuminate\Http\Request;

class MissionController extends Controller
{
    public function index(Request $request): array
    {
        $responderId = $request->user()->responder_id;
        abort_unless($responderId, 403, 'No responder is linked to this account.');
        $missions = Incident::where('assigned_responder_id', $responderId)
            ->where('organization_id', $request->user()->organization_id)
            ->whereIn('status', [IncidentStatus::Dispatched, IncidentStatus::Acknowledged])
            ->orderByDesc('created_at')->limit(50)->get();
        $zones = Zone::where('organization_id', $request->user()->organization_id)->whereIn('id', $missions->pluck('zone_id'))->get()->keyBy('id');

        return ['data' => $missions->map(function (Incident $incident) use ($zones) {
            $zone = $zones->get($incident->zone_id);

            return [
                'id' => $incident->id, 'status' => $incident->status->value,
                'source' => $incident->source, 'approvedAt' => $incident->approved_at?->toISOString(),
                'destination' => ['zoneId' => $zone->id, 'name' => $zone->name,
                    'latitude' => $zone->latitude, 'longitude' => $zone->longitude,
                    'boundary' => $zone->boundary, 'simulated' => $zone->demo_key !== null],
            ];
        })];
    }

    public function acknowledge(Request $request, Incident $incident, IncidentWorkflow $workflow): array
    {
        abort_unless($incident->organization_id === $request->user()->organization_id, 404);
        abort_unless($request->user()->responder_id && $incident->assigned_responder_id === $request->user()->responder_id, 403, 'This mission is not assigned to you.');
        $incident = $workflow->acknowledge($incident, $request->user()->id);

        return ['id' => $incident->id, 'status' => $incident->status->value];
    }
}
