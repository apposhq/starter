<?php

namespace App\Providers;

use App\Http\Middleware\HandleInertiaRequests;
use App\Models\ApiKey;
use Carbon\CarbonImmutable;
use Dedoc\Scramble\Scramble;
use Dedoc\Scramble\Support\Generator\OpenApi;
use Dedoc\Scramble\Support\Generator\SecurityScheme;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Foundation\DevCommands;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\View;
use Illuminate\Support\Facades\Vite;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Uri;
use Illuminate\Validation\Rules\Password;
use Illuminate\View\View as ViewInstance;
use Inertia\Inertia;
use Laravel\Sanctum\Sanctum;
use RuntimeException;
use Throwable;

class AppServiceProvider extends ServiceProvider
{
    /**
     * The key committed to this repository, so `mise dev` runs without generating one.
     *
     * Named here and referenced from config/app.php so the guard below compares against the same literal
     * the default sets, rather than a second copy that stops matching the moment either is edited.
     */
    public const DEVELOPMENT_APP_KEY = 'base64:g7J5n6MtDAj0ppG9TbzJ6eLWXcQgtPK0HufPZLo8Td0=';

    /**
     * The settled half of the RUM payload, resolved on the first render this worker serves.
     *
     * @var array<string, mixed>|null
     */
    protected ?array $browserTelemetry = null;

    protected bool $browserTelemetryResolved = false;

    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Scramble registers its routes during boot, so opting out of the default document has to happen
        // before that. Left on, it publishes every `api/*` route as a second, differently shaped document
        // beside the versioned one — the same endpoints described twice.
        Scramble::ignoreDefaultRoutes();
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();
        $this->configureApiKeys();
        $this->configureDevCommands();
        $this->configureMailers();
        $this->requireProductionServices();
        $this->configureServerSideRendering();
        $this->configureSessionReplayStyles();
        $this->configureBrowserTelemetry();
    }

    /**
     * Point Sanctum at the platform's API key model.
     *
     * Sanctum resolves tokens through whichever model is registered here, so the extra columns, the
     * revocation grace period and the team ownership all apply to real requests rather than only to keys
     * created through the UI.
     */
    protected function configureApiKeys(): void
    {
        Sanctum::usePersonalAccessTokenModel(ApiKey::class);

        // One document per API version, not one for the whole app. The path is versioned, so the contract
        // is too: `v2` becomes a second registration and a second file, and `v1` keeps describing exactly
        // what `v1` still serves rather than drifting as new endpoints land beside it.
        // middleware is spelled out because Scramble's default includes RestrictedDocsAccess, which
        // aborts 403 outside local unless a viewApiDocs gate exists. These docs are the customer-facing
        // reference — they have to be reachable in the environments customers use.
        Scramble::registerApi('v1', ['api_path' => 'api/v1', 'middleware' => ['web']])
            ->expose(ui: '/docs/v1', document: '/docs/v1.json')
            ->withDocumentTransformers(function (OpenApi $document): void {
                // Declared once here rather than annotated on every endpoint: Scramble reads routes, not
                // middleware intent, and without this the document tells a customer nothing about how to
                // authenticate — the first thing they need.
                $document->secure(SecurityScheme::http('bearer')->setDescription(
                    'A team API key, sent as `Authorization: Bearer sk_live_…`. Keys are issued per team and '
                    .'act only on that team.'
                ));
            });

        $this->configureApiRateLimits();
    }

    /**
     * Meter the platform API per key rather than per address.
     *
     * An IP is the wrong unit for a server-to-server API: a customer behind one NAT would share a budget
     * with strangers, and a customer on twenty workers would get twenty budgets. The key is the account,
     * so it is what gets metered — and a key that is being abused can be revoked, which an IP cannot.
     *
     * Only authenticated traffic reaches here. Laravel ranks Authenticate above ThrottleRequests in its
     * middleware priority, so a request without a usable key is answered 401 before any limiter runs —
     * metering unauthenticated traffic would need a separate limiter ahead of the guard.
     */
    protected function configureApiRateLimits(): void
    {
        RateLimiter::for('api', function (Request $request): Limit {
            $key = $request->attributes->get('api_key');

            return $key instanceof ApiKey
                ? Limit::perMinute(config("api.rate_limits.{$key->mode->value}"))->by("api-key:{$key->id}")
                : Limit::none();
        });
    }

    /**
     * Describe the development processes so `php artisan dev` supervises them as one tree.
     *
     * Registration from outside vendor/ outranks Illuminate's own, so re-using the `server` name replaces
     * `artisan serve` with Octane. `queue` keeps the framework's command, which is already the one this
     * application wants, and `logs` is dropped because the log stack writes to stderr — which this
     * command already multiplexes, so pail would print every line a second time.
     *
     * Reloading is Octane's own --watch rather than an external watcher invoking `octane:reload`. Under
     * `artisan dev` that external call segfaults FrankenPHP when it lands during an in-flight request,
     * and the runner's --kill-others-on-fail then takes the whole environment down with it. Octane's
     * watcher restarts workers from inside the server it already owns, and drops a process besides.
     */
    protected function configureDevCommands(): void
    {
        if (! $this->app->isLocal()) {
            return;
        }

        DevCommands::except('logs');

        // Port from APP_URL, so the server cannot listen somewhere the generated URLs do not point.
        DevCommands::artisan(sprintf(
            'octane:start --server=frankenphp --host=0.0.0.0 --port=%d --watch',
            parse_url((string) config('app.url'), PHP_URL_PORT) ?: 8000,
        ), 'server')->blue();

        // No --host or --port: config/reverb.php already resolves them to 0.0.0.0:8080.
        DevCommands::artisan('reverb:start', 'reverb')->purple();

        // Not node('dev'): package.json declares no scripts, so `bun run dev` would have nothing to run.
        DevCommands::register('vp dev', 'vite')->yellow();
    }

    /**
     * Refuse to boot production while mail or file storage still resolves to a development service.
     *
     * Two separate mistakes, because development runs the same drivers production does. The first is a
     * driver that only pretends to deliver — the log mailer accepts every password reset Fortify sends and
     * forwards none, the local disk writes uploads into a container filesystem the queue worker cannot
     * read and a redeploy discards. The second is the right driver aimed at the wrong host: compose and
     * mise both default to the mailpit and seaweedfs containers, so an environment that sets nothing
     * inherits addresses that do not resolve anywhere near the deployment.
     *
     * Neither surfaces as an error on its own until something is actually sent: mail is accepted and
     * dropped, and a write to a bucket that is not there fails on the first upload rather than at deploy
     * time. Crashing at boot converts both into a failed deploy, which is the cheaper failure.
     *
     * Runs after configureMailers() so it sees the restricted mailer list. Scoped by naming the
     * environments that are allowed to point at development services rather than by testing for
     * production: `staging` is a deployment too, and an is-production check waves it through.
     */
    protected function requireProductionServices(): void
    {
        if ($this->app->environment(['local', 'testing', 'preview'])) {
            return;
        }

        // The committed key signs session cookies, signed URLs and every verification link, so an
        // environment that inherits it hands anyone who has read this repository the ability to forge them.
        if (config('app.key') === self::DEVELOPMENT_APP_KEY) {
            throw new RuntimeException('APP_KEY is still the key committed to this repository, which anyone can use to forge sessions and signed URLs. Run `php artisan key:generate` and set it before deploying.');
        }

        $this->requireDeliverableMailer();
        $this->requireSharedDisk();
    }

    /**
     * Refuse a mailer that cannot deliver, whether it says so plainly or by falling back.
     */
    protected function requireDeliverableMailer(): void
    {
        $mailer = (string) config('mail.default');

        // configureMailers() restricts the resolved list, so a name outside it resolves to nothing and
        // would otherwise throw "Mailer [resend] is not defined" at the first password reset instead of here.
        if (! array_key_exists($mailer, config()->array('mail.mailers'))) {
            throw new RuntimeException(sprintf(
                'MAIL_MAILER is [%s], which this application does not configure. Use one of: %s.',
                $mailer,
                implode(', ', array_keys(config()->array('mail.mailers'))),
            ));
        }

        // A failover mailer is only as good as its members: the first one that connects wins, and this
        // application's failover ends in `log`, so a broken smtp member silently swallows everything.
        foreach ($this->transportsBehind($mailer) as $transport) {
            if (in_array($transport, ['log', 'array'], true)) {
                throw new RuntimeException("MAIL_MAILER resolves to [{$transport}], which accepts every message and delivers none. Set it to a real transport before deploying.");
            }

            $destination = config("mail.mailers.{$transport}.url") ?? config("mail.mailers.{$transport}.host");

            if ($this->isDevelopmentDestination((string) $destination)) {
                throw new RuntimeException("Mail for [{$transport}] points at [{$destination}], which is the local development service. Set MAIL_URL, or MAIL_HOST and MAIL_PORT, before deploying.");
            }
        }
    }

    /**
     * Refuse a disk that only one container can read, or one still addressing the development service.
     */
    protected function requireSharedDisk(): void
    {
        $disk = (string) config('filesystems.default');

        // The driver, not a list of the disk names that happen to use it today: any local-driver disk added
        // later writes somewhere only one container can read, and a name list would wave it through.
        if (config("filesystems.disks.{$disk}.driver") === 'local') {
            throw new RuntimeException("FILESYSTEM_DISK is [{$disk}], a local-driver disk that writes where only one container can read and a redeploy discards. Set it before deploying.");
        }

        $endpoint = (string) config("filesystems.disks.{$disk}.endpoint");

        if ($this->isDevelopmentDestination($endpoint)) {
            throw new RuntimeException("AWS_ENDPOINT is [{$endpoint}], which is the local development service. Point it at the deployed one, or leave it empty for AWS itself, before deploying.");
        }
    }

    /**
     * Flatten a mailer to the transports that can actually carry a message.
     *
     * `failover` and `roundrobin` name other mailers rather than delivering themselves, so the thing worth
     * checking is what they fall through to.
     *
     * @return array<int, string>
     */
    protected function transportsBehind(string $mailer): array
    {
        $transport = (string) config("mail.mailers.{$mailer}.transport");

        if (! in_array($transport, ['failover', 'roundrobin'], true)) {
            return [$mailer];
        }

        return collect(config()->array("mail.mailers.{$mailer}.mailers"))
            ->flatMap(fn (string $member): array => $this->transportsBehind($member))
            ->unique()
            ->values()
            ->all();
    }

    /**
     * Whether an address belongs to the local development stack rather than a deployed service.
     *
     * Falls back to the raw value when parse_url finds no host, because the discrete mail settings carry a
     * bare hostname where MAIL_URL carries a full URL, and `127.0.0.1` alone parses as a path.
     */
    protected function isDevelopmentDestination(string $destination): bool
    {
        if ($destination === '') {
            return false;
        }

        // Uri::host() rather than parse_url so URLs and the bare hostnames the discrete mail settings carry
        // are read the same way; both return null for a bare name, hence the raw fallback.
        $host = Uri::of($destination)->host() ?: $destination;

        // The property, not a roster of container names: a compose service name is a single DNS label, so
        // anything without a dot is reachable only inside that network, and loopback is reachable only from
        // this machine. Adding a service to compose.yml cannot leave this list behind.
        return ! str_contains($host, '.')
            || in_array($host, ['127.0.0.1', '::1'], true)
            || str_starts_with($host, '127.');
    }

    /**
     * Keep only the mailers this application actually delivers through.
     *
     * Deleting a mailer from config/mail.php does not remove it: LoadConfiguration merges the framework's
     * own mail.php underneath, and `mailers` is one of its deep-merged options, so ses, postmark, resend
     * and sendmail come straight back with credentials wired to environment variables nobody sets. That
     * leaves MAIL_MAILER=postmark resolving to a transport that fails at send time instead of failing
     * here. Restricting the resolved list is the only way the removal actually holds.
     */
    protected function configureMailers(): void
    {
        // Read back from the file that declares them rather than restated here: a fifth mailer added to
        // config/mail.php would otherwise be stripped at boot with no message, and then rejected by the
        // guard above as "not configured" while the reader is looking straight at it.
        $declared = require $this->app->configPath('mail.php');

        config()->set('mail.mailers', Arr::only(
            config()->array('mail.mailers'),
            array_keys($declared['mailers']),
        ));
    }

    /**
     * Hand the browser its RUM configuration through the root template.
     *
     * Shared rather than passed as an Inertia prop so the SDK can start before the app mounts and still
     * catch an error thrown on the way up. The keys are the SDK's own option names, so the TypeScript
     * side is a straight hand-off with nothing to translate.
     *
     * @see resources/js/rum.ts
     */
    protected function configureBrowserTelemetry(): void
    {
        $inertia = $this->app->make(HandleInertiaRequests::class);

        // A composer rather than View::share, because `version` has to be read per render: a worker boots
        // once and serves thousands of requests, so a frozen value keeps advertising the asset manifest
        // that existed at boot and attributes later errors to a release whose maps were uploaded under a
        // different hash. Everything else is settled by the time config is loaded, so it is built once.
        View::composer($inertia->rootView(request()), function (ViewInstance $view) use ($inertia): void {
            $view->with('rum', $this->browserTelemetry($inertia));
        });
    }

    /**
     * The RUM payload for one render: the settled half memoised, the asset version read fresh.
     *
     * Resolving the token here rather than in boot() is what keeps it off every other process. It is an
     * HTTP call, and boot() runs in each of them — `queue:listen` alone reboots the framework every few
     * seconds, and each `php artisan` pays it too, for a value only a rendered page can use. Measured: an
     * artisan command goes from 219ms to 1208ms while OpenObserve is unreachable.
     *
     * @return array<string, mixed>|null
     */
    protected function browserTelemetry(HandleInertiaRequests $inertia): ?array
    {
        if (! $this->browserTelemetryResolved) {
            $this->browserTelemetryResolved = true;

            $this->resolveDevelopmentRumToken();

            $this->browserTelemetry = filled(config('rum.client_token')) ? [
                'apiVersion' => config('rum.api_version'),
                'applicationId' => config('rum.application_id'),
                'clientToken' => config('rum.client_token'),
                'defaultPrivacyLevel' => config('rum.default_privacy_level'),
                'env' => config('rum.env'),
                'insecureHTTP' => config()->boolean('rum.insecure_http'),
                'organizationIdentifier' => config('rum.organization'),
                'service' => config('rum.service'),
                'sessionReplaySampleRate' => config()->integer('rum.session_replay_sample_rate'),
                'sessionSampleRate' => config()->integer('rum.session_sample_rate'),
                'site' => config('rum.site'),
            ] : null;
        }

        if ($this->browserTelemetry === null) {
            return null;
        }

        return [...$this->browserTelemetry, 'version' => self::assetVersion($inertia)];
    }

    protected function resolveDevelopmentRumToken(): void
    {
        if (! $this->app->environment(['local', 'preview']) || filled(config('rum.client_token'))) {
            return;
        }

        $token = $this->fetchRumToken();

        config()->set('rum.client_token', $token);
    }

    /**
     * Read the token from OpenObserve, treating an unreachable server as "no browser telemetry".
     *
     * Never fatal: OpenObserve being down must not stop the application from booting.
     */
    protected function fetchRumToken(): string
    {
        try {
            return (string) Http::withBasicAuth(
                (string) config('rum.username'),
                (string) config('rum.password'),
            )->timeout(3)->get(sprintf(
                '%s/api/%s/rumtoken',
                config('rum.base_url'),
                config('rum.organization'),
            ))->json('data.rum_token', '');
        } catch (Throwable $e) {
            // Not fatal — OpenObserve being down must not stop the app booting — but not silent either:
            // this pins browser telemetry off for the worker's entire life, and without a line here the
            // only symptom is RUM quietly never appearing.
            Log::warning('Could not resolve the RUM client token; browser telemetry is off for this worker.', [
                'endpoint' => config('rum.base_url'),
                'exception' => $e,
            ]);

            return '';
        }
    }

    /**
     * Let session replay read the stylesheet while Vite is serving it.
     *
     * Replay stores the page's CSS by reading document.styleSheets[].cssRules, which the browser refuses
     * for a cross-origin sheet unless it was fetched in CORS mode. In dev the stylesheet comes from the
     * Vite server on another port, so without this the recording keeps a bare <link> to an origin the
     * replay player cannot resolve, and every dev replay plays back as an unstyled or blank page. The
     * built assets are same-origin, so production already inlines the CSS and needs nothing here.
     *
     * Scoped to hot mode deliberately: crossorigin on a production asset served from a CDN without an
     * Access-Control-Allow-Origin header would block the stylesheet outright.
     *
     * The check has to happen inside the closure, which Laravel resolves per rendered tag. `mise dev`
     * starts Octane and Vite in parallel, so a provider that reads isRunningHot() once at boot usually
     * loses the race against the hot file being written and never emits the attribute at all.
     */
    protected function configureSessionReplayStyles(): void
    {
        Vite::useStyleTagAttributes(fn (): array => Vite::isRunningHot()
            ? ['crossorigin' => 'anonymous']
            : []);
    }

    /**
     * The identifier a source map upload has to be tagged with to deobfuscate this build's stack traces.
     *
     * Delegates to Inertia's asset version rather than recomputing the manifest hash. Writing that hash a
     * second time is what creates a second identifier: the copy omitted Inertia's `app.asset_url` branch,
     * so any deploy setting ASSET_URL already had two — and disagreement here means maps uploaded under a
     * version no browser reports, with stack traces silently staying minified.
     */
    public static function assetVersion(?HandleInertiaRequests $inertia = null): string
    {
        // An explicit release wins, because the manifest a deploy uploads maps against is not the one
        // inside the image: the Dockerfile runs its own `vp build`. Setting OO_RUM_RELEASE on both the
        // build and `php artisan rum:sourcemaps` is what makes the identities agree.
        //
        // Public and static so rum:sourcemaps uploads under the identity the browser reports. Computing it
        // twice is how the triple silently diverges and every production stack trace stays minified.
        if (filled($release = config('rum.release'))) {
            return (string) $release;
        }

        // The middleware is not a singleton, so resolving it per render is a fresh reflection-based build.
        return ($inertia ?? app(HandleInertiaRequests::class))->version(request()) ?? 'dev';
    }

    /**
     * Server-render the public landing page only.
     *
     * Inertia has no build-time prerendering, so `/` is rendered by the SSR server on request while
     * every authenticated route stays a client-rendered SPA. The closure is evaluated per request,
     * so it stays correct under Octane's long-lived workers.
     */
    protected function configureServerSideRendering(): void
    {
        Inertia::disableSsr(fn (): bool => ! request()->is('/'));
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(fn (): ?Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null,
        );
    }
}
