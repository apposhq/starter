<?php

use App\Http\Controllers\Api\V1\MemberController;
use App\Http\Controllers\Api\V1\TeamController;
use App\Http\Controllers\Api\V1\WebhookEndpointController;
use App\Http\Middleware\EnsureIdempotency;
use App\Http\Middleware\ResolveApiTeam;
use App\Http\Middleware\ValidateApiCursor;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Platform API
|--------------------------------------------------------------------------
|
| The public API customers integrate against, authenticated by a team-owned
| API key rather than a session. Versioned in the path because the OpenAPI
| document is generated per prefix, so `v1` stays stable while `v2` is built
| beside it.
|
| Every route is implicitly scoped to the key's team — see ResolveApiTeam —
| so no endpoint takes a team id and no key can reach another customer.
|
*/

// throttle runs after the key is resolved, so the budget is metered per key rather than per address —
// see AppServiceProvider::configureApiRateLimits. Requests that fail authentication never reach it,
// which is fine: they were rejected before any work was done.
Route::prefix('v1')
    ->middleware(['auth:sanctum', ResolveApiTeam::class, 'throttle:api', ValidateApiCursor::class, EnsureIdempotency::class])
    ->group(function (): void {
        Route::get('team', [TeamController::class, 'show'])->name('api.v1.team.show');

        Route::get('members', [MemberController::class, 'index'])->name('api.v1.members.index');
        Route::get('members/{member}', [MemberController::class, 'show'])
            ->whereNumber('member')
            ->name('api.v1.members.show');

        // whereNumber, so an id that is not one answers 404 rather than reaching a bigint comparison
        // and surfacing Postgres' type error as our 500.
        Route::apiResource('webhook-endpoints', WebhookEndpointController::class)
            ->parameters(['webhook-endpoints' => 'endpoint'])
            ->names('api.v1.webhook-endpoints')
            ->where(['endpoint' => '[0-9]+']);
    });
