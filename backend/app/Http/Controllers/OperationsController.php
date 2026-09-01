<?php

namespace App\Http\Controllers;

use App\Enums\IncidentStatus;
use App\Http\Requests\CreateZoneRequest;
use App\Http\Requests\CrowdObservationRequest;
use App\Models\DomainEvent;
use App\Models\Incident;
use App\Models\Zone;
use App\Services\CrowdMonitor;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class OperationsController extends Controller
{
    public function zones(Request $request): LengthAwarePaginator
    {
        return Zone::where('organization_id', $request->user()->organization_id)->orderBy('id')->paginate(50)->through(function (Zone $zone) {
            $data = $zone->toArray();
            $data['dataFreshness'] = ! $zone->last_observed_at ? 'missing' : ($zone->last_observed_at->lt(now()->subSeconds(config('aman.observation_max_age_seconds'))) ? 'stale' : 'fresh');

            return $data;
        });
    }

    public function createZone(CreateZoneRequest $request): JsonResponse
    {
        return response()->json(Zone::create([...$request->validated(), 'organization_id' => $request->user()->organization_id]), 201);
    }

    public function observe(CrowdObservationRequest $request, Zone $zone, CrowdMonitor $monitor): JsonResponse
    {
        abort_unless($zone->organization_id === $request->user()->organization_id, 404);

        return response()->json($monitor->ingest($zone, $request->validated(), 'demo', $request->user()->id));
    }

    public function events(Request $request): array
    {
        $input = $request->validate(['after' => 'sometimes|integer|min:0|max:9223372036854775806', 'limit' => 'sometimes|integer|between:1,200']);
        $query = DomainEvent::where('organization_id', $request->user()->organization_id);
        $events = (clone $query)->where('id', '>', $input['after'] ?? 0)->orderBy('id')->limit($input['limit'] ?? 100)->get();

        return ['data' => $events->map->envelope(), 'nextCursor' => (string) ($events->last()?->id ?? ($input['after'] ?? 0)), 'hasMore' => $events->isNotEmpty() && (clone $query)->where('id', '>', $events->last()->id)->exists()];
    }

    public function incidents(Request $request): LengthAwarePaginator
    {
        return Incident::where('organization_id', $request->user()->organization_id)->orderByDesc('created_at')->orderBy('id')->paginate(50)->through(function (Incident $incident) {
            $data = $incident->toArray();
            $data['acknowledgementOverdue'] = $incident->status === IncidentStatus::Dispatched
                && $incident->approved_at?->lt(now()->subSeconds(60));

            return $data;
        });
    }

    public function integrations(): array
    {
        return [
            'nokia' => [
                'mode' => config('camara.mode'),
                'credentialsConfigured' => filled(config('camara.api_key')),
                'implementedConnectors' => ['location_retrieval', 'location_verification', 'device_reachability', 'congestion_insights'],
            ],
            'populationDensity' => [
                'provider' => config('population.provider'),
                'credentialsConfigured' => filled(config('population.orange.client_id')) && filled(config('population.orange.client_secret')),
                'dataKind' => 'mocked_historical_or_predicted_estimate',
            ],
            'pending' => [
                'region_device_count' => 'Awaiting provider availability and account-specific contract.',
                'geofencing' => 'Requires subscription lifecycle and a secured public callback URL.',
                'quality_on_demand' => 'Requires approved device/application network flow and QoS profile; not activated automatically.',
            ],
        ];
    }
}
