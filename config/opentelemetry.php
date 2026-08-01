<?php

use Illuminate\Support\Str;
use Illuminate\Support\Uri;
use Keepsuit\LaravelOpenTelemetry\Instrumentation;
use Keepsuit\LaravelOpenTelemetry\Support\ResourceAttributesParser;
use Keepsuit\LaravelOpenTelemetry\TailSampling;
use Keepsuit\LaravelOpenTelemetry\WorkerMode;
use OpenTelemetry\SDK\Common\Configuration\Variables;

/**
 * OpenObserve authenticates OTLP ingestion with HTTP Basic rather than a bearer token, so the header is
 * derived from the same OO_USER/OO_PASSWORD pair compose hands every service. Deriving it here keeps the
 * credential in one place instead of a second, hand-encoded OTEL_EXPORTER_OTLP_HEADERS copy that silently
 * rots when the password changes. Nothing here runs config:cache, so this resolves once per boot; if that
 * ever changes, the cache has to be built with the real OO_* values present or this default gets baked in.
 */
$openObserveEndpoint = (string) env('OO_OTLP_ENDPOINT', 'http://localhost:5080/api/default');

/**
 * The dev credential is only assumed for a local collector. Pointed at a real OpenObserve without
 * OO_USER/OO_PASSWORD present, this sends no Authorization header at all rather than the committed dev
 * password: an unauthenticated export is a visible 401 in the exporter's error output, whereas the
 * wrong password looks identical to "no traffic" and leaks the dev credential to that host.
 */
$openObserveIsLocal = in_array(Uri::of($openObserveEndpoint)->host(), ['localhost', '127.0.0.1', '::1', 'openobserve'], true);

$openObserveUser = env('OO_USER', $openObserveIsLocal ? 'root@example.com' : null);
$openObservePassword = env('OO_PASSWORD', $openObserveIsLocal ? 'Password1!' : null);

$openObserveHeaders = (string) env(Variables::OTEL_EXPORTER_OTLP_HEADERS, filled($openObserveUser) && filled($openObservePassword)
    ? 'Authorization=Basic '.base64_encode($openObserveUser.':'.$openObservePassword)
    : '');

$openObserveTimeout = (int) env(Variables::OTEL_EXPORTER_OTLP_TIMEOUT, 10000);

return [
    /**
     * The OpenObserve connection, resolved once here so config/rum.php can read it rather than repeating
     * the endpoint parsing and the credential fallback. Two derivations of "is this a local OpenObserve"
     * had already drifted apart, which meant one signal authenticating and the other not, from one value.
     */
    'openobserve' => [
        'endpoint' => $openObserveEndpoint,
        'is_local' => $openObserveIsLocal,
        'username' => $openObserveUser,
        'password' => $openObservePassword,
    ],

    /**
     * When set to true, Opentelemetry SDK will be disabled
     */
    'disabled' => filter_var(env(Variables::OTEL_SDK_DISABLED, false), FILTER_VALIDATE_BOOLEAN),

    /**
     * Service name
     */
    'service_name' => env(Variables::OTEL_SERVICE_NAME, Str::slug((string) config('app.name'))),

    /**
     * Service instance id
     * Should be unique for each instance of your service.
     * Defaults to the hostname, which under compose is the container id, so each replica of server,
     * reverb and worker reports as its own instance. Left unset the package generates a fresh random id
     * per request, which under Octane makes one long-lived worker look like thousands of instances.
     */
    'service_instance_id' => env('OTEL_SERVICE_INSTANCE_ID', gethostname() ?: null),

    /**
     * Additional resource attributes
     * Key-value pairs of resource attributes to add to all telemetry data.
     * By default, reads and parses OTEL_RESOURCE_ATTRIBUTES environment variable (which should be in the format 'key1=value1,key2=value2').
     */
    'resource_attributes' => [
        // Derived from APP_ENV so dev and production telemetry stay separable in OpenObserve without a
        // second env var to keep in sync. Anything in OTEL_RESOURCE_ATTRIBUTES is merged after, and wins.
        //
        // The default mirrors config/app.php, and must keep matching config/rum.php: browser and backend
        // telemetry that disagree on the environment cannot be filtered together, which is the whole
        // point of shipping them to one store.
        'deployment.environment.name' => config('app.env'),
        ...ResourceAttributesParser::parse((string) env(Variables::OTEL_RESOURCE_ATTRIBUTES, '')),
    ],

    /**
     * Include authenticated user context on traces and logs.
     */
    'user_context' => filter_var(env('OTEL_USER_CONTEXT', true), FILTER_VALIDATE_BOOLEAN),

    /**
     * Comma separated list of propagators to use.
     * Supports any otel propagator, for example: "tracecontext", "baggage", "b3", "b3multi", "none"
     */
    'propagators' => env(Variables::OTEL_PROPAGATORS, 'tracecontext'),

    /**
     * OpenTelemetry Meter configuration
     */
    'metrics' => [
        /**
         * Metrics exporter
         * This should be the key of one of the exporters defined in the exporters section
         * Supported drivers: "otlp", "console", "memory", "null"
         */
        'exporter' => env(Variables::OTEL_METRICS_EXPORTER, 'otlp'),
    ],

    /**
     * OpenTelemetry Traces configuration
     */
    'traces' => [
        /**
         * Traces exporter
         * This should be the key of one of the exporters defined in the exporters section
         * Supported drivers: "otlp", "zipkin", "console", "memory", "null"
         */
        'exporter' => env(Variables::OTEL_TRACES_EXPORTER, 'otlp'),

        /**
         * Traces sampler
         */
        'sampler' => [
            /**
             * Wraps the sampler in a parent based sampler
             */
            'parent' => filter_var(env('OTEL_TRACES_SAMPLER_PARENT', true), FILTER_VALIDATE_BOOLEAN),

            /**
             * Sampler type
             * Supported values: "always_on", "always_off", "traceidratio"
             */
            'type' => env('OTEL_TRACES_SAMPLER_TYPE', 'always_on'),

            'args' => [
                /**
                 * Sampling ratio for traceidratio sampler
                 */
                'ratio' => env('OTEL_TRACES_SAMPLER_TRACEIDRATIO_RATIO', 0.05),
            ],

            'tail_sampling' => [
                'enabled' => filter_var(env('OTEL_TRACES_TAIL_SAMPLING_ENABLED', false), FILTER_VALIDATE_BOOLEAN),
                // Maximum time to wait for the end of the trace before making a sampling decision (in milliseconds)
                'decision_wait' => (int) env('OTEL_TRACES_TAIL_SAMPLING_DECISION_WAIT', 5000),

                'rules' => [
                    TailSampling\Rules\ErrorsRule::class => filter_var(env('OTEL_TRACES_TAIL_SAMPLING_RULE_KEEP_ERRORS', true), FILTER_VALIDATE_BOOLEAN),
                    TailSampling\Rules\SlowTraceRule::class => [
                        'enabled' => filter_var(env('OTEL_TRACES_TAIL_SAMPLING_RULE_SLOW_TRACES', true), FILTER_VALIDATE_BOOLEAN),
                        'threshold_ms' => (int) env('OTEL_TRACES_TAIL_SAMPLING_SLOW_TRACES_THRESHOLD_MS', 2000),
                    ],
                ],
            ],
        ],

        /**
         * Traces span processors.
         * Processors classes must implement OpenTelemetry\SDK\Trace\SpanProcessorInterface
         *
         * Example: YourTracesSpanProcessor::class
         */
        'processors' => [],
    ],

    /**
     * OpenTelemetry logs configuration
     */
    'logs' => [
        /**
         * Logs exporter
         * This should be the key of one of the exporters defined in the exporters section
         * Supported drivers: "otlp", "console", "memory", "null"
         */
        'exporter' => env(Variables::OTEL_LOGS_EXPORTER, 'otlp'),

        /**
         * Inject active trace id in log context
         *
         * When using the OpenTelemetry logger, the trace id is always injected in the exported log record.
         * This option allows to inject the trace id in the log context for other loggers.
         */
        'inject_trace_id' => true,

        /**
         * Context field name for trace id
         */
        'trace_id_field' => 'trace_id',

        /**
         * Logs record processors.
         * Processors classes must implement OpenTelemetry\SDK\Logs\LogRecordProcessorInterface
         *
         * Example: YourLogRecordProcessor::class
         */
        'processors' => [],
    ],

    /**
     * OpenTelemetry exporters
     *
     * Here you can configure exports used by metrics, traces and logs.
     * If you want to use the same protocol with different endpoints,
     * you can copy the exporter with a different and change the endpoint
     *
     * Supported drivers: "otlp", "zipkin" (only traces), "console", "memory", "null"
     */
    'exporters' => [
        'otlp' => [
            'driver' => 'otlp',
            /**
             * OpenObserve ingests OTLP natively, so there is no collector in between. The org is part of
             * the path and the SDK appends /v1/{traces,metrics,logs} itself, so this stays a bare base URL
             * with no trailing slash. Compose points it at the container; the default is the local infra.
             */
            'endpoint' => env(Variables::OTEL_EXPORTER_OTLP_ENDPOINT, $openObserveEndpoint),
            /**
             * Supported protocols: "grpc", "http/protobuf", "http/json"
             */
            'protocol' => env(Variables::OTEL_EXPORTER_OTLP_PROTOCOL, 'http/protobuf'),
            'max_retries' => (int) env('OTEL_EXPORTER_OTLP_MAX_RETRIES', 3),

            /**
             * Per-signal reads are kept even though all three signals go to the same OpenObserve.
             * Collapsing them to one variable each looks like a simplification but is not: this config is
             * merged *over* the package's own, whose stock version reads all nine
             * OTEL_EXPORTER_OTLP_{TRACES,METRICS,LOGS}_{TIMEOUT,HEADERS,PROTOCOL} regardless. Dropping
             * them here does not remove them from the environment surface — it only makes nine
             * OTel-spec-standard variables get read and then silently discarded.
             */
            'traces_timeout' => (int) env(Variables::OTEL_EXPORTER_OTLP_TRACES_TIMEOUT, $openObserveTimeout),
            'traces_headers' => (string) env(Variables::OTEL_EXPORTER_OTLP_TRACES_HEADERS, $openObserveHeaders),
            'traces_protocol' => env(Variables::OTEL_EXPORTER_OTLP_TRACES_PROTOCOL),

            'metrics_timeout' => (int) env(Variables::OTEL_EXPORTER_OTLP_METRICS_TIMEOUT, $openObserveTimeout),
            'metrics_headers' => (string) env(Variables::OTEL_EXPORTER_OTLP_METRICS_HEADERS, $openObserveHeaders),
            'metrics_protocol' => env(Variables::OTEL_EXPORTER_OTLP_METRICS_PROTOCOL),

            'logs_timeout' => (int) env(Variables::OTEL_EXPORTER_OTLP_LOGS_TIMEOUT, $openObserveTimeout),
            'logs_headers' => (string) env(Variables::OTEL_EXPORTER_OTLP_LOGS_HEADERS, $openObserveHeaders),
            'logs_protocol' => env(Variables::OTEL_EXPORTER_OTLP_LOGS_PROTOCOL),

            /**
             * Preferred metrics temporality
             * Supported values: "Delta", "Cumulative"
             */
            'metrics_temporality' => env(Variables::OTEL_EXPORTER_OTLP_METRICS_TEMPORALITY_PREFERENCE),
        ],

        'zipkin' => [
            'driver' => 'zipkin',
            'endpoint' => env(Variables::OTEL_EXPORTER_ZIPKIN_ENDPOINT, 'http://localhost:9411'),
            'timeout' => env(Variables::OTEL_EXPORTER_ZIPKIN_TIMEOUT, 10000),
            'max_retries' => (int) env('OTEL_EXPORTER_ZIPKIN_MAX_RETRIES', 3),
        ],
    ],

    /**
     * List of instrumentation used for application tracing
     */
    'instrumentation' => [
        Instrumentation\HttpServerInstrumentation::class => [
            'enabled' => filter_var(env('OTEL_INSTRUMENTATION_HTTP_SERVER', true), FILTER_VALIDATE_BOOLEAN),
            'excluded_paths' => [],
            'excluded_methods' => [],
            'allowed_headers' => [],
            'sensitive_headers' => [],
            'sensitive_query_parameters' => [],
        ],

        Instrumentation\HttpClientInstrumentation::class => [
            'enabled' => filter_var(env('OTEL_INSTRUMENTATION_HTTP_CLIENT', true), FILTER_VALIDATE_BOOLEAN),
            'manual' => false, // When set to true, you need to call `withTrace()` on the request to enable tracing
            'allowed_headers' => [],
            'sensitive_headers' => [],
            'sensitive_query_parameters' => [],
        ],

        Instrumentation\QueryInstrumentation::class => filter_var(env('OTEL_INSTRUMENTATION_QUERY', true), FILTER_VALIDATE_BOOLEAN),

        Instrumentation\RedisInstrumentation::class => filter_var(env('OTEL_INSTRUMENTATION_REDIS', true), FILTER_VALIDATE_BOOLEAN),

        Instrumentation\QueueInstrumentation::class => filter_var(env('OTEL_INSTRUMENTATION_QUEUE', true), FILTER_VALIDATE_BOOLEAN),

        Instrumentation\CacheInstrumentation::class => filter_var(env('OTEL_INSTRUMENTATION_CACHE', true), FILTER_VALIDATE_BOOLEAN),

        Instrumentation\EventInstrumentation::class => [
            'enabled' => filter_var(env('OTEL_INSTRUMENTATION_EVENT', true), FILTER_VALIDATE_BOOLEAN),
            'excluded' => [],
        ],

        Instrumentation\ViewInstrumentation::class => filter_var(env('OTEL_INSTRUMENTATION_VIEW', true), FILTER_VALIDATE_BOOLEAN),

        Instrumentation\LivewireInstrumentation::class => filter_var(env('OTEL_INSTRUMENTATION_LIVEWIRE', true), FILTER_VALIDATE_BOOLEAN),

        Instrumentation\ConsoleInstrumentation::class => [
            'enabled' => filter_var(env('OTEL_INSTRUMENTATION_CONSOLE', true), FILTER_VALIDATE_BOOLEAN),
            'commands' => [],
        ],

        Instrumentation\ScoutInstrumentation::class => filter_var(env('OTEL_INSTRUMENTATION_SCOUT', true), FILTER_VALIDATE_BOOLEAN),
    ],

    /**
     * Worker mode detection configuration
     *
     * Detects worker modes (e.g., Octane, Horizon, Queue) and optimizes OpenTelemetry
     * behavior for long-running processes.
     */
    'worker_mode' => [
        /**
         * Flush after each iteration (e.g. http request, queue job).
         * If false, flushes are batched and executed periodically and on shutdown.
         *
         * Left false so the export stays off the request path. The catch, confirmed against a live
         * OpenObserve: the interval is only checked at an iteration boundary, so on an idle app the last
         * spans of a burst sit in the buffer until the next request arrives. Under steady traffic that is
         * invisible; if you are staring at a dashboard waiting for a one-off request to appear, that is
         * why. Set this true to trade throughput for immediacy.
         *
         * Only Octane and queue:work are detected as worker modes. `reverb:start` is neither, so telemetry
         * from the reverb container batches until that process exits.
         */
        'flush_after_each_iteration' => filter_var(env('OTEL_WORKER_MODE_FLUSH_AFTER_EACH_ITERATION', false), FILTER_VALIDATE_BOOLEAN),

        /**
         * Metrics collection interval in seconds.
         * When running in worker mode, metrics are collected and exported at this interval.
         * Note: This setting is ignored if 'flush_after_each_iteration' is true.
         * Note: The interval is checked after each iteration, so the actual interval may be longer
         */
        'metrics_collect_interval' => (int) env('OTEL_WORKER_MODE_COLLECT_INTERVAL', 60),

        /**
         * Detectors to use for worker mode detection
         *
         * Detectors are checked in order, the first one that returns true determines the mode.
         * Custom detectors implementing DetectorInterface can be added here.
         *
         * Built-in detectors:
         * - OctaneDetector: Detects Laravel Octane
         * - QueueDetector: Detects Laravel default queue worker and Laravel Horizon
         */
        'detectors' => [
            WorkerMode\Detectors\OctaneWorkerModeDetector::class,
            WorkerMode\Detectors\QueueWorkerModeDetector::class,
        ],
    ],
];
