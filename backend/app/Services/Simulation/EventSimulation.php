<?php

namespace App\Services\Simulation;

use App\Enums\EventType;
use App\Enums\IncidentStatus;
use App\Models\Incident;
use App\Models\Organization;
use App\Models\Responder;
use App\Models\SimulationRun;
use App\Models\User;
use App\Models\VenueEvent;
use App\Models\Zone;
use App\Services\CrowdMonitor;
use App\Services\EventJournal;
use App\Services\IncidentWorkflow;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class EventSimulation
{
    public function __construct(
        private LocationProvider $provider,
        private ZoneLocator $locator,
        private CrowdMonitor $monitor,
        private IncidentWorkflow $workflow,
        private EventJournal $journal,
        private NokiaReachability $reachability,
        private PositionStore $positionStore,
    ) {}

    public function assertEnabled(): void
    {
        abort_unless(config('aman.demo_enabled'), 403, 'Enable AMAN_DEMO_ENABLED to use fictional event data.');
    }

    public function create(User $actor, int $count = 6000): SimulationRun
    {
        $this->assertEnabled();

        if (DB::transactionLevel() === 0) {
            $this->reachability->refresh();
        }

        return DB::transaction(function () use ($actor, $count) {
            Organization::whereKey($actor->organization_id)->lockForUpdate()->firstOrFail();
            abort_if(SimulationRun::where('organization_id', $actor->organization_id)->whereIn('status', ['running', 'paused'])->exists(), 409, 'Stop the existing simulation before creating another.');
            $definition = json_decode(file_get_contents(resource_path('simulation/stadium.json')), true, flags: JSON_THROW_ON_ERROR);
            $event = VenueEvent::create(['organization_id' => $actor->organization_id,
                'name' => $definition['name'], 'venue_name' => 'Fictional Stadium', 'status' => 'draft']);
            $run = SimulationRun::create(['organization_id' => $actor->organization_id,
                'venue_event_id' => $event->id, 'created_by' => $actor->id,
                'attendee_count' => $count, 'definition' => $definition, 'elapsed_seconds' => 0, 'revision' => 0, 'status' => 'paused']);
            foreach ($definition['zones'] as &$zone) {
                [$left, $bottom, $right, $top] = $zone['bounds'];
                $center = $this->provider->coordinates($definition, ($left + $right) / 2, ($bottom + $top) / 2);
                $model = new Zone(['organization_id' => $actor->organization_id, 'venue_event_id' => $event->id,
                    'name' => $zone['name'], 'area_sqm' => ($right - $left) * ($top - $bottom),
                    'warning_density' => 1, 'critical_density' => 2, 'people_per_device' => 1,
                    'calibration_note' => 'Simulated attendees: one fictional device per attendee; illustrative thresholds.',
                    ...$center]);
                $model->forceFill(['demo_key' => 'location-sim:'.$run->id.':'.$zone['key'],
                    'boundary' => array_map(fn ($point) => $this->provider->coordinates($definition, ...$point),
                        [[$left, $bottom], [$right, $bottom], [$right, $top], [$left, $top]])])->save();
                $zone['id'] = $model->id;
            }
            unset($zone);
            foreach ($definition['responders'] as $index => &$responder) {
                $model = new Responder(['organization_id' => $actor->organization_id, 'venue_event_id' => $event->id,
                    'name' => $responder['name'], 'role' => 'crowd_marshal',
                    'phone_number' => '+999888'.str_pad((string) ($index + 1), 7, '0', STR_PAD_LEFT), 'authorized' => true]);
                $model->forceFill(['demo_key' => 'location-sim:'.$run->id.':responder:'.$index])->save();
                $responder['id'] = $model->id;
            }
            unset($responder);
            $run->update(['definition' => $definition]);
            $this->sample($run, CarbonImmutable::now());

            return $run->fresh();
        });
    }

    public function control(SimulationRun $run, string $action, User $actor): SimulationRun
    {
        $this->assertEnabled();

        return DB::transaction(function () use ($run, $action, $actor) {
            $run = SimulationRun::whereKey($run->id)->lockForUpdate()->firstOrFail();
            if ($action === 'reset') {
                $this->stop($run, 'Reset for a new demonstration.');

                return $this->create($actor, $run->attendee_count);
            }
            abort_if($run->status === 'stopped', 409, 'This run is stopped. Reset to start a new event.');
            if ($action === 'stop') {
                $this->stop($run, 'Operator stopped demonstration.');
            } else {
                $run->update(['status' => $action === 'start' ? 'running' : 'paused', 'last_tick_at' => now()]);
                VenueEvent::whereKey($run->venue_event_id)->update(['status' => $action === 'start' ? 'active' : 'paused']);
            }

            return $run->fresh();
        });
    }

    private function stop(SimulationRun $run, string $reason): void
    {
        $run->update(['status' => 'stopped']);
        // A stopped run is never polled for positions again, so its file goes with it.
        $this->positionStore->forget($run->id);
        VenueEvent::whereKey($run->venue_event_id)->update(['status' => 'completed', 'ends_at' => now()]);
        $incidents = Incident::where('venue_event_id', $run->venue_event_id)->whereNotNull('active_zone_id')->get();
        foreach ($incidents as $incident) {
            $incident->update(['active_zone_id' => null, 'assigned_responder_id' => null]);
            $this->journal->append(EventType::SimulationStopped, ['reason' => $reason, 'outcome' => 'simulation_ended_not_resolved'],
                $incident->zone_id, $incident->id, $run->created_by);
        }
        Responder::where('venue_event_id', $run->venue_event_id)->update(['available' => false]);
        $responderIds = Responder::where('venue_event_id', $run->venue_event_id)->pluck('id');
        foreach (User::whereIn('responder_id', $responderIds)->where('email', 'like', 'simulation-%@example.invalid')->get() as $user) {
            $user->tokens()->where('name', 'simulation-mobile')->delete();
        }
    }

    /** One pass per five seconds, with cached reachability refreshed before transaction locks. */
    public function tick(): void
    {
        if (! config('aman.demo_enabled')) {
            return;
        }
        SimulationRun::where('status', 'running')->select('id')->eachById(function ($item) {
            try {
                $this->advance($item->id);
            } catch (\Throwable $error) {
                report($error);
            }
        });
    }

    public function advance(int $id): void
    {
        if (SimulationRun::whereKey($id)->where('status', 'running')->exists()) {
            $this->reachability->refresh();
        }
        DB::transaction(function () use ($id) {
            $run = SimulationRun::whereKey($id)->lockForUpdate()->firstOrFail();
            $now = CarbonImmutable::now();
            if ($run->status !== 'running' || ($run->last_tick_at && $run->last_tick_at->gt($now->subSeconds(5)))) {
                return;
            }
            if (! Organization::whereKey($run->organization_id)->where('status', 'active')->exists()) {
                $run->update(['status' => 'paused']);

                return;
            }
            $run->elapsed_seconds += 5;
            $run->last_tick_at = $now;
            $this->sample($run, $now);
        }, attempts: 3);
    }

    private function sample(SimulationRun $run, CarbonImmutable $now): void
    {
        $definition = $run->definition;
        $zoneMap = array_column($definition['zones'], 'id', 'key');
        $counts = array_fill_keys(array_values($zoneMap), 0);
        $quality = array_fill_keys(['located', 'outside', 'ambiguous', 'stale', 'missing'], 0);
        $positions = [];
        $examples = [];
        for ($i = 0; $i < $run->attendee_count; $i++) {
            $request = ['device' => ['phoneNumber' => $this->provider->phone($i)], 'maxAge' => 5];
            $response = $this->provider->retrieve($request, $definition, $run->attendee_count, $run->elapsed_seconds, $run->interventions ?? [], $now);
            $location = $this->locator->classify($response, $definition, $now);
            $quality[$location['status']]++;
            $zoneId = $location['zoneKey'] ? $zoneMap[$location['zoneKey']] : null;
            if ($zoneId) {
                $counts[$zoneId]++;
            }
            $positions[] = ['id' => $run->id.':attendee:'.($i + 1), 'zoneId' => $zoneId,
                'quality' => $location['status'], 'x' => $location['x'] ?? null, 'z' => $location['y'] ?? null,
                'latitude' => data_get($response, 'body.area.center.latitude'),
                'longitude' => data_get($response, 'body.area.center.longitude'),
                'accuracyMeters' => data_get($response, 'body.area.radius'),
                'observedAt' => data_get($response, 'body.lastLocationTime')];
            if ($i < 3) {
                $examples[] = ['operation' => 'location-retrieval', 'request' => $request, 'response' => $response];
            }
        }

        $responders = Responder::where('venue_event_id', $run->venue_event_id)->get()->keyBy('id');
        $missions = Incident::where('venue_event_id', $run->venue_event_id)->whereNotNull('assigned_responder_id')->get()->keyBy('assigned_responder_id');
        $zones = Zone::where('venue_event_id', $run->venue_event_id)->get()->keyBy('id');
        foreach ($definition['responders'] as $index => $member) {
            $responder = $responders[$member['id']];
            $point = $member;
            $mission = $missions->get($member['id']);
            if ($mission && $mission->status === IncidentStatus::Acknowledged) {
                $destination = collect($definition['zones'])->firstWhere('id', $mission->zone_id);
                [$left, $bottom, $right, $top] = $destination['bounds'];
                $point = data_get($responder->signals, 'simulationPoint', $member);
                $dx = ($left + $right) / 2 - $point['x'];
                $dy = ($bottom + $top) / 2 - $point['y'];
                $distance = hypot($dx, $dy);
                $zoneKey = $destination['key'];
                $fraction = $distance > 0 ? min(1, 7 / $distance) : 0;
                $point = ['x' => $point['x'] + $dx * $fraction, 'y' => $point['y'] + $dy * $fraction];
            }
            $raw = $this->provider->responderResponses($definition, $point, $index, $run->elapsed_seconds, $now, ! empty($run->interventions));
            $raw['verification'] = $this->provider->verifyAssignedArea($definition, $mission?->zone_id, $raw['location']);
            if ($mission && in_array($mission->status, [IncidentStatus::Dispatched, IncidentStatus::Acknowledged], true)) {
                $mission->update(['decision' => [...($mission->decision ?? []),
                    'arrivalVerification' => $mission->status === IncidentStatus::Acknowledged
                        ? [...$raw['verification'], 'checkedAt' => $now->toISOString()] : null]]);
            }
            $verificationArea = $raw['verification']['area'];
            $networkReachability = $this->reachability->forResponder($index);
            $raw['reachability'] = $networkReachability;
            $signals = ['source' => 'simulated_network', 'checkedAt' => $now->toISOString(),
                'location' => [...$raw['location']['area']['center'], 'accuracyMeters' => 1, 'observedAt' => $now->toISOString()],
                'reachability' => $networkReachability,
                'congestion' => $raw['congestion'], 'verification' => $raw['verification'],
                'simulationPoint' => ['x' => $point['x'], 'y' => $point['y']], 'rawResponses' => $raw,
                'verificationArea' => $verificationArea, 'communicationAdvice' => $raw['congestion'][0]['congestionLevel'] === 'High'
                    ? 'Network is congested; confirm acknowledgement and consider radio if delayed.' : 'Monitor acknowledgement.'];
            $responder->forceFill(['signals' => $signals])->save();
            $this->journal->append(EventType::ResponderUpdated, ['responderId' => $responder->id, 'signals' => $signals], actorId: $run->created_by);
        }
        $uncertain = $quality['ambiguous'] + $quality['stale'] + $quality['missing'];
        foreach ($zones as $zone) {
            $this->monitor->ingest($zone, ['sampleId' => (string) Str::uuid(), 'deviceCount' => $counts[$zone->id],
                'uncertainDeviceCount' => $uncertain, 'observedAt' => $now->toISOString()], 'simulated_network', $run->created_by);
        }
        foreach (Incident::where('venue_event_id', $run->venue_event_id)->whereIn('status', [IncidentStatus::Detected, IncidentStatus::AwaitingApproval])->whereNotNull('active_zone_id')->get() as $incident) {
            $this->workflow->recommend($incident, $run->created_by);
        }
        $run->revision++;
        $interventions = $run->interventions ?? [];
        $phase = isset($interventions['south'])
            ? ($run->elapsed_seconds >= $interventions['south'] + $definition['southRecovery']['seconds'] ? 'south_recovered' : 'south_response')
            : (isset($interventions['east'])
                ? ($run->elapsed_seconds >= $interventions['east'] + $definition['recovery']['seconds'] ? 'east_recovered' : 'east_response')
                : ($run->elapsed_seconds >= $definition['bottleneckAtSeconds'] ? 'east_bottleneck' : 'arrivals'));
        // Positions go to disk, not into the run row. See PositionStore for why.
        $this->positionStore->put($run->id, $positions);
        $run->snapshot = ['source' => 'simulated_network', 'observedAt' => $now->toISOString(),
            'phase' => $phase, 'quality' => $quality, 'zoneCounts' => $counts,
            'providerExamples' => $examples,
            'locationRequestsThisTick' => $run->attendee_count, 'reachabilityProvider' => 'nokia_sandbox'];
        $run->save();
        $this->journal->append(EventType::SimulationUpdated, ['runId' => $run->id, 'venueEventId' => $run->venue_event_id,
            'revision' => $run->revision, 'phase' => $phase, 'quality' => $quality], actorId: $run->created_by);
    }
}
