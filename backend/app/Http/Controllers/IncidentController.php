<?php

namespace App\Http\Controllers;

use App\Models\Incident;
use App\Services\Demo\DemoWorkspace;
use App\Services\IncidentWorkflow;
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
        $request->validate(['routeReviewed' => 'required|accepted']);

        return $workflow->approve($incident, $workspace->operator()->id);
    }

    public function resolve(Request $request, Incident $incident, IncidentWorkflow $workflow, DemoWorkspace $workspace): Incident
    {
        abort_unless($incident->organization_id === $workspace->operator()->organization_id, 404);
        $data = $request->validate(['note' => 'sometimes|nullable|string|max:1000']);

        return $workflow->resolve($incident, $workspace->operator()->id, $data['note'] ?? 'Operator confirmed stable recovery.');
    }
}
