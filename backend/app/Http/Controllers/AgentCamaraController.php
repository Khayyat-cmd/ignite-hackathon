<?php

namespace App\Http\Controllers;

use App\Models\Incident;
use App\Services\Ai\CamaraEvidenceGateway;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use RuntimeException;

/**
 * The private, server-to-server CAMARA tool gateway the orchestration agent
 * calls. It is the only path from the agent to network evidence, and it hands
 * back one normalized record per call — never a credential, phone number, or
 * raw coordinate.
 */
class AgentCamaraController extends Controller
{
    public function __invoke(Request $request, CamaraEvidenceGateway $gateway): JsonResponse
    {
        $expected = (string) config('aman.agent.gateway_token');
        abort_if($expected === '', 503, 'The CAMARA tool gateway is not configured.');
        abort_unless(hash_equals($expected, (string) $request->bearerToken()), 401, 'Unauthorized.');

        $validated = $request->validate([
            'requestId' => ['required', 'string', 'max:100'],
            'incidentId' => ['required', 'uuid'],
            'zoneId' => ['required', 'uuid'],
            'operation' => ['required', Rule::in(CamaraEvidenceGateway::OPERATIONS)],
            'responderId' => ['nullable', 'uuid'],
        ]);

        $incident = Incident::findOrFail($validated['incidentId']);
        abort_unless($incident->zone_id === $validated['zoneId'], 422, 'Zone does not belong to this incident.');

        try {
            $evidence = $gateway->evidence($incident, $validated['operation'], $validated['responderId'] ?? null);
        } catch (RuntimeException $exception) {
            // The agent treats a 4xx as a failed tool call and reasons on without it.
            return response()->json(['error' => $exception->getMessage()], 422);
        }

        return response()->json(['evidence' => $evidence]);
    }
}
