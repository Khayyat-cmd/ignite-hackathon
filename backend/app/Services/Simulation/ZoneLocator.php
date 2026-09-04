<?php

namespace App\Services\Simulation;

use Carbon\CarbonImmutable;

final class ZoneLocator
{
    /** Fixture rectangles are measured in metres relative to the declared origin. */
    public function classify(array $response, array $definition, CarbonImmutable $now, int $maxAge = 5): array
    {
        if (($response['status'] ?? 0) !== 200) {
            return ['status' => 'missing', 'zoneKey' => null];
        }
        $body = $response['body'];
        $time = CarbonImmutable::parse($body['lastLocationTime']);
        if ($time->lt($now->subSeconds($maxAge)) || $time->gt($now)) {
            return ['status' => 'stale', 'zoneKey' => null];
        }
        $origin = $definition['origin'];
        $center = $body['area']['center'];
        $x = ($center['longitude'] - $origin['longitude']) * 111320 * cos(deg2rad($origin['latitude']));
        $y = ($center['latitude'] - $origin['latitude']) * 111320;
        $radius = $body['area']['radius'];
        $contained = [];
        $intersects = false;
        foreach ($definition['zones'] as $zone) {
            [$left, $bottom, $right, $top] = $zone['bounds'];
            if ($x - $radius > $left && $x + $radius < $right && $y - $radius > $bottom && $y + $radius < $top) {
                $contained[] = $zone['key'];
            }
            $nearX = max($left, min($right, $x));
            $nearY = max($bottom, min($top, $y));
            $intersects = $intersects || (($nearX - $x) ** 2 + ($nearY - $y) ** 2 <= $radius ** 2);
        }

        return ['status' => count($contained) === 1 ? 'located' : ($intersects ? 'ambiguous' : 'outside'),
            'zoneKey' => count($contained) === 1 ? $contained[0] : null, 'x' => round($x, 3), 'y' => round($y, 3)];
    }
}
