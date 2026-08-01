<?php

use Illuminate\Support\Uri;

return [

    /*
    |--------------------------------------------------------------------------
    | Default Mailer
    |--------------------------------------------------------------------------
    |
    | This option controls the default mailer that is used to send all email
    | messages unless another mailer is explicitly specified when sending
    | the message. All additional mailers can be configured within the
    | "mailers" array. Examples of each type of mailer are provided.
    |
    */

    'default' => env('MAIL_MAILER', 'smtp'),

    /*
    |--------------------------------------------------------------------------
    | Mailer Configurations
    |--------------------------------------------------------------------------
    |
    | Here you may configure all of the mailers used by your application plus
    | their respective settings. Several examples have been configured for
    | you and you are free to add your own as your application requires.
    |
    | Laravel supports a variety of mail "transport" drivers that can be used
    | when delivering an email. You may specify which one you're using for
    | your mailers below. You may also add additional mailers if needed.
    |
    | Laravel supports smtp, sendmail, mailgun, ses, ses-v2, postmark, resend,
    | log, array, failover and roundrobin. Only the ones this application
    | actually delivers through are defined below; the API transports were
    | removed rather than left configured-but-unused.
    |
    | Delivery is SMTP configured by a single MAIL_URL, e.g.
    | smtp://user:pass@host:587. Use the "smtp" scheme even for implicit TLS:
    | the URL scheme becomes the transport name and there is no "smtps"
    | transport, so smtps:// throws. Port 465 selects implicit TLS on its own,
    | or set MAIL_SCHEME=smtps explicitly.
    |
    */

    'mailers' => [

        'smtp' => [
            'transport' => 'smtp',
            'scheme' => env('MAIL_SCHEME'),
            // The local default lives on the discrete keys, not on `url`. MailManager merges a URL over
            // host/port/username/password whenever one is set, so defaulting `url` would permanently
            // disable the discrete path — a deployment configured the documented Laravel way, with
            // MAIL_HOST and MAIL_USERNAME and no MAIL_URL, would silently keep talking to mailpit.
            //
            // Coalesced to null because compose turns an unset variable into an empty string, and
            // MailManager gates on isset() — which an empty string passes, sending it down the
            // URL-parsing path to fail with "Unsupported mail transport []".
            'url' => env('MAIL_URL') ?: null,
            'host' => env('MAIL_HOST', 'localhost'),
            'port' => env('MAIL_PORT', 1025),
            'username' => env('MAIL_USERNAME'),
            'password' => env('MAIL_PASSWORD'),
            'timeout' => null,
            'local_domain' => env('MAIL_EHLO_DOMAIN', Uri::of((string) config('app.url'))->host()),
        ],

        'log' => [
            'transport' => 'log',
            'channel' => env('MAIL_LOG_CHANNEL'),
        ],

        'array' => [
            'transport' => 'array',
        ],

        'failover' => [
            'transport' => 'failover',
            'mailers' => [
                'smtp',
                'log',
            ],
            'retry_after' => 60,
        ],

        // No roundrobin mailer: it existed to spread load across two API transports that are now gone,
        // and rotating between one mailer is not a strategy. `failover` above is the one that still
        // earns its place, dropping to the log channel when SMTP is unreachable.

    ],

    /*
    |--------------------------------------------------------------------------
    | Global "From" Address
    |--------------------------------------------------------------------------
    |
    | You may wish for all emails sent by your application to be sent from
    | the same address. Here you may specify a name and address that is
    | used globally for all emails that are sent by your application.
    |
    */

    'from' => [
        'address' => env('MAIL_FROM_ADDRESS', 'hello@example.com'),
        'name' => env('MAIL_FROM_NAME', config('app.name')),
    ],

];
