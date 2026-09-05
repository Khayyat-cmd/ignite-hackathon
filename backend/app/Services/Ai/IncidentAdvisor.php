<?php

namespace App\Services\Ai;

use App\Models\Incident;

interface IncidentAdvisor
{
    /**
     * @return array<string, mixed>
     */
    public function advise(Incident $incident): array;
}
