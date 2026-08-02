<?php

use App\Http\Controllers\Settings\ApiKeyController;
use App\Http\Controllers\Settings\ProfileController;
use App\Http\Controllers\Settings\SecurityController;
use App\Http\Controllers\Settings\WebhookEndpointController;
use App\Http\Controllers\Teams\TeamController;
use App\Http\Controllers\Teams\TeamInvitationController;
use App\Http\Controllers\Teams\TeamMemberController;
use App\Http\Middleware\EnsureTeamMembership;
use Illuminate\Auth\Middleware\RequirePassword;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth'])->group(function () {
    Route::redirect('settings', '/settings/profile');

    Route::get('settings/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('settings/profile', [ProfileController::class, 'update'])->name('profile.update');
});

Route::middleware(['auth', 'verified'])->group(function () {
    Route::delete('settings/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');

    Route::get('settings/security', [SecurityController::class, 'edit'])
        ->middleware(RequirePassword::class)
        ->name('security.edit');

    Route::put('settings/password', [SecurityController::class, 'update'])
        ->middleware('throttle:6,1')
        ->name('user-password.update');

    Route::inertia('settings/appearance', 'settings/appearance')->name('appearance.edit');

    Route::get('settings/teams', [TeamController::class, 'index'])->name('teams.index');
    Route::post('settings/teams', [TeamController::class, 'store'])->name('teams.store');

    Route::middleware(EnsureTeamMembership::class)->group(function () {
        Route::get('settings/teams/{team}', [TeamController::class, 'edit'])->name('teams.edit');
        Route::patch('settings/teams/{team}', [TeamController::class, 'update'])->name('teams.update');
        Route::delete('settings/teams/{team}', [TeamController::class, 'destroy'])->name('teams.destroy');
        Route::post('settings/teams/{team}/switch', [TeamController::class, 'switch'])->name('teams.switch');
        Route::delete('settings/teams/{team}/leave', [TeamController::class, 'leave'])->name('teams.leave');

        Route::patch('settings/teams/{team}/members/{user}', [TeamMemberController::class, 'update'])->name('teams.members.update');
        Route::delete('settings/teams/{team}/members/{user}', [TeamMemberController::class, 'destroy'])->name('teams.members.destroy');

        // scopeBindings resolves {apiKey} and {webhook} through the {team} that precedes them in the URI,
        // so one team asking for another's is a 404 from the router. Without it every action has to
        // remember an ownership check by hand, and forgetting is a cross-tenant read, not a visible bug.
        // Scoped to these routes rather than the whole group: {user} would resolve through Team::users(),
        // and the relation is members().
        Route::scopeBindings()->group(function () {
            // `can:` on the group rather than a Gate call in each method: every action here needs the
            // same ability, so stating it per-method means a method added later is authorized by
            // omission — and that failure is silent.
            Route::middleware('can:manageApiKeys,team')->group(function () {
                Route::get('settings/teams/{team}/api-keys', [ApiKeyController::class, 'index'])->name('api-keys.index');
                Route::post('settings/teams/{team}/api-keys', [ApiKeyController::class, 'store'])->name('api-keys.store');
                Route::delete('settings/teams/{team}/api-keys/{apiKey}', [ApiKeyController::class, 'destroy'])->name('api-keys.destroy');
            });

            Route::middleware('can:manageWebhooks,team')->group(function () {
                Route::get('settings/teams/{team}/webhooks', [WebhookEndpointController::class, 'index'])->name('webhooks.index');
                Route::post('settings/teams/{team}/webhooks', [WebhookEndpointController::class, 'store'])->name('webhooks.store');
                Route::patch('settings/teams/{team}/webhooks/{webhook}', [WebhookEndpointController::class, 'update'])->name('webhooks.update');
                Route::delete('settings/teams/{team}/webhooks/{webhook}', [WebhookEndpointController::class, 'destroy'])->name('webhooks.destroy');
            });
        });

        Route::post('settings/teams/{team}/invitations', [TeamInvitationController::class, 'store'])->name('teams.invitations.store');
        Route::delete('settings/teams/{team}/invitations/{invitation}', [TeamInvitationController::class, 'destroy'])->name('teams.invitations.destroy');
    });
});

Route::get('.well-known/passkey-endpoints', function () {
    return response()->json([
        'enroll' => route('security.edit'),
        'manage' => route('security.edit'),
    ]);
})->name('well-known.passkeys');
