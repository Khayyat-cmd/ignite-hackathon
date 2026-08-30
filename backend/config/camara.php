<?php

return [
    'mode' => env('CAMARA_MODE', 'disabled'), // disabled, sandbox, live
    'base_url' => env('CAMARA_BASE_URL', 'https://network-as-code.p-eu.apihub.nokia.io'),
    'host' => env('CAMARA_HOST', 'network-as-code.nokia.rapidapi.com'),
    'api_key' => env('CAMARA_API_KEY'),
    'timeout_seconds' => 8,
    'connect_timeout_seconds' => 3,
    'cache_seconds' => 10,
    'paths' => [
        'location' => '/location-retrieval/v0/retrieve',
        'verification' => '/location-verification/v1/verify',
        'reachability' => '/device-status/device-reachability-status/v1/retrieve',
        'congestion' => '/congestion-insights/v0/query',
    ],
];
