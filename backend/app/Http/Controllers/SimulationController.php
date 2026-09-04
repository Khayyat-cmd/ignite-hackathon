<?php

namespace App\Http\Controllers;

use App\Models\Incident;
use App\Models\Responder;
use App\Models\SimulationRun;
use App\Models\Zone;
use App\Services\Demo\DemoWorkspace;
use App\Services\Simulation\EventSimulation;
use App\Services\Simulation\LocationProvider;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SimulationController extends Controller
{
    public function index(DemoWorkspace $workspace): array
    {
        return ['data' => SimulationRun::where('organization_id', $workspace->operator()->organization_id)
            ->orderByDesc('id')->paginate(20, ['id', 'venue_event_id', 'status', 'elapsed_seconds', 'attendee_count', 'revision'])];
    }

    public function store(Request $request, EventSimulation $simulation, DemoWorkspace $workspace): JsonResponse
    {
        $data = $request->validate(['attendeeCount' => 'sometimes|integer|min:100|max:10000']);
        $run = $simulation->create($workspace->operator(), $data['attendeeCount'] ?? 6000);

        return response()->json($this->summary($run), 201);
    }

    public function control(Request $request, SimulationRun $run, EventSimulation $simulation, DemoWorkspace $workspace): array
    {
        $workspace->owns($run);
        $data = $request->validate(['action' => 'required|in:start,pause,stop,reset']);

        return $this->summary($simulation->control($run, $data['action'], $workspace->operator()));
    }

    public function show(Request $request, SimulationRun $run, DemoWorkspace $workspace): JsonResponse
    {
        $workspace->owns($run);

        return DB::transaction(function () use ($request, $run, $workspace) {
            $current = SimulationRun::whereKey($run->id)->sharedLock()->firstOrFail();

            return $this->snapshotResponse($request, $current, $workspace);
        });
    }

    private function snapshotResponse(Request $request, SimulationRun $run, DemoWorkspace $workspace): JsonResponse
    {
        $workspace->owns($run);
        $data = $request->validate(['client' => 'sometimes|in:operator,unity', 'offset' => 'sometimes|integer|min:0|max:10000',
            'limit' => 'sometimes|integer|min:1|max:10000', 'revision' => 'sometimes|integer|min:1']);
        if (isset($data['revision'])) {
            abort_unless((int) $data['revision'] === $run->revision, 409, 'Snapshot changed. Restart reading at offset zero.');
        }
        $snapshot = $run->snapshot ?? [];
        $result = $this->summary($run);
        $result['zones'] = Zone::where('venue_event_id', $run->venue_event_id)->get()->map(function (Zone $zone): array {
            $stableFor = $zone->noncritical_since ? $zone->noncritical_since->diffInSeconds(now()) : 0;
            $required = config('aman.resolution_stable_seconds');

            return [...$zone->toArray(), 'resolution' => [
                'stableForSeconds' => $stableFor,
                'requiredStableSeconds' => $required,
                'ready' => ! in_array($zone->risk_level, ['critical', 'unknown'], true) && $stableFor >= $required,
            ]];
        })->values();
        $responders = Responder::where('venue_event_id', $run->venue_event_id)->get();
        $activeMissions = Incident::where('venue_event_id', $run->venue_event_id)->whereNotNull('active_zone_id')
            ->whereNotNull('assigned_responder_id')->get()->keyBy('assigned_responder_id');
        $result['responders'] = $responders;
        $result['responderPositions'] = $responders->map(function (Responder $responder) use ($activeMissions): array {
            $mission = $activeMissions->get($responder->id);

            return [
                'id' => $responder->id,
                'x' => data_get($responder->signals, 'simulationPoint.x'),
                'z' => data_get($responder->signals, 'simulationPoint.y'),
                'available' => $responder->available,
                'missionStatus' => $mission?->status?->value,
            ];
        })->values();
        if (($data['client'] ?? 'operator') === 'unity') {
            $offset = (int) ($data['offset'] ?? 0);
            $limit = (int) ($data['limit'] ?? 10000);
            $result['positions'] = array_slice($snapshot['positions'] ?? [], $offset, $limit);
            $result['nextOffset'] = $offset + $limit < $run->attendee_count ? $offset + $limit : null;
            $result['coordinateSystem'] = ['origin' => $run->definition['origin'], 'units' => 'metres', 'x' => 'east', 'z' => 'north', 'y' => 'height'];
        } else {
            $result['incidents'] = Incident::where('venue_event_id', $run->venue_event_id)->orderByDesc('created_at')->limit(100)->get();
            $result['providerExamples'] = $snapshot['providerExamples'] ?? [];
            $result['assumptions'] = ['One fictional device per attendee.', 'One-metre location accuracy is a simulation assumption.',
                'Reachability uses linked Nokia sandbox devices; other network responses are local fixtures.', 'No real calls, SMS or push notifications are sent.'];
        }

        return response()->json($result)->header('Cache-Control', 'no-store');
    }

    public function retrieve(Request $request, SimulationRun $run, LocationProvider $provider, DemoWorkspace $workspace): JsonResponse
    {
        $workspace->owns($run);
        $request->validate(['device.phoneNumber' => 'required|string|max:20', 'maxAge' => 'sometimes|integer|min:0|max:120']);
        $response = $provider->retrieve($request->only(['device', 'maxAge']), $run->definition, $run->attendee_count,
            $run->elapsed_seconds, $run->interventions ?? [], CarbonImmutable::now());

        return response()->json($response['body'], $response['status'])->header('X-Data-Source', 'simulated_network');
    }

    private function summary(SimulationRun $run): array
    {
        $snapshot = $run->snapshot ?? [];

        return ['id' => $run->id, 'venueEventId' => $run->venue_event_id, 'status' => $run->status,
            'revision' => $run->revision, 'elapsedSeconds' => $run->elapsed_seconds,
            'attendeeCount' => $run->attendee_count, 'source' => 'simulated_network', 'pollIntervalSeconds' => 5,
            'phase' => $snapshot['phase'] ?? 'ready', 'observedAt' => $snapshot['observedAt'] ?? null,
            'stale' => ! isset($snapshot['observedAt']) || CarbonImmutable::parse($snapshot['observedAt'])->lt(now()->subSeconds(15)),
            'quality' => $snapshot['quality'] ?? [], 'zoneCounts' => $snapshot['zoneCounts'] ?? [],
            'reachabilityProvider' => 'nokia_sandbox'];
    }
}
