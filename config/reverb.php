<?php

// parse_url finds no host in a scheme-less value like `example.com`, and falling back to `*` there
// disabled origin checking altogether — Reverb short-circuits the whole check on a wildcard, so a
// missing scheme in APP_URL silently let any site open a socket. A scheme is prepended when one is
// absent so a bare hostname still resolves; anything left unparseable yields an empty list, which
// rejects every origin instead of accepting all of them.
$appUrl = (string) config('app.url');
$broadcaster = config()->array('broadcasting.connections.reverb');

$appHost = (string) (parse_url(str_contains($appUrl, '://') ? $appUrl : 'https://'.$appUrl, PHP_URL_HOST) ?: '');

return [

    /*
    |--------------------------------------------------------------------------
    | Default Reverb Server
    |--------------------------------------------------------------------------
    |
    | This option controls the default server used by Reverb to handle
    | incoming messages as well as broadcasting message to all your
    | connected clients. At this time only "reverb" is supported.
    |
    */

    'default' => env('REVERB_SERVER', 'reverb'),

    /*
    |--------------------------------------------------------------------------
    | Reverb Servers
    |--------------------------------------------------------------------------
    |
    | Here you may define details for each of the supported Reverb servers.
    | Each server has its own configuration options that are defined in
    | the array below. You should ensure all the options are present.
    |
    */

    'servers' => [

        'reverb' => [
            'host' => env('REVERB_SERVER_HOST', '0.0.0.0'),
            'port' => env('REVERB_SERVER_PORT', 8080),
            'path' => env('REVERB_SERVER_PATH', ''),
            'hostname' => env('REVERB_HOST'),
            'options' => [
                'tls' => [],
            ],
            'max_request_size' => env('REVERB_MAX_REQUEST_SIZE', 10_000),
            // On by default, not off as the framework ships it: Redis is in this stack for exactly this,
            // and leaving it off meant development ran a single-process Reverb while the preview cluster
            // ran the Redis-backed one — a parity gap for the one thing scaling changes.
            'scaling' => [
                'enabled' => env('REVERB_SCALING_ENABLED', true),
                'channel' => env('REVERB_SCALING_CHANNEL', 'reverb'),
                // Reverb resolves this through ConfigurationUrlParser, which merges the URL's
                // components over the discrete keys, so a URL silently wins over any host, port,
                // username, password, or database set alongside it. Only the URL is offered here so
                // there is one way to point Reverb at Redis. timeout is read straight off this array
                // rather than through the URL, so it stays separate.
                'server' => [
                    'url' => env('REDIS_URL', 'redis://127.0.0.1:6379'),
                    'timeout' => env('REDIS_TIMEOUT', 60),
                ],
            ],
            'pulse_ingest_interval' => env('REVERB_PULSE_INGEST_INTERVAL', 15),
            'telescope_ingest_interval' => env('REVERB_TELESCOPE_INGEST_INTERVAL', 15),
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Reverb Applications
    |--------------------------------------------------------------------------
    |
    | Here you may define how Reverb applications are managed. If you choose
    | to use the "config" provider, you may define an array of apps which
    | your server will support, including their connection credentials.
    |
    */

    'apps' => [

        'provider' => 'config',

        'apps' => [
            [
                // The server's half of the same handshake config/broadcasting.php configures the client
                // for. Declared once there and read here: a default changed on one side only means every
                // broadcast is signed for an app this server has never heard of.
                'key' => $broadcaster['key'],
                'secret' => $broadcaster['secret'],
                'app_id' => $broadcaster['app_id'],
                'options' => $broadcaster['options'],
                // Reverb matches the Origin header's host only, so APP_URL's host is the right value.
                'allowed_origins' => array_filter(explode(',', (string) env('REVERB_ALLOWED_ORIGINS', $appHost))),
                'ping_interval' => env('REVERB_APP_PING_INTERVAL', 60),
                'activity_timeout' => env('REVERB_APP_ACTIVITY_TIMEOUT', 30),
                'max_connections' => env('REVERB_APP_MAX_CONNECTIONS'),
                'max_message_size' => env('REVERB_APP_MAX_MESSAGE_SIZE', 10_000),
                'accept_client_events_from' => env('REVERB_APP_ACCEPT_CLIENT_EVENTS_FROM', 'members'),
                'rate_limiting' => [
                    'enabled' => env('REVERB_APP_RATE_LIMITING_ENABLED', false),
                    'max_attempts' => env('REVERB_APP_RATE_LIMIT_MAX_ATTEMPTS', 60),
                    'decay_seconds' => env('REVERB_APP_RATE_LIMIT_DECAY_SECONDS', 60),
                    'terminate_on_limit' => env('REVERB_APP_RATE_LIMIT_TERMINATE', false),
                ],
            ],
        ],
    ],
];
