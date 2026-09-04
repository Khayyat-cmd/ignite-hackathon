<?php

namespace App\Services\Simulation;

use Carbon\CarbonImmutable;

/** Local provider: Nokia-shaped bodies; transport and timings are simulated. */
final class LocationProvider
{
    public function coordinates(array $definition, float $x, float $y): array
    {
        $origin = $definition['origin'];

        return ['latitude' => $origin['latitude'] + $y / 111320,
            'longitude' => $origin['longitude'] + $x / (111320 * cos(deg2rad($origin['latitude'])))];
    }

    public function phone(int $index): string
    {
        return '+999'.str_pad((string) ($index + 1), 10, '0', STR_PAD_LEFT);
    }

    public function point(array $definition, int $index, int $count, int $elapsed, array $interventions = []): array
    {
        $fraction = $index / $count;
        $share = 0;
        $route = $definition['routes'][array_key_last($definition['routes'])];
        foreach ($definition['routes'] as $candidate) {
            $share += $candidate['share'];
            if ($fraction < $share) {
                $route = $candidate;
                break;
            }
        }
        [$start, $end] = $route['points'];
        $progress = min(1, max(0, ($elapsed - $start['t']) / ($end['t'] - $start['t'])));
        $x = $start['x'] + ($end['x'] - $start['x']) * $progress;
        $y = $start['y'] + ($end['y'] - $start['y']) * $progress;
        $eastRecoveryApplied = false;
        if (isset($interventions['east']) && isset($route['recovery'])) {
            $at = $this->point($definition, $index, $count, $interventions['east'], []);
            $recovery = min(1, max(0, ($elapsed - $interventions['east']) / $definition['recovery']['seconds']));
            $destination = $this->recoveryDestination($route, $index);
            $x = $at['x'] + ($destination['point']['x'] + $this->offset($index, 31) - $at['x']) * $recovery;
            $y = $at['y'] + ($destination['point']['y'] + $this->offset($index, 47) - $at['y']) * $recovery;
            $eastRecoveryApplied = true;
        }

        if (isset($interventions['south']) && $this->southRecoveryDestination($route, $index) !== null) {
            $beforeSouth = $interventions;
            unset($beforeSouth['south']);
            $at = $this->point($definition, $index, $count, $interventions['south'], $beforeSouth);
            $recovery = min(1, max(0, ($elapsed - $interventions['south']) / $definition['southRecovery']['seconds']));
            $destination = $this->southRecoveryDestination($route, $index);

            return ['x' => $at['x'] + ($destination['x'] + $this->offset($index, 31) - $at['x']) * $recovery,
                'y' => $at['y'] + ($destination['y'] + $this->offset($index, 47) - $at['y']) * $recovery];
        }

        return ['x' => $eastRecoveryApplied ? $x : $x + $this->offset($index, 31),
            'y' => $eastRecoveryApplied ? $y : $y + $this->offset($index, 47)];
    }

    private function recoveryDestination(array $route, int $index): array
    {
        $destinations = $route['recovery'];
        $selection = (($index * 73) % 997) / 997;
        $share = 0;
        $destination = $destinations[array_key_last($destinations)];
        foreach ($destinations as $candidate) {
            $share += $candidate['share'];
            if ($selection < $share) {
                $destination = $candidate;

                break;
            }
        }

        return $destination;
    }

    private function southRecoveryDestination(array $route, int $index): ?array
    {
        if (isset($route['southRecovery'])) {
            return $route['southRecovery'];
        }
        if (! isset($route['recovery'])) {
            return null;
        }

        $destination = $this->recoveryDestination($route, $index);

        return ($destination['key'] ?? null) === 'south' ? $destination['southRecovery'] : null;
    }

    private function offset(int $index, int $factor): float
    {
        return (($index * $factor) % 997) / 997 * 16 - 8;
    }

    public function retrieve(array $request, array $definition, int $count, int $elapsed, array $interventions, CarbonImmutable $now): array
    {
        $phone = data_get($request, 'device.phoneNumber', '');
        $index = (int) substr($phone, 4) - 1;
        if ($index < 0 || $index >= $count || $phone !== $this->phone($index)) {
            return ['status' => 404, 'body' => ['status' => 404, 'code' => 'LOCATION_RETRIEVAL.DEVICE_NOT_FOUND', 'message' => 'Unknown simulated device.']];
        }
        $faultWindow = $elapsed >= 35 && $elapsed < 65;
        if ($faultWindow && $index % 101 === 0) {
            return ['status' => 503, 'body' => ['status' => 503, 'code' => 'UNAVAILABLE', 'message' => 'Scripted temporary location outage.']];
        }
        $point = $this->point($definition, $index, $count, $elapsed, $interventions);
        $stale = $faultWindow && $index % 103 === 0;
        $uncertain = $faultWindow && $index % 107 === 0;

        return ['status' => 200, 'body' => [
            'lastLocationTime' => ($stale ? $now->subSeconds(30) : $now)->toISOString(),
            'area' => ['areaType' => 'CIRCLE', 'center' => $this->coordinates($definition, $point['x'], $point['y']),
                'radius' => $uncertain ? 150 : $definition['precisionMeters']],
        ]];
    }

    public function responderResponses(array $definition, array $point, int $index, int $elapsed, CarbonImmutable $now, bool $recovering): array
    {
        $disconnected = $index === 0 && $elapsed >= 30 && $elapsed < 100;
        $smsOnly = $index === 3 && $elapsed >= 40 && $elapsed < 80;
        $location = ['lastLocationTime' => $now->toISOString(), 'area' => [
            'areaType' => 'CIRCLE', 'center' => $this->coordinates($definition, $point['x'], $point['y']), 'radius' => 1,
        ]];

        return ['location' => $location,
            'reachability' => ['reachable' => ! $disconnected, 'connectivity' => $disconnected ? [] : ($smsOnly ? ['SMS'] : ['DATA', 'SMS']), 'lastStatusTime' => $now->toISOString()],
            'congestion' => [['timeIntervalStart' => $now->subSeconds(5)->toISOString(), 'timeIntervalStop' => $now->toISOString(),
                'congestionLevel' => $recovering ? 'Low' : ($elapsed >= 40 ? 'High' : ($elapsed >= 20 ? 'Medium' : 'Low')), 'confidenceLevel' => 95]],
        ];
    }

    public function verify(array $location, array $area): array
    {
        $actual = $location['area'];
        $lat = deg2rad(($area['center']['latitude'] + $actual['center']['latitude']) / 2);
        $dx = ($actual['center']['longitude'] - $area['center']['longitude']) * 111320 * cos($lat);
        $dy = ($actual['center']['latitude'] - $area['center']['latitude']) * 111320;
        $distance = hypot($dx, $dy);
        $result = $distance + $actual['radius'] <= $area['radius'] ? 'TRUE'
            : ($distance > $area['radius'] + $actual['radius'] ? 'FALSE' : 'PARTIAL');

        return ['verificationResult' => $result, 'lastLocationTime' => $location['lastLocationTime']];
    }

    public function verifyAssignedArea(array $definition, ?string $zoneId, ?array $location): array
    {
        $zone = collect($definition['zones'])->firstWhere('id', $zoneId);
        if (! $zoneId || ! $zone || ! $location) {
            return ['verificationResult' => 'UNKNOWN', 'zoneId' => $zoneId, 'area' => null, 'source' => 'simulated_network'];
        }
        [$left, $bottom, $right, $top] = $zone['bounds'];
        $area = ['areaType' => 'CIRCLE',
            'center' => $this->coordinates($definition, ($left + $right) / 2, ($bottom + $top) / 2),
            'radius' => min($right - $left, $top - $bottom) / 2 - 1];
        $result = $this->verify($location, $area);
        $time = CarbonImmutable::parse($location['lastLocationTime']);
        if ($time->lt(now()->subSeconds(15)) || $time->gt(now()->addSeconds(5))) {
            $result['verificationResult'] = 'UNKNOWN';
        }

        return [...$result, 'zoneId' => $zoneId, 'area' => $area, 'source' => 'simulated_network'];
    }
}
