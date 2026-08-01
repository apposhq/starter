<?php

use App\Providers\AppServiceProvider;

return [

    /*
    |--------------------------------------------------------------------------
    | Application Name
    |--------------------------------------------------------------------------
    |
    | This value is the name of your application, which will be used when the
    | framework needs to place the application's name in a notification or
    | other UI elements where an application name needs to be displayed.
    |
    */

    // The one place this default lives. Every other identity derived from it — the OTel service name,
    // the Reverb app id, the RUM application id, the session cookie, the cache prefix and the page title
    // the client renders after hydration — reads config('app.name') rather than re-reading the variable.
    'name' => env('APP_NAME', 'Starter'),

    /*
    |--------------------------------------------------------------------------
    | Application Environment
    |--------------------------------------------------------------------------
    |
    | This value determines the "environment" your application is currently
    | running in. This may determine how you prefer to configure various
    | services the application utilizes. Set this in your ".env" file.
    |
    */

    'env' => env('APP_ENV', 'local'),

    /*
    |--------------------------------------------------------------------------
    | Application Debug Mode
    |--------------------------------------------------------------------------
    |
    | When your application is in debug mode, detailed error messages with
    | stack traces will be shown on every error that occurs within your
    | application. If disabled, a simple generic error page is shown.
    |
    */

    // Derived from APP_ENV so no cloud environment has to remember to set it. Laravel's own default here
    // is true, which means an image run outside compose — where APP_ENV is production but APP_DEBUG is
    // supplied by nothing — boots in production rendering stack traces and configuration to visitors.
    // Setting APP_DEBUG explicitly still wins, for the rare case of debugging a deployed environment.
    'debug' => (bool) env('APP_DEBUG', env('APP_ENV', 'local') === 'local'),

    /*
    |--------------------------------------------------------------------------
    | Application URL
    |--------------------------------------------------------------------------
    |
    | This URL is used by the console to properly generate URLs when using
    | the Artisan command line tool. You should set this to the root of
    | the application so that it's available within Artisan commands.
    |
    */

    'url' => env('APP_URL', 'http://localhost:8000'),

    /*
    |--------------------------------------------------------------------------
    | Application Timezone
    |--------------------------------------------------------------------------
    |
    | Here you may specify the default timezone for your application, which
    | will be used by the PHP date and date-time functions. The timezone
    | is set to "UTC" by default as it is suitable for most use cases.
    |
    */

    'timezone' => 'UTC',

    /*
    |--------------------------------------------------------------------------
    | Application Locale Configuration
    |--------------------------------------------------------------------------
    |
    | The application locale determines the default locale that will be used
    | by Laravel's translation / localization methods. This option can be
    | set to any locale for which you plan to have translation strings.
    |
    */

    'locale' => env('APP_LOCALE', 'en'),

    'fallback_locale' => env('APP_FALLBACK_LOCALE', 'en'),

    'faker_locale' => env('APP_FAKER_LOCALE', 'en_US'),

    /*
    |--------------------------------------------------------------------------
    | Encryption Key
    |--------------------------------------------------------------------------
    |
    | The default below is a throwaway so a fresh clone boots with no setup. It is committed, so
    | treat it as public: anything it encrypts is readable by anyone with the repository. Every
    | deployment must pass a real APP_KEY. compose.yml only defaults to this throwaway, so nothing
    | stops a deployment booting with it.
    |
    | Generate one with: php -r 'echo "base64:".base64_encode(random_bytes(32)), PHP_EOL;'
    |
    */

    'cipher' => 'AES-256-CBC',

    'key' => env('APP_KEY', AppServiceProvider::DEVELOPMENT_APP_KEY),

    'previous_keys' => [
        ...array_filter(
            explode(',', (string) env('APP_PREVIOUS_KEYS', '')),
        ),
    ],

    /*
    |--------------------------------------------------------------------------
    | Maintenance Mode Driver
    |--------------------------------------------------------------------------
    |
    | These configuration options determine the driver used to determine and
    | manage Laravel's "maintenance mode" status. The "cache" driver will
    | allow maintenance mode to be controlled across multiple machines.
    |
    | Supported drivers: "file", "cache", "array"
    |
    */

    'maintenance' => [
        'driver' => env('APP_MAINTENANCE_DRIVER', 'cache'),
        'store' => env('APP_MAINTENANCE_STORE', 'database'),
    ],

];
