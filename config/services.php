<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as AWS, Slack, and more. This file provides the de facto location for
    | this type of information, allowing packages to have a conventional
    | file to locate the various service credentials.
    |
    | Mail goes out over SMTP configured by MAIL_URL, so this file declares no
    | mail-provider credentials. Note they still resolve at runtime: Laravel
    | merges its own services.php underneath this one, so config('services')
    | keeps postmark, resend and ses with the framework's env names. Deleting a
    | block here removes only this file's copy, never the key.
    |
    */

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

];
