<?php

namespace App\Http\Controllers;

use App\Enums\IncidentStatus;
use App\Models\Incident;
use App\Models\Responder;
use App\Models\SimulationRun;
use App\Models\Zone;
use App\Services\Demo\DemoWorkspace;
use App\Services\IncidentWorkflow;
use Illuminate\Http\Request;

class MissionController extends Controller
{
    public function responders(DemoWorkspace $workspace): array
    {
        $responders = Responder::query()
            ->where('organization_id', $workspace->operator()->organization_id)
            ->where('authorized', true)
            ->get(['id', 'venue_event_id', 'name', 'role', 'available']);
        $runs = SimulationRun::query()
            ->whereIn('venue_event_id', $responders->pluck('venue_event_id'))
            ->with('venueEvent:id,name,status')
            ->orderByDesc('id')
            ->get()
            ->keyBy('venue_event_id');

        return ['data' => $responders
            ->sortByDesc(fn (Responder $responder): int => $runs->get($responder->venue_event_id)?->id ?? 0)
            ->map(function (Responder $responder) use ($runs): array {
                $run = $runs->get($responder->venue_event_id);

                return [
                    'id' => $responder->id,
                    'name' => $responder->name,
                    'role' => $responder->role,
                    'available' => $responder->available,
                    'event' => [
                        'id' => $responder->venue_event_id,
                        'name' => $run?->venueEvent?->name ?? 'Rehearsal event',
                        'number' => $run?->id,
                        'status' => $run?->status ?? 'stopped',
                    ],
                ];
            })->values()];
    }

    public function index(Request $request, DemoWorkspace $workspace): array
    {
        $data = $request->validate(['responderId' => 'required|uuid']);
        $responderId = $data['responderId'];
        $missions = Incident::where('assigned_responder_id', $responderId)
            ->whereNotNull('active_zone_id')
            ->where('organization_id', $workspace->operator()->organization_id)
            ->whereIn('status', [IncidentStatus::Dispatched, IncidentStatus::Acknowledged])
            ->orderByDesc('created_at')->limit(50)->get();
        $zones = Zone::where('organization_id', $workspace->operator()->organization_id)->whereIn('id', $missions->pluck('zone_id'))->get()->keyBy('id');

        return ['data' => $missions->map(function (Incident $incident) use ($zones) {
            $zone = $zones->get($incident->zone_id);

            return [
                'id' => $incident->id, 'status' => $incident->status->value,
                'arrivalVerification' => data_get($incident->decision, 'arrivalVerification'),
                'workStartedAt' => data_get($incident->decision, 'workStartedAt'),
                'source' => $incident->source, 'approvedAt' => $incident->approved_at?->toISOString(),
                'brief' => $this->fieldBrief($incident),
                'destination' => ['zoneId' => $zone->id, 'name' => $zone->name,
                    'latitude' => $zone->latitude, 'longitude' => $zone->longitude,
                    'boundary' => $zone->boundary, 'simulated' => $zone->demo_key !== null],
            ];
        })];
    }

    public function acknowledge(Request $request, Incident $incident, IncidentWorkflow $workflow, DemoWorkspace $workspace): array
    {
        $data = $request->validate(['responderId' => 'required|uuid']);
        abort_unless($incident->organization_id === $workspace->operator()->organization_id, 404);
        abort_unless($incident->assigned_responder_id === $data['responderId'], 403, 'This mission is not assigned to this responder.');
        $responder = $workspace->responder($data['responderId'], SimulationRun::where('venue_event_id', $incident->venue_event_id)->firstOrFail());
        $incident = $workflow->acknowledge($incident, $workspace->responderActor($responder)->id);

        return ['id' => $incident->id, 'status' => $incident->status->value];
    }

    /**
     * The field-facing slice of the operator's approved brief: how urgent the
     * zone is, and whether the agent's own Congestion Insights evidence says
     * mobile data will be unreliable on arrival.
     *
     * The agent's prose is deliberately not forwarded. It is written for the
     * operator deciding a dispatch ("Operator should review and approve…"),
     * which reads wrong in the hands of the responder already assigned.
     * Anything a responder should be told in words comes from the operator
     * through the mission thread instead.
     *
     * @return array<string, mixed>|null
     */
    private function fieldBrief(Incident $incident): ?array
    {
        $advice = data_get($incident->decision, 'advice');
        if (! is_array($advice)) {
            return null;
        }
        $congested = collect(data_get($advice, 'toolTrace', []))
            ->contains(fn (mixed $entry): bool => data_get($entry, 'tool') === 'congestion_insights'
                && data_get($entry, 'result.congestionLevel') === 'high');

        return [
            'urgency' => data_get($advice, 'urgency'),
            'networkCongested' => $congested,
        ];
    }
}
