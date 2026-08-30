<?php

namespace App\Services\Camara;

use App\Exceptions\IntegrationUnavailable;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Validator;

final class NokiaNetwork
{
    public function __construct(private NokiaClient $client) {}

    public function location(string $phone): array
    {
        $body = $this->client->query('location', ['device' => ['phoneNumber' => $phone], 'maxAge' => config('aman.signal_max_age_seconds')]);
        // Preserve uncertainty; a polygon is not converted into a fictitious exact point.
        $this->validate($body, [
            'lastLocationTime' => 'required|date',
            'area.areaType' => 'required|in:CIRCLE',
            'area.center.latitude' => 'required|numeric|between:-90,90',
            'area.center.longitude' => 'required|numeric|between:-180,180',
            'area.radius' => 'required|numeric|min:0',
        ]);

        return [
            'latitude' => (float) $body['area']['center']['latitude'],
            'longitude' => (float) $body['area']['center']['longitude'],
            'accuracyMeters' => (float) $body['area']['radius'],
            'observedAt' => CarbonImmutable::parse($body['lastLocationTime'])->toISOString(),
        ];
    }

    public function reachability(string $phone): array
    {
        $body = $this->client->query('reachability', ['device' => ['phoneNumber' => $phone]]);
        $this->validate($body, ['reachable' => 'required|boolean', 'connectivity' => 'sometimes|array', 'connectivity.*' => 'in:DATA,SMS', 'lastStatusTime' => 'sometimes|nullable|date']);
        if (! is_bool($body['reachable'])) {
            throw new IntegrationUnavailable('malformed_response');
        }

        return [
            'reachable' => $body['reachable'],
            'dataReachable' => $body['reachable'] && in_array('DATA', $body['connectivity'] ?? [], true),
            'connectivity' => $body['connectivity'] ?? [],
            'observedAt' => isset($body['lastStatusTime']) ? CarbonImmutable::parse($body['lastStatusTime'])->toISOString() : null,
        ];
    }

    public function verify(string $phone, float $latitude, float $longitude, float $radius): array
    {
        $body = $this->client->query('verification', ['device' => ['phoneNumber' => $phone], 'area' => ['areaType' => 'CIRCLE', 'center' => ['latitude' => $latitude, 'longitude' => $longitude], 'radius' => $radius]]);
        $this->validate($body, ['verificationResult' => 'required|in:TRUE,FALSE,PARTIAL,UNKNOWN', 'lastLocationTime' => 'sometimes|date']);

        return array_intersect_key($body, array_flip(['verificationResult', 'lastLocationTime', 'matchRate']));
    }

    public function congestion(string $phone): array
    {
        $body = $this->client->query('congestion', ['device' => ['phoneNumber' => $phone]]);
        if (! array_is_list($body)) {
            throw new IntegrationUnavailable('malformed_response');
        }
        $this->validate(['intervals' => $body], ['intervals' => 'array|max:1000', 'intervals.*.timeIntervalStart' => 'required|date', 'intervals.*.timeIntervalStop' => 'required|date', 'intervals.*.congestionLevel' => 'required|in:Low,Medium,High', 'intervals.*.confidenceLevel' => 'nullable|integer|between:0,100']);

        return array_map(fn (array $interval) => array_intersect_key($interval, array_flip(['timeIntervalStart', 'timeIntervalStop', 'congestionLevel', 'confidenceLevel'])), $body);
    }

    private function validate(array $body, array $rules): void
    {
        if (Validator::make($body, $rules)->fails()) {
            throw new IntegrationUnavailable('malformed_or_unsupported_response');
        }
    }
}
