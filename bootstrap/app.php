<?php

use App\Http\Middleware\HandleAppearance;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\ResolveApiTeam;
use App\Http\Middleware\SetTeamUrlDefaults;
use App\Http\Responses\ProblemDetails;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\ThrottleRequests;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        channels: __DIR__.'/../routes/channels.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // dev:tunnel runs cloudflared on this host, terminating TLS and forwarding to the plain HTTP
        // server. Without trusting its X-Forwarded-Proto, url() emits http:// links on an https page.
        // Scoped to loopback, so only a proxy already on this machine can set the header.
        $middleware->trustProxies(at: ['127.0.0.1', '::1']);

        $middleware->encryptCookies(except: ['appearance', 'sidebar_state']);

        $middleware->web(append: [
            HandleAppearance::class,
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
            SetTeamUrlDefaults::class,
        ]);

        // ThrottleRequests sits in the framework's priority list, so it is hoisted ahead of any custom
        // middleware no matter how the route group lists them. The API's limits are per key, and the key
        // is what ResolveApiTeam resolves — left alone, every request would be metered as anonymous.
        $middleware->prependToPriorityList(ThrottleRequests::class, ResolveApiTeam::class);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        // The public API answers in RFC 9457 problem details rather than Laravel's default envelope,
        // because customers write code against these errors. Scoped to api/* so the Inertia application
        // keeps the error pages it already renders.
        $exceptions->render(fn (Throwable $e, Request $request) => $request->is('api/*')
            ? app(ProblemDetails::class)($e, $request)
            : null);
    })->create();
