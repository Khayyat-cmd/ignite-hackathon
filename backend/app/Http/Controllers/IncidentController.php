<?php

namespace App\Http\Controllers;

use App\Jobs\GenerateIncidentAdvice;
use App\Models\Incident;
use App\Services\Demo\DemoWorkspace;
use App\Services\IncidentWorkflow;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class IncidentController extends Controller
{
    public function recommend(Incident $incident, IncidentWorkflow $workflow, DemoWorkspace $workspace): Incident
    {
        abort_unless($incident->organization_id === $workspace->operator()->organization_id, 404);

        return $workflow->recommend($incident, $workspace->operator()->id);
    }

    public function approve(Request $request, Incident $incident, IncidentWorkflow $workflow, DemoWorkspace $workspace): Incident
    {
        abort_unless($incident->organization_id === $workspace->operator()->organization_id, 404);
        $data = $request->validate(['routeReviewed' => 'required|accepted', 'responderId' => 'sometimes|uuid']);

        return $workflow->approve($incident, $workspace->operator()->id, $data['responderId'] ?? null);
    }

    public function advise(Incident $incident, DemoWorkspace $workspace): JsonResponse
    {
        abort_unless($incident->organization_id === $workspace->operator()->organization_id, 404);
        abort_unless($incident->active_zone_id && data_get($incident->decision, 'candidates'), 409, 'Generate a responder recommendation before requesting AI advice.');
        abort_unless(filled(config('services.openai.key')), 503, 'Set OPENAI_API_KEY before requesting AI advice.');
        $decision = $incident->decision ?? [];
        $decision['adviceStatus'] = 'pending';
        unset($decision['advice'], $decision['adviceError']);
        $incident->update(['decision' => $decision]);
        GenerateIncidentAdvice::dispatch($incident->id)->afterCommit();

        return response()->json(['status' => 'pending'], 202);
    }

    public function resolve(Request $request, Incident $incident, IncidentWorkflow $workflow, DemoWorkspace $workspace): Incident
    {
        abort_unless($incident->organization_id === $workspace->operator()->organization_id, 404);
        $data = $request->validate(['note' => 'sometimes|nullable|string|max:1000']);

        return $workflow->resolve($incident, $workspace->operator()->id, $data['note'] ?? 'Operator confirmed stable recovery.');
    }
}
