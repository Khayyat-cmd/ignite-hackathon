<?php

namespace App\Http\Controllers;

use App\Jobs\RefreshPopulationContext;
use App\Models\Zone;
use App\Services\StadiumDemo;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DemoController extends Controller
{
    public function setup(StadiumDemo $demo): JsonResponse
    {
        return response()->json($demo->setup());
    }

    public function scenario(Request $request, Zone $zone, StadiumDemo $demo): Zone
    {
        $input = $request->validate(['action' => 'required|in:calm,crowded,recover,pause']);

        return $demo->control($zone, $input['action'], $request->user()->id);
    }

    public function refresh(Request $request, StadiumDemo $demo): JsonResponse
    {
        $demo->refreshRoster($request->user()->id);

        return response()->json(['status' => 'queued'], 202);
    }

    public function population(Request $request, Zone $zone, StadiumDemo $demo): JsonResponse
    {
        $demo->assertZone($zone);
        abort_unless($zone->boundary, 422, 'Zone geographic boundary is required.');
        $checkedAt = $zone->population_context['checkedAt'] ?? null;
        if ($checkedAt && CarbonImmutable::parse($checkedAt)->gt(now()->subMinute())) {
            return response()->json(['status' => 'cached', 'zoneId' => $zone->id, 'context' => $zone->population_context]);
        }
        RefreshPopulationContext::dispatch($zone->id, $request->user()->id);

        return response()->json(['status' => 'queued', 'zoneId' => $zone->id], 202);
    }
}
