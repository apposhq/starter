<?php

use Illuminate\Support\Str;

/**
 * Connection settings shared by both object-storage disks. They address one service and differ only in
 * which bucket they open, so a second copy is a second place the endpoint can be changed — leaving one
 * disk talking to a server the other cannot see.
 *
 * `?: null` because compose turns an unset variable into an empty string, and the SDK reads "" as a
 * malformed endpoint rather than "use the default AWS one". Setting AWS_ENDPOINT empty is therefore how
 * you select AWS itself — which is also the only target that wants bucket-as-subdomain addressing, so
 * path style follows the presence of an endpoint rather than being configured beside it.
 */
$endpoint = env('AWS_ENDPOINT', 'http://localhost:8333') ?: null;

/**
 * Buckets are named `<app>-<environment>-<visibility>`, so renaming the application in config/app.php
 * renames them, and no two environments can ever share one. A preview built from a feature branch writes
 * to its own bucket rather than into production's, which is the failure this naming exists to prevent.
 */
$bucket = Str::slug((string) config('app.name')).'-'.config('app.env').'-';

$objectStorage = [
    'driver' => 's3',
    'key' => env('AWS_ACCESS_KEY_ID', 'starter'),
    'secret' => env('AWS_SECRET_ACCESS_KEY', 'starter'),
    'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    'endpoint' => $endpoint,
    'use_path_style_endpoint' => env('AWS_USE_PATH_STYLE_ENDPOINT', filled($endpoint)),
    // Laravel defaults this off, which turns a rejected upload into a `false` return that nothing logs.
    'throw' => true,
    'report' => false,
];

return [

    /*
    |--------------------------------------------------------------------------
    | Default Filesystem Disk
    |--------------------------------------------------------------------------
    |
    | Here you may specify the default filesystem disk that should be used
    | by the framework. The "local" disk, as well as a variety of cloud
    | based disks are available to your application for file storage.
    |
    */

    'default' => env('FILESYSTEM_DISK', 's3'),

    /*
    |--------------------------------------------------------------------------
    | Filesystem Disks
    |--------------------------------------------------------------------------
    |
    | Below you may configure as many filesystem disks as necessary, and you
    | may even configure multiple disks for the same driver. Examples for
    | most supported storage drivers are configured here for reference.
    |
    | Supported drivers: "local", "ftp", "sftp", "s3"
    |
    */

    'disks' => [

        'local' => [
            'driver' => 'local',
            'root' => storage_path('app/private'),
            'serve' => true,
            'throw' => false,
            'report' => false,
        ],

        'public' => [
            'driver' => 'local',
            'root' => storage_path('app/public'),
            'url' => rtrim((string) config('app.url'), '/').'/storage',
            'visibility' => 'public',
            'throw' => false,
            'report' => false,
        ],

        /*
        |----------------------------------------------------------------------
        | Object storage
        |----------------------------------------------------------------------
        |
        | Two buckets, because the two kinds of file want opposite things. `s3` holds user data and stays
        | private — reads go through temporaryUrl(), so a link expires and authorization stays with the
        | application. `s3_public` holds assets meant to be fetched by anyone, served straight off the
        | bucket or a CDN in front of it, with no signature to generate on every render.
        |
        | Defaults address the seaweedfs container `mise up` starts, so development needs nothing set.
        | Production overrides them and AppServiceProvider refuses to boot if it inherits these instead.
        |
        | `throw` is on. Laravel defaults it off, which turns a rejected upload into a `false` return that
        | nothing logs — the same silent-failure class the production guards exist to catch.
        |
        | Path style follows the endpoint rather than being set alongside it: a custom endpoint means an
        | S3-compatible service — seaweedfs here, R2, Spaces, B2 or Tigris deployed — and those address
        | buckets by path. AWS is the only target wanting bucket-as-subdomain and the only one needing no
        | endpoint, so it is one question, not two.
        |
        */

        's3' => [
            ...$objectStorage,
            'bucket' => env('AWS_PRIVATE_BUCKET', $bucket.'private'),
            'url' => env('AWS_URL') ?: null,
            'visibility' => 'private',
        ],

        's3_public' => [
            ...$objectStorage,
            'bucket' => env('AWS_PUBLIC_BUCKET', $bucket.'public'),
            // Set AWS_PUBLIC_URL to the CDN or custom domain in front of the bucket. Left unset, Storage
            // ::url() falls back to the endpoint, which is correct locally and rarely what you want live.
            'url' => env('AWS_PUBLIC_URL') ?: null,
            'visibility' => 'public',
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Symbolic Links
    |--------------------------------------------------------------------------
    |
    | Here you may configure the symbolic links that will be created when the
    | `storage:link` Artisan command is executed. The array keys should be
    | the locations of the links and the values should be their targets.
    |
    */

    'links' => [
        public_path('storage') => storage_path('app/public'),
    ],

];
