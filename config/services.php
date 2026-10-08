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

    // Chinggis Airlines agent system: receives the allocations of the listed agencies.
    'chinggis' => [
        'base_url' => env('CHINGGIS_AGENT_API_BASE_URL'),
        'key' => env('CHINGGIS_AGENT_API_KEY'),
        'timeout' => (int) env('CHINGGIS_AGENT_API_TIMEOUT', 15),
        'agency_ids' => array_map('intval', array_filter(explode(',', (string) env('CHINGGIS_AGENCY_IDS', '')))),
    ],

];
