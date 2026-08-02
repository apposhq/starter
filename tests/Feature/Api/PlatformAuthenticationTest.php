<?php

use App\Http\Middleware\ResolveApiTeam;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;

test('a request without a key is rejected', function () {
    $this->getJson('/api/v1/team')->assertUnauthorized();
});

test('a request with an unknown key is rejected', function () {
    $this->withToken('sk_live_'.str_repeat('a', 49))
        ->getJson('/api/v1/team')
        ->assertUnauthorized();
});

test('a browser session cannot authenticate the API', function () {
    [, $owner] = teamWithOwner();

    // Sanctum checks its configured guards before the bearer token, so leaving 'web' in sanctum.guard
    // would resolve a logged-in User here instead of the key's Team.
    $this->actingAs($owner)->getJson('/api/v1/team')->assertUnauthorized();
});

test('a session does not shadow a valid key', function () {
    [$team, $owner] = teamWithOwner();
    [, $secret] = apiKeyFor($team);

    $this->actingAs($owner)
        ->withToken($secret)
        ->getJson('/api/v1/team')
        ->assertSuccessful()
        ->assertJsonPath('data.id', (string) $team->id);
});

test('a valid key authenticates as its team', function () {
    [$team] = teamWithOwner();
    [, $secret] = apiKeyFor($team);

    $this->withToken($secret)
        ->getJson('/api/v1/team')
        ->assertSuccessful()
        ->assertJsonPath('data.id', (string) $team->id);
});

test('a revoked key is rejected', function () {
    [$team] = teamWithOwner();
    [$key, $secret] = apiKeyFor($team);

    $key->revoke();

    $this->withToken($secret)->getJson('/api/v1/team')->assertForbidden();
});

test('a key revoked with a grace period keeps working until the grace expires', function () {
    [$team] = teamWithOwner();
    [$key, $secret] = apiKeyFor($team);

    $key->revoke(now()->addHours(24));

    $this->withToken($secret)->getJson('/api/v1/team')->assertSuccessful();

    $this->travel(25)->hours();

    $this->withToken($secret)->getJson('/api/v1/team')->assertForbidden();
});

test('an expired key is rejected', function () {
    [$team] = teamWithOwner();
    [, $secret] = apiKeyFor($team, ['expires_at' => now()->subDay()]);

    $this->withToken($secret)->getJson('/api/v1/team')->assertUnauthorized();
});

test('use is recorded, so a team can tell whether a key is still needed', function () {
    [$team] = teamWithOwner();
    [$key, $secret] = apiKeyFor($team);

    expect($key->last_used_at)->toBeNull();

    $this->withToken($secret)->getJson('/api/v1/team')->assertSuccessful();

    expect($key->fresh()->last_used_at)->not->toBeNull()
        ->and($key->fresh()->last_used_ip)->toBe('127.0.0.1');
});

test('a key still being sent after revocation is visible in its last use', function () {
    [$team] = teamWithOwner();
    [$key, $secret] = apiKeyFor($team);

    $key->revoke();

    $this->withToken($secret)->getJson('/api/v1/team')->assertForbidden();

    expect($key->fresh()->revoked_at)->not->toBeNull();
});

test('errors answer in RFC 9457 problem details', function () {
    $response = $this->getJson('/api/v1/team')->assertUnauthorized();

    expect($response->headers->get('Content-Type'))->toContain('application/problem+json');

    $response->assertJsonStructure(['type', 'title', 'status', 'detail', 'instance'])
        ->assertJsonPath('status', 401)
        ->assertJsonPath('title', 'Unauthenticated')
        ->assertJsonPath('instance', '/api/v1/team');
});

test('a problem document points at a documented type', function () {
    $this->getJson('/api/v1/team')
        ->assertUnauthorized()
        ->assertJsonPath('type', url('/docs/problems/401'));
});

test('an internal failure does not leak its message when debug is off', function () {
    config(['app.debug' => false]);

    // The throw below is the point of the test; without this its stack trace lands in the suite output.
    Log::spy();

    Route::middleware(['auth:sanctum', ResolveApiTeam::class])
        ->get('/api/v1/boom', fn () => throw new RuntimeException('SELECT * FROM secrets'));

    [$team] = teamWithOwner();
    [, $secret] = apiKeyFor($team);

    $response = $this->withToken($secret)->getJson('/api/v1/boom');

    expect($response->status())->toBe(500)
        ->and($response->json('detail'))->not->toContain('secrets');
});
