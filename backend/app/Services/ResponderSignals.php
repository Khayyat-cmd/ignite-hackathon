<?php

namespace App\Services;

use App\Enums\EventType;
use App\Exceptions\IntegrationUnavailable;
use App\Models\Responder;
use App\Models\Zone;
use App\Services\Camara\NokiaNetwork;
use Illuminate\Support\Facades\DB;

final class ResponderSignals
{
    public function __construct(private NokiaNetwork $network, private EventJournal $journal) {}

    public function refresh(Responder $responder, ?int $actorId = null): Responder
    {
        abort_unless($responder->authorized, 403, 'Device authorization is required before querying the network.');
        $signals = ['source' => 'nokia_'.config('camara.mode'), 'checkedAt' => now()->toISOString()];
        foreach (['location', 'reachability', 'congestion'] as $operation) {
            try {
                $signals[$operation] = $this->network->{$operation}($responder->phone_number);
            } catch (IntegrationUnavailable $e) {
                // Unknown is not unreachable or safe; never retain old successful evidence as fresh.
                $signals[$operation] = null;
                $signals['errors'][$operation] = $e->reason;
            }
        }
        $zone = $responder->demo_position
            ? Zone::where('organization_id', $responder->organization_id)->find($responder->demo_position['zoneId'] ?? '')
            : null;
        if ($zone && config('aman.demo_enabled') && ! app()->environment('production') && config('camara.mode') === 'sandbox') {
            $signals['verificationArea'] = ['latitude' => $zone->latitude, 'longitude' => $zone->longitude, 'radiusMeters' => 1500, 'basis' => 'broad_demo_venue_area_not_zone_membership'];
            try {
                $signals['verification'] = $this->network->verify($responder->phone_number, $zone->latitude, $zone->longitude, 1500);
            } catch (IntegrationUnavailable $e) {
                $signals['verification'] = null;
                $signals['errors']['verification'] = $e->reason;
            }
        }

        return $this->store($responder, $signals, $actorId);
    }

    public function store(Responder $responder, array $signals, ?int $actorId): Responder
    {
        return DB::transaction(function () use ($responder, $signals, $actorId) {
            $locked = Responder::where('organization_id', $responder->organization_id)->whereKey($responder->id)->lockForUpdate()->firstOrFail();
            if ($locked->signals && ($locked->signals['checkedAt'] ?? '') > $signals['checkedAt']) {
                return $locked;
            }
            $locked->forceFill(['signals' => $signals])->save();
            $this->journal->append(EventType::ResponderUpdated, ['responderId' => $locked->id, 'signals' => $signals], actorId: $actorId);

            return $locked;
        });
    }
}
