<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DomainEvent extends Model
{
    public $timestamps = false;

    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['payload' => 'array', 'occurred_at' => 'immutable_datetime', 'published_at' => 'immutable_datetime'];
    }

    public function envelope(): array
    {
        return [
            'schemaVersion' => 1,
            'sequence' => (string) $this->id,
            'eventId' => $this->event_id,
            'event' => $this->type,
            'occurredAt' => $this->occurred_at->toISOString(),
            'zoneId' => $this->zone_id,
            'incidentId' => $this->incident_id,
            'data' => $this->payload,
        ];
    }
}
