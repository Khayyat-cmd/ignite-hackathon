<?php

namespace App\Http\Controllers;

use App\Models\Incident;
use App\Services\IncidentWorkflow;
use Illuminate\Http\Request;

class IncidentController extends Controller
{
    public function recommend(Request $request, Incident $incident, IncidentWorkflow $workflow): Incident
    {
        return $workflow->recommend($incident, $request->user()->id);
    }

    public function approve(Request $request, Incident $incident, IncidentWorkflow $workflow): Incident
    {
        $request->validate(['routeReviewed' => 'required|accepted']);

        return $workflow->approve($incident, $request->user()->id);
    }

    // Operator records acknowledgement; a responder-specific login is a later slice.
    public function acknowledge(Request $request, Incident $incident, IncidentWorkflow $workflow): Incident
    {
        return $workflow->acknowledge($incident, $request->user()->id);
    }

    public function resolve(Request $request, Incident $incident, IncidentWorkflow $workflow): Incident
    {
        $data = $request->validate(['note' => 'required|string|min:5|max:1000']);

        return $workflow->resolve($incident, $request->user()->id, $data['note']);
    }
}
