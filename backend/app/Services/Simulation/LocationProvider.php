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
        $progress = $this->movementProgress(
            $start,
            $end,
            $elapsed - $start['t'],
            $end['t'] - $start['t'],
            $definition['maxWalkingSpeedMetersPerSecond']
        );
        $x = $start['x'] + ($end['x'] - $start['x']) * $progress;
        $y = $start['y'] + ($end['y'] - $start['y']) * $progress;
        $eastRecoveryApplied = false;
        if (isset($interventions['east']) && isset($route['recovery'])) {
            $at = $this->point($definition, $index, $count, $interventions['east'], []);
            $destination = $this->recoveryDestination($route, $index);
            $targets = array_map(fn (array $point): array => [
                'x' => $point['x'] + $this->offset($index, 31),
                'y' => $point['y'] + $this->offset($index, 47),
            ], [...($destination['waypoints'] ?? []), $destination['point']]);
            $recoveryPoint = $this->pointAlongPath(
                $at,
                $targets,
                $elapsed - $interventions['east'],
                $definition['recovery']['seconds'],
                $definition['maxWalkingSpeedMetersPerSecond']
            );
            $x = $recoveryPoint['x'];
            $y = $recoveryPoint['y'];
            $eastRecoveryApplied = true;
        }

        if (isset($interventions['south']) && $this->southRecoveryDestination($route, $index) !== null) {
            $beforeSouth = $interventions;
            unset($beforeSouth['south']);
            $at = $this->point($definition, $index, $count, $interventions['south'], $beforeSouth);
            $destination = $this->southRecoveryDestination($route, $index);
            $target = [
                'x' => $destination['x'] + $this->offset($index, 31),
                'y' => $destination['y'] + $this->offset($index, 47),
            ];
            $recovery = $this->movementProgress(
                $at,
                $target,
                $elapsed - $interventions['south'],
                $definition['southRecovery']['seconds'],
                $definition['maxWalkingSpeedMetersPerSecond']
            );

            return ['x' => $at['x'] + ($target['x'] - $at['x']) * $recovery,
                'y' => $at['y'] + ($target['y'] - $at['y']) * $recovery];
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

    private function movementProgress(array $start, array $end, int $elapsed, int $plannedSeconds, float $maximumSpeed): float
    {
        if ($elapsed <= 0) {
            return 0;
        }

        $distance = hypot($end['x'] - $start['x'], $end['y'] - $start['y']);
        if ($distance === 0.0) {
            return 1;
        }

        $plannedProgress = $plannedSeconds > 0 ? min(1, $elapsed / $plannedSeconds) : 1;
        $speedLimitedProgress = min(1, $elapsed * $maximumSpeed / $distance);

        return min($plannedProgress, $speedLimitedProgress);
    }

    private function pointAlongPath(array $start, array $targets, int $elapsed, int $plannedSeconds, float $maximumSpeed): array
    {
        $segments = [];
        $distance = 0.0;
        $from = $start;
        foreach ($targets as $target) {
            $length = hypot($target['x'] - $from['x'], $target['y'] - $from['y']);
            $segments[] = ['from' => $from, 'to' => $target, 'length' => $length];
            $distance += $length;
            $from = $target;
        }

        if ($distance === 0.0 || $elapsed <= 0) {
            return $start;
        }

        $plannedProgress = $plannedSeconds > 0 ? min(1, $elapsed / $plannedSeconds) : 1;
        $travelled = min($distance * $plannedProgress, $elapsed * $maximumSpeed);
        foreach ($segments as $segment) {
            if ($segment['length'] === 0.0) {
                continue;
            }
            if ($travelled <= $segment['length']) {
                $progress = $travelled / $segment['length'];

                return [
                    'x' => $segment['from']['x'] + ($segment['to']['x'] - $segment['from']['x']) * $progress,
                    'y' => $segment['from']['y'] + ($segment['to']['y'] - $segment['from']['y']) * $progress,
                ];
            }
            $travelled -= $segment['length'];
        }

        return $targets[array_key_last($targets)] ?? $start;
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
