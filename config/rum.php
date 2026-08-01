<?php

use Illuminate\Support\Str;

/**
 * Browser telemetry: JavaScript errors, session replay and Core Web Vitals, shipped to the same
 * OpenObserve that receives the backend traces. The SDK propagates a W3C traceparent header on
 * same-origin requests, so a browser error and the Laravel request behind it share a trace id.
 *
 * Read from config/app.php rather than re-deriving from env: a second env() call here means a second
 * default literal, and the two silently disagree the moment one is changed. config/ files are loaded in
 * sorted order, so `app` is already resolved by the time this file is required (config/fortify.php does
 * the same).
 */
$environment = (string) config('app.env');

/**
 * Everything below is derived from OO_OTLP_ENDPOINT, which already carries the whole address as
 * `<scheme>://<host>/api/<organization>` — self-hosted at localhost:5080 in development, and an
 * OpenObserve Cloud region (api.openobserve.ai, eu1.openobserve.ai, …) everywhere else. Deriving means
 * one value to configure and no way for the exporter, the browser and the source map upload to end up
 * pointed at three different places.
 */
$openObserve = (string) config('opentelemetry.openobserve.endpoint');
$openObserveUrl = parse_url($openObserve);

$openObserveBase = (string) preg_replace('#/api/[^/]+/?$#', '', $openObserve);

$openObserveOrganization = (string) (preg_match('#/api/([^/]+)/?$#', $openObserve, $m) ? $m[1] : 'default');

/**
 * Whether OO_OTLP_ENDPOINT names something only this machine or the compose network can resolve — decided
 * in config/opentelemetry.php so the exporters and the browser cannot disagree about it. The backend
 * reaching OpenObserve at `openobserve:5080` says nothing about where a browser can reach it, which is
 * why `site` below is derived separately.
 */
$openObserveIsLocal = (bool) config('opentelemetry.openobserve.is_local');

/**
 * The host the *browser* posts to. A container name is unreachable from any browser, and `localhost` is
 * the phone itself when the app is opened from another device on the network — so a local OpenObserve is
 * addressed through APP_URL's host, which is by definition the address that reached this application.
 * A cloud endpoint is reachable as-is.
 */
$browserUrl = parse_url((string) config('app.url'));

$openObserveBrowserHost = $openObserveIsLocal
    ? ($browserUrl['host'] ?? 'localhost').':'.($openObserveUrl['port'] ?? 5080)
    : ($openObserveUrl['host'] ?? 'localhost').(isset($openObserveUrl['port']) ? ':'.$openObserveUrl['port'] : '');

return [
    'base_url' => $openObserveBase,

    /**
     * The same credential the OTLP exporters use, resolved the same way — see config/opentelemetry.php,
     * which assumes the development password only when the endpoint is a local one.
     */
    'username' => config('opentelemetry.openobserve.username'),

    'password' => config('opentelemetry.openobserve.password'),

    /**
     * RUM stays off until OpenObserve issues a client token, so the token is the whole switch — a separate
     * `enabled` flag would be the same fact stored twice, and setting one without the other ships a token
     * with telemetry off, or telemetry on with no credential.
     *
     * Locally and in preview AppServiceProvider asks OpenObserve for one on the first render; a deployment
     * sets OO_RUM_CLIENT_TOKEN. Either way it is resolved at runtime and never baked into the bundle.
     */
    'client_token' => env('OO_RUM_CLIENT_TOKEN'),

    // Names this application in OpenObserve's RUM views, so it follows APP_NAME the way the OTel
    // service name and the session cookie already do.
    'application_id' => env('OO_RUM_APPLICATION_ID', Str::slug((string) env('APP_NAME', 'Starter'))),

    // Read out of the endpoint path, where OpenObserve already puts it.
    'organization' => env('OO_RUM_ORGANIZATION', $openObserveOrganization),

    'api_version' => env('OO_RUM_API_VERSION', 'v1'),

    /**
     * Host the *browser* posts to, without a scheme; the SDK appends /rum/{api_version}/{org}/{track}.
     * The same host the backend exports to, so browser and server telemetry land in one place and there
     * is no proxying to arrange: development reaches the local container directly, and every other
     * environment reaches OpenObserve Cloud directly.
     */
    'site' => env('OO_RUM_SITE', $openObserveBrowserHost),

    /**
     * Whether that host is plain HTTP, which the local container is and a cloud region is not.
     */
    'insecure_http' => $openObserveIsLocal || ($openObserveUrl['scheme'] ?? 'http') === 'http',

    /**
     * service, env and version form the triple a source map is uploaded under. All three have to match
     * the upload exactly or stack traces stay minified, so they are derived rather than typed twice:
     * service tracks the OTel service name, version tracks the asset build (see AppServiceProvider).
     */
    'service' => config('opentelemetry.service_name'),

    'env' => $environment,

    /**
     * Overrides the asset-manifest hash as the release identifier a source map is uploaded under.
     *
     * Needed whenever the build that produces the maps is not the build inside the image — the
     * Dockerfile runs its own `vp build`, so its manifest hashes differently from the one on the machine
     * that ran `php artisan rum:sourcemaps`. Set this to the same value in both places (a git SHA works) and the
     * browser reports the version the maps were uploaded under. Left unset, the hash is used.
     */
    'release' => env('OO_RUM_RELEASE'),

    /**
     * Percentage of sessions collected.
     */
    'session_sample_rate' => (int) env('OO_RUM_SESSION_SAMPLE_RATE', 100),

    /**
     * Percentage of collected sessions that also record a replay. Replay ships DOM mutations for the
     * whole session, so production samples it; every other environment records all of them.
     *
     * Sampling below 100 outside production is a trap rather than a saving: OpenObserve creates the
     * _sessionreplay stream lazily on the first segment it receives, and its RUM page throws
     * "Stream '_sessionreplay' not found" rather than showing an empty state until that happens. On a
     * fresh volume a sampled-out run of dev sessions leaves the stream missing and replay looking broken.
     */
    'session_replay_sample_rate' => (int) env(
        'OO_RUM_SESSION_REPLAY_SAMPLE_RATE',
        $environment === 'production' ? 20 : 100,
    ),

    /**
     * Replay masks the value of every input by default. Anything looser will record whatever a user
     * types into this app's auth and profile forms.
     */
    'default_privacy_level' => env('OO_RUM_PRIVACY_LEVEL', 'mask-user-input'),
];
