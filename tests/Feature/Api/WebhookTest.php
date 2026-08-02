<?php

use App\Jobs\DeliverWebhook;
use App\Models\WebhookEndpoint;
use App\Support\Webhooks;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

test('a secret is minted in the shape verifying libraries expect', function () {
    $secret = WebhookEndpoint::mintSecret();

    // strlen, not toHaveLength: the decoded secret is 32 random bytes, and read as UTF-8 some of those
    // byte sequences count as a single character — making a correct secret occasionally measure short.
    expect($secret)->toStartWith('whsec_')
        ->and(strlen((string) base64_decode(Str::after($secret, 'whsec_'), true)))->toBe(32);
});

test('a signature matches what the Standard Webhooks scheme produces', function () {
    $endpoint = new WebhookEndpoint(['secret' => 'whsec_'.base64_encode('a-known-secret')]);

    $signature = $endpoint->sign('msg_1', 1700000000, '{"hello":"world"}');

    $expected = 'v1,'.base64_encode(hash_hmac(
        'sha256', 'msg_1.1700000000.{"hello":"world"}', 'a-known-secret', true,
    ));

    expect($signature)->toBe($expected);
});

test('changing any signed part changes the signature', function () {
    $endpoint = new WebhookEndpoint(['secret' => WebhookEndpoint::mintSecret()]);

    $base = $endpoint->sign('msg_1', 1700000000, '{"a":1}');

    expect($endpoint->sign('msg_2', 1700000000, '{"a":1}'))->not->toBe($base)
        ->and($endpoint->sign('msg_1', 1700000001, '{"a":1}'))->not->toBe($base)
        ->and($endpoint->sign('msg_1', 1700000000, '{"a":2}'))->not->toBe($base);
});

test('an event reaches only the endpoints that subscribed', function () {
    Queue::fake();

    [$team] = teamWithOwner();

    $subscribed = endpointFor($team, ['events' => ['member.added']]);
    $wildcard = endpointFor($team, ['events' => [WebhookEndpoint::ALL_EVENTS]]);
    endpointFor($team, ['events' => ['team.updated']]);

    $deliveries = Webhooks::send($team, 'member.added', ['id' => '1']);

    expect($deliveries)->toHaveCount(2)
        ->and($deliveries->pluck('webhook_endpoint_id')->sort()->values()->all())
        ->toBe(collect([$subscribed->id, $wildcard->id])->sort()->values()->all());

    Queue::assertPushed(DeliverWebhook::class, 2);
});

test('a disabled endpoint receives nothing', function () {
    Queue::fake();

    [$team] = teamWithOwner();
    endpointFor($team, ['disabled_at' => now()]);

    expect(Webhooks::send($team, 'member.added', []))->toBeEmpty();

    Queue::assertNothingPushed();
});

test('one team\'s event never reaches another team\'s endpoint', function () {
    Queue::fake();

    [$team] = teamWithOwner();
    [$other] = teamWithOwner();

    endpointFor($other);

    expect(Webhooks::send($team, 'member.added', []))->toBeEmpty();
});

test('every endpoint sees the same event id, so a receiver can deduplicate', function () {
    Queue::fake();

    [$team] = teamWithOwner();
    endpointFor($team);
    endpointFor($team);

    $deliveries = Webhooks::send($team, 'member.added', []);

    expect($deliveries->pluck('event_id')->unique())->toHaveCount(1);
});

test('the payload wraps the data in an envelope', function () {
    Queue::fake();

    [$team] = teamWithOwner();
    endpointFor($team);

    $delivery = Webhooks::send($team, 'member.added', ['id' => '7'])->sole();

    expect($delivery->payload)->toHaveKeys(['id', 'type', 'created_at', 'data'])
        ->and($delivery->payload['type'])->toBe('member.added')
        ->and($delivery->payload['data'])->toBe(['id' => '7'])
        ->and($delivery->payload['id'])->toBe($delivery->event_id);
});

test('a delivery is sent with the three standard headers, signed', function () {
    Http::fake(['*' => Http::response('', 200)]);

    [$team] = teamWithOwner();
    $endpoint = endpointFor($team, ['url' => 'https://8.8.8.8/hooks']);

    $delivery = deliveryFor($endpoint);

    (new DeliverWebhook($delivery))->handle();

    Http::assertSent(function ($request) use ($endpoint, $delivery): bool {
        $timestamp = (int) $request->header('webhook-timestamp')[0];

        return $request->header('webhook-id')[0] === $delivery->event_id
            && $request->header('webhook-signature')[0] === $endpoint->sign($delivery->event_id, $timestamp, $request->body());
    });

    expect($delivery->fresh()->succeeded())->toBeTrue()
        ->and($delivery->fresh()->attempts)->toBe(1);
});

test('any 2xx counts as accepted', function (int $status) {
    Http::fake(['*' => Http::response('', $status)]);

    [$team] = teamWithOwner();
    $delivery = deliveryFor(endpointFor($team));

    (new DeliverWebhook($delivery))->handle();

    expect($delivery->fresh()->succeeded())->toBeTrue();
})->with([200, 201, 202, 204]);

test('a failing endpoint is recorded and retried', function () {
    Http::fake(['*' => Http::response('down', 503)]);

    [$team] = teamWithOwner();
    $delivery = deliveryFor(endpointFor($team));

    $job = new DeliverWebhook($delivery);
    $job->handle();

    $delivery->refresh();

    expect($delivery->succeeded())->toBeFalse()
        ->and($delivery->response_status)->toBe(503)
        ->and($delivery->response_body)->toContain('down')
        ->and($delivery->attempts)->toBe(1)
        ->and($delivery->failed_at)->toBeNull();
});

test('a disabled endpoint is not delivered to, even if already queued', function () {
    Http::fake();

    [$team] = teamWithOwner();
    $endpoint = endpointFor($team);
    $delivery = deliveryFor($endpoint);

    $endpoint->forceFill(['disabled_at' => now()])->save();

    (new DeliverWebhook($delivery))->handle();

    Http::assertNothingSent();
});

test('the secret never leaves the server in a listing', function () {
    [$team] = teamWithOwner();
    [, $secret] = apiKeyFor($team);

    $endpoint = endpointFor($team);

    $response = $this->withToken($secret)->getJson('/api/v1/webhook-endpoints')->assertSuccessful();

    expect($response->json('data.0'))->not->toHaveKey('secret')
        ->and($response->getContent())->not->toContain($endpoint->secret);
});

test('creating an endpoint returns the secret exactly once', function () {
    [$team] = teamWithOwner();
    [, $key] = apiKeyFor($team);

    $created = $this->withToken($key)->postJson('/api/v1/webhook-endpoints', [
        'url' => 'https://8.8.8.8/hooks',
        'events' => ['member.added'],
    ])->assertCreated();

    $secret = $created->json('data.secret');

    expect($secret)->toStartWith('whsec_');

    $this->withToken($key)
        ->getJson('/api/v1/webhook-endpoints/'.$created->json('data.id'))
        ->assertSuccessful()
        ->assertJsonMissingPath('data.secret');
});

test('an http url is refused', function () {
    [$team] = teamWithOwner();
    [, $key] = apiKeyFor($team);

    $this->withToken($key)->postJson('/api/v1/webhook-endpoints', [
        'url' => 'http://example.test/hooks',
        'events' => ['*'],
    ])->assertStatus(422);
});

test('creating an endpoint twice with one idempotency key registers one', function () {
    [$team] = teamWithOwner();
    [, $key] = apiKeyFor($team);

    $payload = ['url' => 'https://8.8.8.8/hooks', 'events' => ['*']];

    $first = $this->withToken($key)->withHeader('Idempotency-Key', 'create-1')
        ->postJson('/api/v1/webhook-endpoints', $payload)->assertCreated();

    $second = $this->withToken($key)->withHeader('Idempotency-Key', 'create-1')
        ->postJson('/api/v1/webhook-endpoints', $payload)->assertCreated();

    expect($team->webhooks()->count())->toBe(1)
        ->and($second->json('data.id'))->toBe($first->json('data.id'))
        ->and($second->headers->get('Idempotent-Replayed'))->toBe('true');
});

test('an endpoint can be disabled and re-enabled', function () {
    [$team] = teamWithOwner();
    [, $key] = apiKeyFor($team);

    $endpoint = endpointFor($team);

    $this->withToken($key)
        ->putJson("/api/v1/webhook-endpoints/{$endpoint->id}", ['active' => false])
        ->assertSuccessful()
        ->assertJsonPath('data.active', false);

    $this->withToken($key)
        ->putJson("/api/v1/webhook-endpoints/{$endpoint->id}", ['active' => true])
        ->assertSuccessful()
        ->assertJsonPath('data.active', true);
});

test('another team\'s endpoint is not found', function () {
    [$team] = teamWithOwner();
    [$other] = teamWithOwner();

    [, $key] = apiKeyFor($team);
    $foreign = endpointFor($other);

    $this->withToken($key)->getJson("/api/v1/webhook-endpoints/{$foreign->id}")->assertNotFound();
    $this->withToken($key)->deleteJson("/api/v1/webhook-endpoints/{$foreign->id}")->assertNotFound();

    expect($foreign->fresh())->not->toBeNull();
});

test('deleting an endpoint takes its deliveries with it', function () {
    [$team] = teamWithOwner();
    [, $key] = apiKeyFor($team);

    $endpoint = endpointFor($team);
    $delivery = deliveryFor($endpoint);

    $this->withToken($key)->deleteJson("/api/v1/webhook-endpoints/{$endpoint->id}")->assertNoContent();

    $this->assertDatabaseMissing('webhook_deliveries', ['id' => $delivery->id]);
});
