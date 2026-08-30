<?php

return [
    'provider' => env('POPULATION_DENSITY_PROVIDER', 'disabled'),
    'timeout_seconds' => 10,
    'orange' => [
        'client_id' => env('ORANGE_CLIENT_ID'),
        'client_secret' => env('ORANGE_CLIENT_SECRET'),
        'token_url' => env('ORANGE_TOKEN_URL', 'https://api.orange.com/openidconnect/playground/v1.0/token'),
        'api_url' => env('ORANGE_POPULATION_DENSITY_URL', 'https://api.orange.com/camara/playground/api/population-density-data/v0.2/retrieve'),
    ],
];
