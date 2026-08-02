<?php

use App\Enums\ApiKeyMode;
use App\Enums\TeamRole;
use App\Jobs\DeliverWebhook;
use App\Models\ApiIdempotencyKey;
use App\Models\ApiKey;
use App\Models\User;
use App\Models\WebhookDelivery;
use App\Models\WebhookEndpoint;
use App\Rules\PublicHttpsUrl;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Request;
use Illuminate\Pagination\Cursor;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

test('a webhook url pointing inside the network is refused', function (string $url) {
    [$team] = teamWithOwner();
    [, $key] = apiKeyFor($team);

    $this->withToken($key)
        ->postJson('/api/v1/webhook-endpoints', ['url' => $url, 'events' => ['*']])
        ->assertStatus(422);

    expect($team->webhooks()->count())->toBe(0);
})->with([
    'metadata service' => 'https://169.254.169.254/latest/meta-data/',
    'loopback' => 'https://127.0.0.1/hooks',
    'private range' => 'https://10.0.0.5/hooks',
    'rfc1918' => 'https://192.168.1.10/hooks',
    'plain http' => 'http://example.com/hooks',
]);

test('the public-url rule judges addresses, not names', function () {
    expect(PublicHttpsUrl::isPublic('https://8.8.8.8/hooks'))->toBeTrue()
        ->and(PublicHttpsUrl::isPublic('https://169.254.169.254/meta'))->toBeFalse()
        ->and(PublicHttpsUrl::isPublic('https://[::1]/hooks'))->toBeFalse()
        ->and(PublicHttpsUrl::isPublic('http://8.8.8.8/hooks'))->toBeFalse();
});

test('a delivery does not follow redirects', function () {
    Http::fake(['*' => Http::response('', 302, ['Location' => 'http://169.254.169.254/latest/meta-data/'])]);

    [$team] = teamWithOwner();
    $delivery = deliveryFor(endpointFor($team, ['url' => 'https://8.8.8.8/hooks']));

    (new DeliverWebhook($delivery))->handle();

    Http::assertSent(fn ($request) => $request->url() === 'https://8.8.8.8/hooks');
    Http::assertSentCount(1);

    expect($delivery->fresh()->succeeded())->toBeFalse();
});

test('a delivery whose host stops resolving publicly is not sent', function () {
    Http::fake();

    [$team] = teamWithOwner();
    // Saved before the address became private, which validation cannot prevent.
    $endpoint = endpointFor($team);
    $endpoint->forceFill(['url' => 'https://127.0.0.1/hooks'])->save();

    $delivery = deliveryFor($endpoint);

    (new DeliverWebhook($delivery))->handle();

    Http::assertNothingSent();

    expect($delivery->fresh()->failed_at)->not->toBeNull();
});

test('a long connection error is recorded rather than overflowing the column', function () {
    Http::fake(fn () => throw new ConnectionException(str_repeat('x', 400)));

    [$team] = teamWithOwner();
    $delivery = deliveryFor(endpointFor($team, ['url' => 'https://8.8.8.8/hooks']));

    (new DeliverWebhook($delivery))->handle();

    $error = $delivery->fresh()->error;

    expect($error)->not->toBeNull()
        ->and(strlen($error))->toBeLessThanOrEqual(255);
});

test('a queue-level failure still marks the delivery failed', function () {
    [$team] = teamWithOwner();
    $delivery = deliveryFor(endpointFor($team));

    (new DeliverWebhook($delivery))->failed(new RuntimeException('worker timed out'));

    expect($delivery->fresh()->failed_at)->not->toBeNull()
        ->and($delivery->fresh()->error)->toContain('worker timed out');
});

test('a job whose delivery was cascade-deleted retires instead of failing', function () {
    expect((new DeliverWebhook(new WebhookDelivery))->deleteWhenMissingModels)->toBeTrue();
});

test('the signing secret is never stored for replay', function () {
    [$team] = teamWithOwner();
    [, $key] = apiKeyFor($team);

    $created = $this->withToken($key)
        ->withHeader('Idempotency-Key', 'create-endpoint')
        ->postJson('/api/v1/webhook-endpoints', ['url' => 'https://8.8.8.8/hooks', 'events' => ['*']])
        ->assertCreated();

    $secret = $created->json('data.secret');
    expect($secret)->toStartWith('whsec_');

    $stored = ApiIdempotencyKey::sole();
    expect(json_encode($stored->response_body))->not->toContain($secret)
        ->and($stored->response_body['data'])->not->toHaveKey('secret');

    // The replay is a replay, not a second reveal.
    $this->withToken($key)
        ->withHeader('Idempotency-Key', 'create-endpoint')
        ->postJson('/api/v1/webhook-endpoints', ['url' => 'https://8.8.8.8/hooks', 'events' => ['*']])
        ->assertCreated()
        ->assertJsonMissingPath('data.secret');
});

test('an abandoned claim is taken over rather than blocking the key forever', function () {
    [$team] = teamWithOwner();
    [, $key] = apiKeyFor($team);

    $payload = ['url' => 'https://8.8.8.8/hooks', 'events' => ['*']];

    // Run the request once so the claim carries the real fingerprint, then reopen it — which is exactly
    // the state a worker killed mid-request leaves behind.
    $this->withToken($key)
        ->withHeader('Idempotency-Key', 'abandoned')
        ->postJson('/api/v1/webhook-endpoints', $payload)
        ->assertCreated();

    ApiIdempotencyKey::sole()->forceFill(['completed_at' => null, 'response_status' => null])->save();

    $this->withToken($key)
        ->withHeader('Idempotency-Key', 'abandoned')
        ->postJson('/api/v1/webhook-endpoints', $payload)
        ->assertStatus(409);

    $this->travel(6)->minutes();

    $this->withToken($key)
        ->withHeader('Idempotency-Key', 'abandoned')
        ->postJson('/api/v1/webhook-endpoints', $payload)
        ->assertCreated();
});

test('re-revoking with a grace period cannot bring a dead key back', function () {
    [$team, $owner] = teamWithOwner();
    [$apiKey, $secret] = apiKeyFor($team, ['created_by' => $owner->id]);

    $this->actingAs($owner)
        ->delete(route('api-keys.destroy', ['team' => $team->slug, 'apiKey' => $apiKey->id]))
        ->assertRedirect();

    expect($apiKey->fresh()->isActive())->toBeFalse();

    $this->actingAs($owner)
        ->delete(route('api-keys.destroy', ['team' => $team->slug, 'apiKey' => $apiKey->id]), ['grace_hours' => 24])
        ->assertRedirect();

    expect($apiKey->fresh()->isActive())->toBeFalse();

    $this->withToken($secret)->getJson('/api/v1/team')->assertForbidden();
});

test('an out-of-range grace period is refused, leaving the key revocable', function () {
    [$team, $owner] = teamWithOwner();
    [$apiKey] = apiKeyFor($team, ['created_by' => $owner->id]);

    $this->actingAs($owner)
        ->delete(route('api-keys.destroy', ['team' => $team->slug, 'apiKey' => $apiKey->id]), ['grace_hours' => 99999999999])
        ->assertSessionHasErrors('grace_hours');

    expect($apiKey->fresh()->revoked_at)->toBeNull();
});

test('a non-numeric member id is not found rather than a server error', function (string $id) {
    [$team] = teamWithOwner();
    [, $key] = apiKeyFor($team);

    $this->withToken($key)->getJson("/api/v1/members/{$id}")->assertNotFound();
})->with(['me', 'abc', '01ARZ3NDEKTSV4RRFFQ69G5FAV']);

test('a cursor from another endpoint is a client error, not a server error', function () {
    [$team] = teamWithOwner();
    [, $key] = apiKeyFor($team);

    endpointFor($team, ['url' => 'https://8.8.8.8/a']);
    endpointFor($team, ['url' => 'https://8.8.8.8/b']);

    // Decodes cleanly, but carries none of the columns this endpoint orders by.
    $cursor = (new Cursor(['ordered_by_something_else' => 1], true))->encode();

    $this->withToken($key)
        ->getJson("/api/v1/webhook-endpoints?per_page=1&cursor={$cursor}")
        ->assertBadRequest()
        ->assertJsonPath('status', 400);
});

test('a non-numeric per_page falls back to the default', function (string $value) {
    [$team] = teamWithOwner();
    [, $key] = apiKeyFor($team);

    $this->withToken($key)
        ->getJson("/api/v1/members?per_page={$value}")
        ->assertSuccessful()
        ->assertJsonPath('meta.per_page', config('api.pagination.per_page'));
})->with(['abc', '', 'all']);

test('a long webhook url is accepted end to end', function () {
    [$team] = teamWithOwner();
    [, $key] = apiKeyFor($team);

    $url = 'https://8.8.8.8/hooks?'.Str::repeat('a', 300);

    $this->withToken($key)
        ->postJson('/api/v1/webhook-endpoints', ['url' => $url, 'events' => ['*']])
        ->assertCreated();

    expect($team->webhooks()->sole()->url)->toBe($url);
});

test('the api docs are reachable outside local', function () {
    config(['app.env' => 'production']);
    $this->app['env'] = 'production';

    $this->get('/docs/v1.json')->assertSuccessful();
});

test('a plaintext secret is never left in an unencrypted session store', function () {
    expect(config('session.encrypt'))->toBeTrue();
});

test('minting still produces a usable key after the constant removal', function () {
    [$secret] = ApiKey::mintSecret(ApiKeyMode::Live);

    expect(Str::after($secret, 'sk_live_'))->toHaveLength(49);
});

test('two different multipart bodies do not share a fingerprint', function () {
    $a = Request::create('/api/v1/things', 'POST', ['name' => 'first']);
    $b = Request::create('/api/v1/things', 'POST', ['name' => 'second']);

    expect(ApiIdempotencyKey::fingerprint($a))->not->toBe(ApiIdempotencyKey::fingerprint($b));
});

test('a webhook endpoint model still signs correctly after the changes', function () {
    $endpoint = new WebhookEndpoint(['secret' => 'whsec_'.base64_encode('k')]);

    expect($endpoint->sign('id', 1, 'body'))
        ->toBe('v1,'.base64_encode(hash_hmac('sha256', 'id.1.body', 'k', true)));
});

test('webhook deliveries past the retention window are swept', function () {
    $this->travelTo(now()->startOfDay());

    [$team] = teamWithOwner();
    $endpoint = endpointFor($team);

    $old = deliveryFor($endpoint);
    $old->forceFill(['created_at' => now()->subDays(config('api.webhook_retention_days') + 1)])->save();

    $recent = deliveryFor($endpoint);

    $this->artisan('schedule:run')->assertSuccessful();

    $this->assertDatabaseMissing('webhook_deliveries', ['id' => $old->id]);
    $this->assertDatabaseHas('webhook_deliveries', ['id' => $recent->id]);
});

test('the failed-delivery count is served by a partial index', function () {
    $definition = collect(DB::select(
        'select indexdef from pg_indexes where indexname = ?', ['webhook_deliveries_failed_idx'],
    ))->first()?->indexdef;

    // Partial, so the count stays proportional to failures rather than to everything ever delivered.
    expect($definition)->toContain('webhook_endpoint_id')
        ->and($definition)->toContain('failed_at IS NOT NULL');
});

test('authorization is enforced by the route, not by remembering to call a gate', function () {
    [$team] = teamWithOwner();
    $member = User::factory()->create();
    $team->members()->attach($member, ['role' => TeamRole::Member->value]);

    foreach (['api-keys.index', 'webhooks.index'] as $route) {
        $this->actingAs($member)->get(route($route, ['team' => $team->slug]))->assertForbidden();
    }

    expect(collect(Route::getRoutes()->getRoutes())
        ->filter(fn ($r) => str_contains((string) $r->getName(), 'api-keys.') || str_contains((string) $r->getName(), 'webhooks.'))
        ->every(fn ($r) => collect($r->gatherMiddleware())->contains(fn ($m) => str_starts_with((string) $m, 'can:'))))
        ->toBeTrue();
});
