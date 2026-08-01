<?php

use Illuminate\Support\Str;
use Illuminate\Support\Uri;

$url = (string) env('DB_URL', sprintf(
    'postgresql://postgres:postgres@localhost:5432/%s?sslmode=prefer',
    Str::slug((string) config('app.name'), '_'),
));

/**
 * The suite runs against the `_test` companion the compose init script creates beside the main database,
 * because RefreshDatabase drops every table and must not do that to development data.
 *
 * Suffixed here rather than configured in phpunit.xml, so the name cannot drift from whatever DB_URL or
 * POSTGRES_DB actually names — the init script derives it the same way, from current_database().
 */
if (env('APP_ENV') === 'testing') {
    $url = (string) Uri::of($url)->withPath(Uri::of($url)->path().'_test');
}

return [

    /*
    |--------------------------------------------------------------------------
    | Default Database Connection Name
    |--------------------------------------------------------------------------
    |
    | Here you may specify which of the database connections below you wish
    | to use as your default connection for database operations. This is
    | the connection which will be utilized unless another connection
    | is explicitly specified when you execute a query / statement.
    |
    */

    'default' => env('DB_CONNECTION', 'pgsql'),

    /*
    |--------------------------------------------------------------------------
    | Database Connections
    |--------------------------------------------------------------------------
    |
    | One connection, configured from one URL. The cache, session and queue stores
    | leave their `connection` unset so they all resolve to the default below.
    |
    | A second database is deliberately not set up for. Laravel migrates and
    | refreshes one connection at a time — `migrate:fresh` drops only the default
    | connection, and RefreshDatabase issues a single `migrate:fresh` — so adding
    | one means owning that gap in every test run. See laravel/framework#55194.
    |
    */

    'connections' => [

        'pgsql' => [
            'driver' => 'pgsql',
            // One variable, not two: ConfigurationUrlParser merges a URL's query string into the
            // connection config, so `?sslmode=require` arrives as the `sslmode` key. A separate
            // DB_SSLMODE would be a second thing to remember when moving databases.
            'url' => $url,
            'charset' => 'utf8',
            'prefix' => '',
            'prefix_indexes' => true,
            'search_path' => 'public',
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Migration Repository Table
    |--------------------------------------------------------------------------
    |
    | This table keeps track of all the migrations that have already run for
    | your application. Using this information, we can determine which of
    | the migrations on disk haven't actually been run on the database.
    |
    */

    'migrations' => [
        'table' => 'migrations',
        'update_date_on_publish' => true,
    ],

];
