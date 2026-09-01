<?php

namespace App\Services;

use App\Enums\EventType;
use App\Models\DomainEvent;
use App\Models\Incident;
use App\Models\User;
use App\Models\Zone;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class EventJournal
{
    // Called inside the same transaction as the state change (transactional outbox).
    public function append(EventType $type, array $payload, ?string $zoneId = null, ?string $incidentId = null, ?int $actorId = null): DomainEvent
    {
        if (DB::transactionLevel() === 0) {
            throw new \LogicException('Journal writes must be part of a state transaction.');
        }
        // Serialize sequence allocation until commit. Otherwise a later transaction
        // could commit first and a replay cursor could skip a still-uncommitted ID.
        DB::table('event_stream_locks')->where('id', 1)->lockForUpdate()->first();
        $organizationId = $actorId ? User::find($actorId)?->organization_id : null;
        $organizationId ??= $zoneId ? Zone::find($zoneId)?->organization_id : null;
        $organizationId ??= $incidentId ? Incident::find($incidentId)?->organization_id : null;

        return DomainEvent::create([
            'organization_id' => $organizationId,
            'event_id' => (string) Str::uuid(),
            'type' => $type->value,
            'zone_id' => $zoneId,
            'incident_id' => $incidentId,
            'actor_id' => $actorId,
            'payload' => $payload,
            'occurred_at' => now(),
        ]);
    }
}
