<?php

namespace App\Http\Controllers;

use App\Enums\EventType;
use App\Enums\IncidentStatus;
use App\Models\Incident;
use App\Models\SimulationRun;
use App\Services\Demo\DemoWorkspace;
use App\Services\EventJournal;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class MissionCommunicationController extends Controller
{
    public function index(Request $request, Incident $incident, DemoWorkspace $workspace): array
    {
        $data = $request->validate(['after' => 'sometimes|integer|min:0', 'responderId' => 'sometimes|uuid']);
        $this->authorizeMission($incident, $workspace, $data['responderId'] ?? null);

        return ['data' => DB::table('mission_messages')->where('incident_id', $incident->id)
            ->where('id', '>', $data['after'] ?? 0)->orderBy('id')->limit(100)->get()];
    }

    public function store(Request $request, Incident $incident, EventJournal $journal, DemoWorkspace $workspace): array
    {
        $data = $request->validate(['clientMessageId' => 'required|uuid',
            'kind' => 'required|in:instruction,message,en_route,on_scene,cleared', 'body' => 'required|string|min:1|max:1000',
            'responderId' => 'sometimes|uuid']);
        $responderId = $data['responderId'] ?? null;
        $this->authorizeMission($incident, $workspace, $responderId);
        $actor = $responderId
            ? $workspace->responderActor($workspace->responder($responderId, SimulationRun::where('venue_event_id', $incident->venue_event_id)->firstOrFail()))
            : $workspace->operator();
        $isOperator = $responderId === null;
        abort_unless($isOperator ? in_array($data['kind'], ['instruction', 'message'], true) : $data['kind'] !== 'instruction', 403);

        return DB::transaction(function () use ($incident, $data, $journal, $workspace, $responderId, $actor) {
            // Match the simulation tick lock order: run, then incident.
            $run = SimulationRun::where('venue_event_id', $incident->venue_event_id)->lockForUpdate()->first();
            $locked = Incident::whereKey($incident->id)->lockForUpdate()->firstOrFail();
            $this->authorizeMission($locked, $workspace, $responderId);
            $existing = DB::table('mission_messages')->where('incident_id', $locked->id)->where('client_message_id', $data['clientMessageId'])->first();
            if ($existing) {
                abort_unless($existing->user_id === $actor->id && $existing->kind === $data['kind'] && $existing->body === $data['body'], 409, 'Message ID is already in use.');

                return ['id' => $existing->id, 'duplicate' => true];
            }
            abort_unless($locked->active_zone_id && in_array($locked->status, [IncidentStatus::Dispatched, IncidentStatus::Acknowledged], true), 409, 'This mission is not active.');
            if (in_array($data['kind'], ['en_route', 'on_scene', 'cleared'], true)) {
                abort_unless($locked->status === IncidentStatus::Acknowledged, 409, 'Acknowledge the mission before reporting progress.');
            }
            if ($data['kind'] === 'on_scene' && $run) {
                abort_unless($run->status === 'running', 409, 'Start the simulation before reporting arrival.');
                $zoneKey = collect($run->definition['zones'])->firstWhere('id', $locked->zone_id)['key'] ?? null;
                abort_unless(in_array($zoneKey, ['east', 'south'], true), 409, 'This demo only models recovery for East Entrance and South Concourse.');
                $interventions = $run->interventions ?? [];
                $interventions[$zoneKey] ??= $run->elapsed_seconds;
                $run->update(['interventions' => $interventions, 'intervention_at' => $interventions['east'] ?? null]);
            }
            $id = DB::table('mission_messages')->insertGetId(['incident_id' => $locked->id, 'user_id' => $actor->id,
                'client_message_id' => $data['clientMessageId'], 'kind' => $data['kind'], 'body' => $data['body'],
                'created_at' => now(), 'updated_at' => now()]);
            $journal->append(EventType::MissionMessage, ['messageId' => $id, 'kind' => $data['kind'],
                'body' => $data['body'], 'responderId' => $locked->assigned_responder_id],
                $locked->zone_id, $locked->id, $actor->id);

            return ['id' => $id, 'duplicate' => false];
        }, attempts: 3);
    }

    private function authorizeMission(Incident $incident, DemoWorkspace $workspace, ?string $responderId): void
    {
        abort_unless($incident->organization_id === $workspace->operator()->organization_id, 404);
        if ($responderId !== null) {
            abort_unless($incident->assigned_responder_id === $responderId || $incident->responder_id === $responderId, 403, 'This mission is not assigned to this responder.');
        }
    }
}
