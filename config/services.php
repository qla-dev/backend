<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'token' => env('POSTMARK_TOKEN'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'resend' => [
        'key' => env('RESEND_KEY'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'openrouter' => [
        'api_key' => env('OPENROUTER_API_KEY'),
        'model' => env('OPENROUTER_MODEL', 'google/gemini-2.5-flash'),
        // Used by OpenRouterDispatchAssistant when the primary model's response comes back empty
        // (a known intermittent Gemini-via-OpenRouter failure) or fails to connect - the retry
        // switches models instead of hitting the same flaky provider again. Leave unset to disable
        // the model switch and just retry the primary model a second time.
        'fallback_model' => env('OPENROUTER_FALLBACK_MODEL'),
        'url' => env('OPENROUTER_URL', 'https://openrouter.ai/api/v1/chat/completions'),
    ],

    'fuelo' => [
        'base_url' => env('FUELO_BASE_URL', 'https://de.fuelo.net/'),
        'stations_url' => env('FUELO_STATIONS_URL', 'https://de.fuelo.net/ajax/get_gasstations_within_bounds_mysql_clustering'),
        'python_binary' => env('FUELO_PYTHON_BINARY', 'python3'),
    ],

    'vessel_stream' => [
        'api_key' => env('AISSTREAM_API_KEY'),
        'url' => env('AISSTREAM_URL', 'wss://stream.aisstream.io/v0/stream'),
    ],

    'google' => [
        'client_ids' => array_values(array_unique(array_filter([
            ...array_map('trim', explode(',', (string) env('GOOGLE_CLIENT_IDS', ''))),
            trim((string) env('GOOGLE_WEB_CLIENT_ID', '')),
            trim((string) env('GOOGLE_IOS_CLIENT_ID', '')),
            trim((string) env('GOOGLE_ANDROID_CLIENT_ID', '')),
        ]))),
    ],

    'apple' => [
        'client_ids' => array_values(array_filter(array_map(
            'trim',
            explode(',', (string) env('APPLE_CLIENT_IDS', ''))
        ))),
    ],

];
