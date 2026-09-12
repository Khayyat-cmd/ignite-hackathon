<?php

return [
    'reachability' => [
        'key' => env('CAMARA_API_KEY'),
        'enabled' => env('NOKIA_REACHABILITY_ENABLED', true),
        'url' => 'https://network-as-code.p-eu.apihub.nokia.io/device-status/device-reachability-status/v1/retrieve',
        'devices' => ['+99999991001', '+99999991002', '+99999991001', '+99999991003'],
    ],
    // The CAMARA orchestration agent (ml/agent) runs as its own loopback service.
    // Laravel is the only caller and the only holder of provider credentials.
    'agent' => [
        'url' => env('AMAN_AGENT_URL'),
        'token' => env('AMAN_AGENT_SERVICE_TOKEN'),
        'timeout_seconds' => env('AMAN_AGENT_TIMEOUT_SECONDS', 45),
        // The private token the agent presents back to the CAMARA tool gateway.
        'gateway_token' => env('AMAN_CAMARA_GATEWAY_TOKEN'),
    ],
    // Demo ingestion is deliberately off until explicitly enabled by a developer.
    'demo_enabled' => (bool) env('AMAN_DEMO_ENABLED', false),
    'observation_max_age_seconds' => 120,
    'signal_max_age_seconds' => 120,
    'future_tolerance_seconds' => 5,
    'recovery_fraction' => 0.8,
    'resolution_stable_seconds' => 15,
    'max_candidates' => 50,
    'max_location_accuracy_meters' => 100,
];
