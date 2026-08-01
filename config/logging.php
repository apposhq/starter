<?php

use App\Logging\FlattenExceptionsOnHandler;
use Keepsuit\LaravelOpenTelemetry\Support\OpenTelemetryMonologHandler;
use Monolog\Handler\StreamHandler;
use Monolog\Processor\PsrLogMessageProcessor;

return [

    /*
    |--------------------------------------------------------------------------
    | Default Log Channel
    |--------------------------------------------------------------------------
    |
    | This option defines the default log channel that is utilized to write
    | messages to your logs. The value provided here should match one of
    | the channels present in the list of "channels" configured below.
    |
    */

    'default' => env('LOG_CHANNEL', 'stack'),

    /*
    |--------------------------------------------------------------------------
    | Deprecations Log Channel
    |--------------------------------------------------------------------------
    |
    | This option controls the log channel that should be used to log warnings
    | regarding deprecated PHP and library features. This allows you to get
    | your application ready for upcoming major versions of dependencies.
    |
    */

    'deprecations' => [
        'channel' => env('LOG_DEPRECATIONS_CHANNEL', 'null'),
        'trace' => env('LOG_DEPRECATIONS_TRACE', false),
    ],

    /*
    |--------------------------------------------------------------------------
    | Log Channels
    |--------------------------------------------------------------------------
    |
    | Here you may configure the log channels for your application. Laravel
    | utilizes the Monolog PHP logging library, which includes a variety
    | of powerful log handlers and formatters that you're free to use.
    |
    | Available drivers: "single", "daily", "slack", "syslog",
    |                    "errorlog", "monolog", "custom", "stack"
    |
    */

    /*
    |--------------------------------------------------------------------------
    | Log Channels
    |--------------------------------------------------------------------------
    |
    | Only the channels this application configures. Laravel merges its own logging.php underneath this
    | one — `channels` is one of LoadConfiguration's mergeable options — so single, daily, slack, syslog,
    | errorlog, papertrail, null and emergency all still resolve, and `deprecations` above still finds the
    | `null` channel. Deleting them here removes this file's copy, never the channel; what it removes is
    | eight blocks of configuration nothing in this application reads.
    |
    */

    'channels' => [

        // stderr and OTLP, the same in every environment: the process writes an event stream and whatever
        // supervises it decides where that goes — the terminal under `mise dev`, `docker logs` under
        // compose, the collector in a deployment. No file, so there is no rotation to own and no copy that
        // exists in only one environment.
        //
        // ignore_exceptions wraps the handlers in Monolog's WhatFailureGroupHandler. It matters because
        // `otlp` is on this stack: that handler reads the authenticated user, so a log call made while the
        // database is down re-enters the database and throws from inside the logger. Without it, that
        // secondary exception escapes Laravel's error handler, replaces the original in the response, and
        // the real failure is never recorded. With it, stderr still gets the line.
        'stack' => [
            'driver' => 'stack',
            'channels' => explode(',', (string) env('LOG_STACK', 'stderr,otlp')),
            'ignore_exceptions' => true,
        ],

        'stderr' => [
            'driver' => 'monolog',
            'level' => env('LOG_LEVEL', 'debug'),
            'handler' => StreamHandler::class,
            'handler_with' => [
                'stream' => 'php://stderr',
            ],
            'formatter' => env('LOG_STDERR_FORMATTER'),
            'processors' => [PsrLogMessageProcessor::class],
        ],

        // Ships records to the collector over OTLP, stamped with the active trace and span id so a log line
        // opens the trace it came from. The package injects this channel when it is absent, but it is
        // spelled out here so `otlp` in LOG_STACK resolves to something a reader can find. Exports are
        // batched, so this costs a buffer append per line rather than a request.
        'otlp' => [
            'driver' => 'monolog',
            'handler' => OpenTelemetryMonologHandler::class,
            'level' => env('LOG_LEVEL', 'debug'),
            // A tap rather than `processors`: the stack builds one Logger, so a channel-level processor
            // would rewrite records on their way to stderr too. See FlattenExceptionsOnHandler.
            'tap' => [FlattenExceptionsOnHandler::class],
        ],

    ],

];
