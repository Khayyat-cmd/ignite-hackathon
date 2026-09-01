<?php

namespace App\Http\Controllers;

use App\Http\Requests\CreateResponderRequest;
use App\Jobs\RefreshResponder;
use App\Models\Responder;
use App\Services\ResponderSignals;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ResponderController extends Controller
{
    public function index(Request $request): LengthAwarePaginator
    {
        return Responder::where('organization_id', $request->user()->organization_id)->orderBy('id')->paginate(50)->through(function (Responder $responder) {
            $data = $responder->toArray();
            $checkedAt = data_get($responder->signals, 'checkedAt');
            $data['networkFreshness'] = ! $checkedAt ? 'missing' : (CarbonImmutable::parse($checkedAt)->lt(now()->subSeconds(config('aman.signal_max_age_seconds'))) ? 'stale' : 'fresh');
            $data['networkWarning'] = collect(data_get($responder->signals, 'congestion', []))->contains('congestionLevel', 'High')
                ? 'Network congestion reported; monitor acknowledgement. This does not prove delivery failure.'
                : null;

            return $data;
        });
    }

    public function store(CreateResponderRequest $request): JsonResponse
    {
        return response()->json(Responder::create([...$request->validated(), 'organization_id' => $request->user()->organization_id]), 201);
    }

    public function refresh(Request $request, Responder $responder): JsonResponse
    {
        abort_unless($responder->organization_id === $request->user()->organization_id, 404);
        abort_unless($responder->authorized, 403, 'Device authorization is required.');
        abort_unless(in_array(config('camara.mode'), ['sandbox', 'live'], true) && filled(config('camara.api_key')), 503, 'Nokia credentials and mode must be configured first.');
        RefreshResponder::dispatch($responder->id, $request->user()->id);

        return response()->json(['status' => 'queued', 'responderId' => $responder->id], 202);
    }

    public function demoSignals(Request $request, Responder $responder, ResponderSignals $service): Responder
    {
        abort_unless($responder->organization_id === $request->user()->organization_id, 404);
        abort_unless(config('aman.demo_enabled') && ! app()->environment('production'), 403, 'Demo mode is disabled.');
        $data = $request->validate([
            'latitude' => 'required|numeric|between:-90,90', 'longitude' => 'required|numeric|between:-180,180',
            'accuracyMeters' => 'required|numeric|between:0,100000', 'dataReachable' => 'required|boolean',
            'source' => 'prohibited',
        ]);
        $now = now()->toISOString();

        return $service->store($responder, [
            'source' => 'demo', 'checkedAt' => $now,
            'location' => ['latitude' => (float) $data['latitude'], 'longitude' => (float) $data['longitude'], 'accuracyMeters' => (float) $data['accuracyMeters'], 'observedAt' => $now],
            'reachability' => ['reachable' => (bool) $data['dataReachable'], 'dataReachable' => (bool) $data['dataReachable'], 'connectivity' => $data['dataReachable'] ? ['DATA'] : [], 'observedAt' => $now],
        ], $request->user()->id);
    }
}
