<?php

namespace App\Http\Controllers;

use App\Enums\EventType;
use App\Models\Incident;
use App\Models\Responder;
use App\Models\SimulationRun;
use App\Models\Zone;
use App\Services\Demo\DemoWorkspace;
use App\Services\EventJournal;
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

    /**
     * The operator's zone focus. Electron writes it when the operator selects a zone
     * or an incident; the standalone Unity screen reads it from its own snapshot poll
     * and moves its camera. The two clients never talk to each other.
     */
    public function focus(Request $request, SimulationRun $run, EventJournal $journal, DemoWorkspace $workspace): JsonResponse
    {
        $workspace->owns($run);
        $data = $request->validate([
            'zoneId' => ['present', 'nullable', 'uuid'],
            'incidentId' => ['sometimes', 'nullable', 'uuid'],
        ]);

        $updated = DB::transaction(function () use ($run, $data, $journal, $workspace): SimulationRun {
            $locked = SimulationRun::whereKey($run->id)->lockForUpdate()->firstOrFail();
            $zoneId = $data['zoneId'];
            if ($zoneId !== null) {
                abort_unless(Zone::where('venue_event_id', $locked->venue_event_id)->whereKey($zoneId)->exists(),
                    404, 'That zone is not part of this event.');
            }
            $incidentId = $data['incidentId'] ?? null;
            if ($incidentId !== null) {
                abort_unless(Incident::where('venue_event_id', $locked->venue_event_id)->whereKey($incidentId)->exists(),
                    404, 'That incident is not part of this event.');
            }
            // Selecting the same zone again is a no-op. The console posts on every
            // click, and a repeat must not burn a sequence number Unity is tracking.
            if (data_get($locked->focus, 'zoneId') === $zoneId && data_get($locked->focus, 'incidentId') === $incidentId) {
                return $locked;
            }
            $focus = [
                'zoneId' => $zoneId,
                'incidentId' => $incidentId,
                'sequence' => (int) data_get($locked->focus, 'sequence', 0) + 1,
                'setAt' => now()->toISOString(),
            ];
            $locked->forceFill(['focus' => $focus])->save();
            $journal->append(EventType::ZoneFocused, $this->focusPayload($locked), $zoneId, $incidentId,
                $workspace->operator()->id);

            return $locked;
        }, attempts: 3);

        return response()->json(['focus' => $this->focusPayload($updated)])->header('Cache-Control', 'no-store');
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
        // `revision` pins one snapshot across a paged read. The tick advances it every
        // five seconds, so enforcing it on the first page would reject every client
        // that kept a revision from its last poll. A fresh read always starts clean.
        if (isset($data['revision']) && (int) ($data['offset'] ?? 0) > 0) {
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

    /**
     * Focus in the terms each screen needs: stable IDs for Electron, and a camera
     * target in simulation metres for Unity. `zoneId` is null once the operator
     * clears the selection, which is Unity's cue to frame the whole venue again.
     *
     * @return array<string, mixed>
     */
    private function focusPayload(SimulationRun $run): array
    {
        $focus = $run->focus ?? [];
        $zoneId = $focus['zoneId'] ?? null;
        $definition = collect($run->definition['zones'] ?? [])->firstWhere('id', $zoneId);
        $camera = null;
        if ($definition !== null) {
            [$left, $bottom, $right, $top] = $definition['bounds'];
            $camera = ['x' => ($left + $right) / 2, 'z' => ($bottom + $top) / 2,
                'width' => $right - $left, 'depth' => $top - $bottom, 'units' => 'metres'];
        }

        return [
            'zoneId' => $zoneId,
            'zoneKey' => $definition['key'] ?? null,
            'zoneName' => $definition['name'] ?? null,
            'incidentId' => $focus['incidentId'] ?? null,
            'sequence' => (int) ($focus['sequence'] ?? 0),
            'setAt' => $focus['setAt'] ?? null,
            'camera' => $camera,
        ];
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
            'reachabilityProvider' => 'nokia_sandbox', 'focus' => $this->focusPayload($run)];
    }
}
