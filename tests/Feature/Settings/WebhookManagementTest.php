<?php

use App\Enums\TeamRole;
use App\Models\User;
use App\Models\WebhookEndpoint;
use Inertia\Support\SessionKey;
use Inertia\Testing\AssertableInertia as Assert;

test('the endpoint list is displayed', function () {
    [$team, $owner] = teamWithOwner();
    $endpoint = endpointFor($team, ['description' => 'Production', 'created_by' => $owner->id]);

    $this->actingAs($owner)
        ->get(route('webhooks.index', ['team' => $team->slug]))
        ->assertSuccessful()
        ->assertInertia(fn (Assert $page) => $page
            ->component('settings/webhooks')
            ->has('endpoints', 1)
            ->where('endpoints.0.id', $endpoint->id)
            ->where('endpoints.0.description', 'Production')
            ->where('endpoints.0.active', true),
        );
});

test('the signing secret never reaches the list', function () {
    [$team, $owner] = teamWithOwner();
    $endpoint = endpointFor($team);

    $response = $this->actingAs($owner)->get(route('webhooks.index', ['team' => $team->slug]));

    $response->assertSuccessful()->assertDontSee($endpoint->secret);

    expect($response->viewData('page')['props']['endpoints'][0])->not->toHaveKey('secret');
});

test('the list shows how recent deliveries went', function () {
    [$team, $owner] = teamWithOwner();
    $endpoint = endpointFor($team);

    deliveryFor($endpoint)->forceFill(['delivered_at' => now(), 'response_status' => 200, 'attempts' => 1])->save();
    deliveryFor($endpoint)->forceFill(['failed_at' => now(), 'response_status' => 500, 'attempts' => 6])->save();

    $this->actingAs($owner)
        ->get(route('webhooks.index', ['team' => $team->slug]))
        ->assertSuccessful()
        ->assertInertia(fn (Assert $page) => $page
            ->has('endpoints.0.recent_deliveries', 2)
            ->where('endpoints.0.failed_deliveries_count', 1),
        );
});

test('creating an endpoint returns the signing secret once', function () {
    [$team, $owner] = teamWithOwner();

    $response = $this->actingAs($owner)->post(route('webhooks.store', ['team' => $team->slug]), [
        'url' => 'https://8.8.8.8/hooks',
        'description' => 'Production',
        'events' => ['*'],
    ]);

    $response->assertRedirect(route('webhooks.index', ['team' => $team->slug]));

    $endpoint = $team->webhooks()->sole();
    $secret = $response->getSession()->get(SessionKey::FLASH_DATA)['secret'];

    expect($secret)->toStartWith('whsec_')
        ->and($secret)->toBe($endpoint->secret)
        ->and($endpoint->created_by)->toBe($owner->id);
});

test('an http url is refused', function () {
    [$team, $owner] = teamWithOwner();

    $this->actingAs($owner)
        ->post(route('webhooks.store', ['team' => $team->slug]), [
            'url' => 'http://example.test/hooks',
            'events' => ['*'],
        ])
        ->assertSessionHasErrors('url');

    expect($team->webhooks()->count())->toBe(0);
});

test('an endpoint needs a url and at least one event', function (array $payload, string $invalid) {
    [$team, $owner] = teamWithOwner();

    $this->actingAs($owner)
        ->post(route('webhooks.store', ['team' => $team->slug]), $payload)
        ->assertSessionHasErrors($invalid);
})->with([
    'no url' => [['events' => ['*']], 'url'],
    'no events' => [['url' => 'https://8.8.8.8/h'], 'events'],
    'empty events' => [['url' => 'https://8.8.8.8/h', 'events' => []], 'events'],
]);

test('an endpoint can be disabled and re-enabled', function () {
    [$team, $owner] = teamWithOwner();
    $endpoint = endpointFor($team);

    $this->actingAs($owner)
        ->patch(route('webhooks.update', ['team' => $team->slug, 'webhook' => $endpoint->id]), ['active' => false])
        ->assertRedirect();

    expect($endpoint->fresh()->isActive())->toBeFalse();

    $this->actingAs($owner)
        ->patch(route('webhooks.update', ['team' => $team->slug, 'webhook' => $endpoint->id]), ['active' => true])
        ->assertRedirect();

    expect($endpoint->fresh()->isActive())->toBeTrue();
});

test('an admin can manage webhooks', function () {
    [$team] = teamWithOwner();
    $admin = User::factory()->create();
    $team->members()->attach($admin, ['role' => TeamRole::Admin->value]);

    $this->actingAs($admin)
        ->post(route('webhooks.store', ['team' => $team->slug]), [
            'url' => 'https://8.8.8.8/hooks',
            'events' => ['*'],
        ])
        ->assertRedirect();

    expect($team->webhooks()->count())->toBe(1);
});

test('a plain member cannot manage webhooks', function () {
    [$team] = teamWithOwner();
    $member = User::factory()->create();
    $team->members()->attach($member, ['role' => TeamRole::Member->value]);

    $this->actingAs($member)
        ->get(route('webhooks.index', ['team' => $team->slug]))
        ->assertForbidden();

    $this->actingAs($member)
        ->post(route('webhooks.store', ['team' => $team->slug]), [
            'url' => 'https://8.8.8.8/hooks',
            'events' => ['*'],
        ])
        ->assertForbidden();
});

test('a team cannot touch another team\'s endpoint', function () {
    [$team, $owner] = teamWithOwner();
    [$other] = teamWithOwner();

    $foreign = endpointFor($other);

    $this->actingAs($owner)
        ->patch(route('webhooks.update', ['team' => $team->slug, 'webhook' => $foreign->id]), ['active' => false])
        ->assertNotFound();

    $this->actingAs($owner)
        ->delete(route('webhooks.destroy', ['team' => $team->slug, 'webhook' => $foreign->id]))
        ->assertNotFound();

    expect($foreign->fresh()->isActive())->toBeTrue();
});

test('deleting an endpoint takes its deliveries with it', function () {
    [$team, $owner] = teamWithOwner();
    $endpoint = endpointFor($team);
    $delivery = deliveryFor($endpoint);

    $this->actingAs($owner)
        ->delete(route('webhooks.destroy', ['team' => $team->slug, 'webhook' => $endpoint->id]))
        ->assertRedirect();

    $this->assertDatabaseMissing('webhook_endpoints', ['id' => $endpoint->id]);
    $this->assertDatabaseMissing('webhook_deliveries', ['id' => $delivery->id]);
});

test('the team page links to both developer surfaces for those who can use them', function () {
    [$team, $owner] = teamWithOwner();

    $this->actingAs($owner)
        ->get(route('teams.edit', ['team' => $team->slug]))
        ->assertSuccessful()
        ->assertInertia(fn (Assert $page) => $page
            ->where('permissions.canManageApiKeys', true)
            ->where('permissions.canManageWebhooks', true),
        );
});

test('a plain member is not offered the developer surfaces', function () {
    [$team] = teamWithOwner();
    $member = User::factory()->create();
    $team->members()->attach($member, ['role' => TeamRole::Member->value]);

    $this->actingAs($member)
        ->get(route('teams.edit', ['team' => $team->slug]))
        ->assertSuccessful()
        ->assertInertia(fn (Assert $page) => $page
            ->where('permissions.canManageApiKeys', false)
            ->where('permissions.canManageWebhooks', false),
        );
});

test('an endpoint created here signs the way the spec requires', function () {
    [$team, $owner] = teamWithOwner();

    $this->actingAs($owner)->post(route('webhooks.store', ['team' => $team->slug]), [
        'url' => 'https://8.8.8.8/hooks',
        'events' => ['*'],
    ]);

    $endpoint = $team->webhooks()->sole();

    expect($endpoint->sign('msg_1', 1700000000, '{}'))->toStartWith('v1,')
        ->and($endpoint)->toBeInstanceOf(WebhookEndpoint::class);
});
