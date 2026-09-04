<?php

namespace App\Services\Demo;

use App\Models\Organization;
use App\Models\Responder;
use App\Models\SimulationRun;
use App\Models\User;
use Illuminate\Support\Str;

/**
 * Provides the single, local workspace used by the self-contained judging demo.
 *
 * There is intentionally no browser login in this build. This class is the one
 * boundary that owns the demo identity, so authentication can be introduced
 * later without leaking it through the simulation workflow.
 */
final class DemoWorkspace
{
    public function operator(): User
    {
        $organization = Organization::firstOrCreate(
            ['slug' => 'aman-local-demo'],
            ['name' => 'AMAN Local Demo', 'status' => 'active'],
        );

        return User::firstOrCreate(
            ['email' => 'operator@aman.local'],
            [
                'organization_id' => $organization->id,
                'name' => 'Demo Operator',
                'password' => Str::random(64),
                'role' => 'operator',
                'status' => 'active',
            ],
        );
    }

    public function owns(SimulationRun $run): void
    {
        abort_unless($run->organization_id === $this->operator()->organization_id, 404);
    }

    public function responder(string $responderId, SimulationRun $run): Responder
    {
        $this->owns($run);

        return Responder::where('venue_event_id', $run->venue_event_id)->findOrFail($responderId);
    }

    public function responderActor(Responder $responder): User
    {
        return User::firstOrCreate(
            ['email' => 'responder-'.$responder->id.'@aman.local'],
            [
                'organization_id' => $responder->organization_id,
                'name' => $responder->name,
                'password' => Str::random(64),
                'role' => 'responder',
                'status' => 'active',
                'responder_id' => $responder->id,
            ],
        );
    }
}
