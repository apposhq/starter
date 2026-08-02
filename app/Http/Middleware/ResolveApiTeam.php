<?php

namespace App\Http\Middleware;

use App\Models\ApiKey;
use App\Models\Team;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Keepsuit\LaravelOpenTelemetry\Facades\Tracer;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Put the API key's team on the request, and refuse a key that is no longer usable.
 *
 * An API key is owned by a team, so `auth:sanctum` resolves the *team* as the authenticated entity
 * rather than a user. That is deliberate — a key outlives the member who created it — but it means the
 * usual `$request->user()` is a Team, and nothing downstream should have to know that. Controllers read
 * `api_team` instead.
 *
 * Revocation is checked here rather than left to Sanctum, which only understands `expires_at`. A rotated
 * key stays valid for its grace period and then stops, without the row being deleted.
 */
class ResolveApiTeam
{
    public function handle(Request $request, Closure $next): Response
    {
        // Through the guard rather than $request->user(), whose PHPDoc promises a User — true for every
        // other route in this application, and not true here, where a key is owned by a team.
        $team = Auth::guard('sanctum')->user();

        $key = $team instanceof Team ? $team->currentAccessToken() : null;

        if (! $key instanceof ApiKey || ! $key->isActive()) {
            throw new AccessDeniedHttpException('This API key is no longer active.');
        }

        $request->attributes->set('api_team', $team);
        $request->attributes->set('api_key', $key);

        // Cheap enough to write on every request and the only way to answer "is this key still in use?"
        // when a customer asks whether they can retire it.
        $key->forceFill([
            'last_used_at' => now(),
            'last_used_ip' => $request->ip(),
        ])->saveQuietly();

        $response = $next($request);

        // The same id the error payload carries, on every response — so a customer can quote it from a
        // success that behaved oddly, not only from a failure, and it resolves to the full trace.
        if (filled($traceId = Tracer::traceId())) {
            $response->headers->set('X-Trace-Id', $traceId);
        }

        return $response;
    }
}
