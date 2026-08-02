<?php

use App\Http\Middleware\EnsureIdempotency;
use App\Http\Middleware\ResolveApiTeam;
use App\Models\ApiIdempotencyKey;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/**
 * The API exposes no writes yet, so the guarantee is exercised against a route registered here. It runs
 * the same middleware stack the group applies, and counts its own invocations — which is the only thing
 * that actually proves the work was not repeated.
 */
function countingWriteRoute(string $uri = '/api/v1/things', ?Closure $responder = null): object
{
    $calls = new class
    {
        public int $count = 0;
    };

    Route::middleware([ResolveApiTeam::class, EnsureIdempotency::class])
        ->post($uri, function () use ($calls, $responder) {
            $calls->count++;

            return $responder
                ? $responder($calls->count)
                : response()->json(['id' => "thing_{$calls->count}"], 201);
        });

    return $calls;
}

test('a request without a key is untouched', function () {
    $calls = countingWriteRoute();

    [$team] = teamWithOwner();
    [, $secret] = apiKeyFor($team);

    $this->withToken($secret)->postJson('/api/v1/things')->assertCreated();
    $this->withToken($secret)->postJson('/api/v1/things')->assertCreated();

    expect($calls->count)->toBe(2)
        ->and(ApiIdempotencyKey::count())->toBe(0);
});

test('a retry replays the first answer instead of acting again', function () {
    $calls = countingWriteRoute();

    [$team] = teamWithOwner();
    [, $secret] = apiKeyFor($team);

    $first = $this->withToken($secret)
        ->withHeader('Idempotency-Key', 'attempt-1')
        ->postJson('/api/v1/things')
        ->assertCreated();

    $second = $this->withToken($secret)
        ->withHeader('Idempotency-Key', 'attempt-1')
        ->postJson('/api/v1/things')
        ->assertCreated();

    expect($calls->count)->toBe(1)
        ->and($second->json())->toBe($first->json())
        ->and($second->headers->get('Idempotent-Replayed'))->toBe('true')
        ->and($first->headers->get('Idempotent-Replayed'))->toBeNull();
});

test('the replay reproduces the original status, not a generic success', function () {
    countingWriteRoute();

    [$team] = teamWithOwner();
    [, $secret] = apiKeyFor($team);

    $this->withToken($secret)->withHeader('Idempotency-Key', 'k')->postJson('/api/v1/things');

    $this->withToken($secret)
        ->withHeader('Idempotency-Key', 'k')
        ->postJson('/api/v1/things')
        ->assertCreated();
});

test('the same key against a different request is refused', function () {
    countingWriteRoute();

    [$team] = teamWithOwner();
    [, $secret] = apiKeyFor($team);

    $this->withToken($secret)
        ->withHeader('Idempotency-Key', 'reused')
        ->postJson('/api/v1/things', ['name' => 'first'])
        ->assertCreated();

    $this->withToken($secret)
        ->withHeader('Idempotency-Key', 'reused')
        ->postJson('/api/v1/things', ['name' => 'second'])
        ->assertStatus(422)
        ->assertJsonPath('status', 422);
});

test('a retry arriving while the original is still running answers 409', function () {
    countingWriteRoute();

    [$team] = teamWithOwner();
    [, $secret] = apiKeyFor($team);

    // What the middleware writes before calling the route: claimed, no outcome recorded yet. The
    // fingerprint is taken from an equivalent request rather than hand-built, so it stays correct if
    // the way a request is identified ever changes.
    ApiIdempotencyKey::create([
        'team_id' => $team->id,
        'key' => 'in-flight',
        'fingerprint' => ApiIdempotencyKey::fingerprint(
            // postJson sends an empty JSON body, not an empty string — the fingerprint sees the difference.
            Request::create('/api/v1/things', 'POST', [], [], [], ['CONTENT_TYPE' => 'application/json'], '[]')
        ),
        'expires_at' => now()->addHours(24),
    ]);

    $this->withToken($secret)
        ->withHeader('Idempotency-Key', 'in-flight')
        ->postJson('/api/v1/things')
        ->assertStatus(409)
        ->assertJsonPath('status', 409);
});

test('one team cannot see another team\'s key', function () {
    $calls = countingWriteRoute();

    [$team] = teamWithOwner();
    [$other] = teamWithOwner();

    [, $secret] = apiKeyFor($team);
    [, $otherSecret] = apiKeyFor($other);

    $this->withToken($secret)->withHeader('Idempotency-Key', 'shared')->postJson('/api/v1/things');
    $this->withToken($otherSecret)->withHeader('Idempotency-Key', 'shared')->postJson('/api/v1/things');

    // Same value, different customers: both requests must actually run.
    expect($calls->count)->toBe(2)
        ->and(ApiIdempotencyKey::count())->toBe(2);
});

test('rotating the key mid-retry still replays, because the record belongs to the team', function () {
    $calls = countingWriteRoute();

    [$team] = teamWithOwner();
    [$first, $firstSecret] = apiKeyFor($team);
    [, $secondSecret] = apiKeyFor($team);

    $this->withToken($firstSecret)
        ->withHeader('Idempotency-Key', 'survives-rotation')
        ->postJson('/api/v1/things')
        ->assertCreated();

    $first->revoke();

    $this->withToken($secondSecret)
        ->withHeader('Idempotency-Key', 'survives-rotation')
        ->postJson('/api/v1/things')
        ->assertCreated();

    expect($calls->count)->toBe(1);
});

test('a server failure is not pinned to the key', function () {
    $attempts = countingWriteRoute('/api/v1/flaky', fn (int $n) => response()->json(
        $n === 1 ? ['error' => 'transient'] : ['id' => 'thing_1'],
        $n === 1 ? 500 : 201,
    ));

    [$team] = teamWithOwner();
    [, $secret] = apiKeyFor($team);

    $this->withToken($secret)
        ->withHeader('Idempotency-Key', 'retry-me')
        ->postJson('/api/v1/flaky')
        ->assertStatus(500);

    // A recorded 500 would make every retry fail identically forever.
    expect(ApiIdempotencyKey::count())->toBe(0);

    $this->withToken($secret)
        ->withHeader('Idempotency-Key', 'retry-me')
        ->postJson('/api/v1/flaky')
        ->assertCreated();

    expect($attempts->count)->toBe(2);
});

test('a client error is replayed, because it is a settled answer', function () {
    $calls = countingWriteRoute('/api/v1/rejects', fn () => response()->json(['message' => 'no'], 422));

    [$team] = teamWithOwner();
    [, $secret] = apiKeyFor($team);

    $this->withToken($secret)->withHeader('Idempotency-Key', 'k')->postJson('/api/v1/rejects');
    $this->withToken($secret)->withHeader('Idempotency-Key', 'k')->postJson('/api/v1/rejects')->assertStatus(422);

    expect($calls->count)->toBe(1);
});

test('an expired key is free to use again', function () {
    $calls = countingWriteRoute();

    [$team] = teamWithOwner();
    [, $secret] = apiKeyFor($team);

    $this->withToken($secret)->withHeader('Idempotency-Key', 'stale')->postJson('/api/v1/things');

    $this->travel(25)->hours();

    $this->withToken($secret)
        ->withHeader('Idempotency-Key', 'stale')
        ->postJson('/api/v1/things')
        ->assertCreated();

    expect($calls->count)->toBe(2);
});

test('an over-long key is rejected', function () {
    countingWriteRoute();

    [$team] = teamWithOwner();
    [, $secret] = apiKeyFor($team);

    $this->withToken($secret)
        ->withHeader('Idempotency-Key', str_repeat('k', 256))
        ->postJson('/api/v1/things')
        ->assertBadRequest()
        ->assertJsonPath('status', 400);
});

test('a key sent on a method that cannot use it is refused rather than ignored', function () {
    [$team] = teamWithOwner();
    [, $secret] = apiKeyFor($team);

    // Answering 200 would tell the client the key did something it did not.
    $this->withToken($secret)
        ->withHeader('Idempotency-Key', 'on-a-read')
        ->getJson('/api/v1/team')
        ->assertBadRequest()
        ->assertJsonPath('status', 400);

    expect(ApiIdempotencyKey::count())->toBe(0);
});

test('reads without a key are untouched', function () {
    [$team] = teamWithOwner();
    [, $secret] = apiKeyFor($team);

    $this->withToken($secret)->getJson('/api/v1/team')->assertSuccessful();

    expect(ApiIdempotencyKey::count())->toBe(0);
});

test('a retried PUT replays rather than applying twice', function () {
    $calls = countingWriteRoute();

    Route::middleware([ResolveApiTeam::class, EnsureIdempotency::class])
        ->put('/api/v1/things/1', function () use ($calls) {
            $calls->count++;

            return response()->json(['id' => 'thing_1', 'applied' => $calls->count]);
        });

    [$team] = teamWithOwner();
    [, $secret] = apiKeyFor($team);

    $first = $this->withToken($secret)->withHeader('Idempotency-Key', 'put-1')
        ->putJson('/api/v1/things/1', ['name' => 'a'])->assertSuccessful();

    $second = $this->withToken($secret)->withHeader('Idempotency-Key', 'put-1')
        ->putJson('/api/v1/things/1', ['name' => 'a'])->assertSuccessful();

    expect($calls->count)->toBe(1)
        ->and($second->json())->toBe($first->json())
        ->and($second->headers->get('Idempotent-Replayed'))->toBe('true');
});

test('expired keys are pruned by the schedule', function () {
    // The sweep is hourly, so schedule:run only considers it at the top of the hour.
    $this->travelTo(now()->startOfHour());

    [$team] = teamWithOwner();

    $stale = ApiIdempotencyKey::create([
        'team_id' => $team->id, 'key' => 'old', 'fingerprint' => str_repeat('a', 64),
        'expires_at' => now()->subHour(),
    ]);

    $live = ApiIdempotencyKey::create([
        'team_id' => $team->id, 'key' => 'new', 'fingerprint' => str_repeat('b', 64),
        'expires_at' => now()->addHour(),
    ]);

    $this->artisan('schedule:run')->assertSuccessful();

    $this->assertDatabaseMissing('api_idempotency_keys', ['id' => $stale->id]);
    $this->assertDatabaseHas('api_idempotency_keys', ['id' => $live->id]);
});
