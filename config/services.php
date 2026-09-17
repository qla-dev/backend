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
        'speech_model' => env('OPENROUTER_SPEECH_MODEL', 'google/gemini-3.1-flash-tts-preview'),
        'api_key' => env('OPENROUTER_API_KEY'),
        'model' => env('OPENROUTER_MODEL', 'google/gemini-2.5-flash'),
        // Used by OpenRouterDispatchAssistant when the primary model's response comes back empty
        // (a known intermittent Gemini-via-OpenRouter failure) or fails to connect - the retry
        // switches models instead of hitting the same flaky provider again. Leave unset to disable
        // the model switch and just retry the primary model a second time.
        'fallback_model' => env('OPENROUTER_FALLBACK_MODEL'),
        // Draws images in LenaAI training mode (superadmins only), after the admin approves one.
        'image_model' => env('OPENROUTER_IMAGE_MODEL', 'google/gemini-2.5-flash-image'),
        'url' => env('OPENROUTER_URL', 'https://openrouter.ai/api/v1/chat/completions'),
        // Turns a driver's recorded question into text before it reaches DispatchChatController.
        // Whisper Large v3 Turbo served by Groq is the fastest transcription on OpenRouter, so it
        // is the primary; LenaTranscription retries on the fallback model when the primary fails
        // or returns nothing, mirroring the pair OpenRouterDispatchAssistant already uses.
        'transcription_model' => env('OPENROUTER_TRANSCRIPTION_MODEL', 'openai/whisper-large-v3-turbo'),
        'transcription_fallback_model' => env('OPENROUTER_TRANSCRIPTION_FALLBACK_MODEL', 'openai/whisper-1'),
        // Whisper Large v3 is served by several providers; pinning the order keeps the primary on
        // Groq's inference rather than a slower one. Leave empty to let OpenRouter choose.
        'transcription_provider' => env('OPENROUTER_TRANSCRIPTION_PROVIDER', 'groq'),
        'transcription_url' => env('OPENROUTER_TRANSCRIPTION_URL', 'https://openrouter.ai/api/v1/audio/transcriptions'),
    ],

    // Lena's live call talks straight to OpenAI, because a realtime session is a persistent WebRTC
    // connection and OpenRouter exposes only request/response endpoints. Everything else Lena does
    // stays on OpenRouter. With no key set, LenaRealtimeSession refuses to mint a token and the
    // call screen reports the feature as unconfigured; nothing else in the app is affected.
    'openai' => [
        'api_key' => env('OPENAI_API_KEY'),
        'realtime_model' => env('OPENAI_REALTIME_MODEL', 'gpt-realtime-2.1'),
        'realtime_voice' => env('OPENAI_REALTIME_VOICE', 'marin'),
        'realtime_client_secrets_url' => env('OPENAI_REALTIME_CLIENT_SECRETS_URL', 'https://api.openai.com/v1/realtime/client_secrets'),
        // The app POSTs its SDP offer here with the ephemeral secret. Exposed through the session
        // endpoint so the URL can move without shipping a new build.
        'realtime_calls_url' => env('OPENAI_REALTIME_CALLS_URL', 'https://api.openai.com/v1/realtime/calls'),
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

    'open_waters' => [
        'url' => 'https://ais.openwaters.io',
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
