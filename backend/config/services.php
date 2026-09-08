<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Resend, Postmark, AWS, and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'openai' => [
        'key' => env('OPENAI_API_KEY'),
        'base_url' => env('OPENAI_BASE_URL', 'https://api.openai.com/v1'),
        'model' => env('OPENAI_MODEL', 'gpt-5.6-luna'),
        'reasoning_effort' => env('OPENAI_REASONING_EFFORT', 'low'),
        'max_output_tokens' => env('OPENAI_MAX_OUTPUT_TOKENS', 500),
        'timeout_seconds' => env('OPENAI_TIMEOUT_SECONDS', 20),
    ],

    'firebase' => [
        'project_id' => env('FIREBASE_PROJECT_ID', 'aman-df1f1'),
        // A blank GOOGLE_APPLICATION_CREDENTIALS must fall back to the bundled path,
        // not to an empty string that silently disables push.
        'credentials' => env('GOOGLE_APPLICATION_CREDENTIALS') ?: storage_path('app/private/firebase-service-account.json'),
        'timeout_seconds' => env('FIREBASE_TIMEOUT_SECONDS', 10),
    ],

];
