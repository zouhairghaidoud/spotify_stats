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

    'spotify' => [
        'client_id' => env('SPOTIFY_CLIENT_ID'),
        'client_secret' => env('SPOTIFY_CLIENT_SECRET'),
        'redirect' => env('SPOTIFY_REDIRECT_URI'),
    ],

    'musicbrainz' => [
        'base_url' => env('MUSICBRAINZ_BASE_URL', 'https://musicbrainz.org/ws/2'),
        'user_agent' => env(
            'MUSICBRAINZ_USER_AGENT',
            'SpotifyStats/1.0 ('.env('APP_URL', 'http://localhost').')',
        ),
        'delay_ms' => (int) env('MUSICBRAINZ_DELAY_MS', 1500),
        'retry_sleep_seconds' => (int) env('MUSICBRAINZ_RETRY_SLEEP_SECONDS', 2),
    ],

    'lastfm' => [
        'base_url' => env('LASTFM_BASE_URL', 'https://ws.audioscrobbler.com/2.0'),
        'api_key' => env('LASTFM_API_KEY'),
        'tag_limit' => (int) env('LASTFM_TAG_LIMIT', 5),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

];
