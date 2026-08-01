<?php

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

    'default' => env('DB_CONNECTION', 'primary'),

    /*
    |--------------------------------------------------------------------------
    | Database Connections
    |--------------------------------------------------------------------------
    |
    | Every connection is configured from a single `DB_URL_<NAME>` variable so a
    | connection can be added by declaring one variable and one block here. The
    | cache, session, and queue stores leave their `connection` unset, so they
    | all resolve to the default connection named below.
    |
    */

    'connections' => [

        'primary' => [
            'driver' => 'pgsql',
            // One variable, not two: ConfigurationUrlParser merges a URL's query string into the
            // connection config, so `?sslmode=require` arrives as the `sslmode` key. A separate
            // DB_SSLMODE_PRIMARY would be a second thing to remember to change when moving databases.
            'url' => env('DB_URL_PRIMARY', 'postgresql://postgres:postgres@localhost:5432/primary?sslmode=prefer'),
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
