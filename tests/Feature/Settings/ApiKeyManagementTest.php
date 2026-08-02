<?php

use App\Enums\ApiKeyMode;
use App\Enums\TeamRole;
use App\Models\ApiKey;
use App\Models\User;
use Inertia\Support\SessionKey;
use Inertia\Testing\AssertableInertia as Assert;

test('the key list is displayed', function () {
    [$team, $owner] = teamWithOwner();
    [$key] = apiKeyFor($team, ['name' => 'Production', 'created_by' => $owner->id]);

    $this->actingAs($owner)
        ->get(route('api-keys.index', ['team' => $team->slug]))
        ->assertSuccessful()
        ->assertInertia(fn (Assert $page) => $page
            ->component('settings/api-keys')
            ->has('keys', 1)
            ->where('keys.0.id', $key->id)
            ->where('keys.0.name', 'Production')
            ->where('keys.0.active', true),
        );
});

test('the list shows the masked key, never the secret', function () {
    [$team, $owner] = teamWithOwner();
    [$key, $secret] = apiKeyFor($team, ['created_by' => $owner->id]);

    $response = $this->actingAs($owner)->get(route('api-keys.index', ['team' => $team->slug]));

    $response->assertSuccessful()->assertDontSee($secret);

    expect($response->viewData('page')['props']['keys'][0]['masked'])->toBe($key->masked());
});

test('creating a key returns the secret once', function () {
    [$team, $owner] = teamWithOwner();

    $response = $this->actingAs($owner)->post(route('api-keys.store', ['team' => $team->slug]), [
        'name' => 'Production',
        'mode' => ApiKeyMode::Live->value,
    ]);

    $response->assertRedirect(route('api-keys.index', ['team' => $team->slug]));

    $key = $team->apiKeys()->sole();

    expect($key->name)->toBe('Production')
        ->and($key->mode)->toBe(ApiKeyMode::Live)
        ->and($key->created_by)->toBe($owner->id);

    // Flashed rather than stored: it appears on the next render and is then gone for good.
    $secret = $response->getSession()->get(SessionKey::FLASH_DATA)['secret'];

    expect($secret)->toBeString()->toStartWith('sk_live_')
        ->and(hash('sha256', $secret))->toBe($key->token);
});

test('the created key authenticates against the API', function () {
    [$team, $owner] = teamWithOwner();

    $response = $this->actingAs($owner)->post(route('api-keys.store', ['team' => $team->slug]), [
        'name' => 'Production',
        'mode' => ApiKeyMode::Live->value,
    ]);

    $secret = $response->getSession()->get(SessionKey::FLASH_DATA)['secret'];

    $this->withToken($secret)
        ->getJson('/api/v1/team')
        ->assertSuccessful()
        ->assertJsonPath('data.id', (string) $team->id);
});

test('a key requires a name and a valid mode', function (array $payload, string $invalid) {
    [$team, $owner] = teamWithOwner();

    $this->actingAs($owner)
        ->post(route('api-keys.store', ['team' => $team->slug]), $payload)
        ->assertSessionHasErrors($invalid);

    expect($team->apiKeys()->count())->toBe(0);
})->with([
    'no name' => [['mode' => 'live'], 'name'],
    'no mode' => [['name' => 'Production'], 'mode'],
    'unknown mode' => [['name' => 'Production', 'mode' => 'staging'], 'mode'],
]);

test('an admin can manage keys', function () {
    [$team] = teamWithOwner();
    $admin = User::factory()->create();
    $team->members()->attach($admin, ['role' => TeamRole::Admin->value]);

    $this->actingAs($admin)
        ->post(route('api-keys.store', ['team' => $team->slug]), ['name' => 'CI', 'mode' => 'test'])
        ->assertRedirect();

    expect($team->apiKeys()->count())->toBe(1);
});

test('a plain member cannot manage keys', function () {
    [$team] = teamWithOwner();
    $member = User::factory()->create();
    $team->members()->attach($member, ['role' => TeamRole::Member->value]);

    $this->actingAs($member)
        ->post(route('api-keys.store', ['team' => $team->slug]), ['name' => 'Mine', 'mode' => 'live'])
        ->assertForbidden();

    expect($team->apiKeys()->count())->toBe(0);
});

test('a non-member cannot reach the key list', function () {
    [$team] = teamWithOwner();
    $outsider = User::factory()->create();

    $this->actingAs($outsider)
        ->get(route('api-keys.index', ['team' => $team->slug]))
        ->assertForbidden();
});

test('revoking a key stops it authenticating', function () {
    [$team, $owner] = teamWithOwner();
    [$key, $secret] = apiKeyFor($team, ['created_by' => $owner->id]);

    $this->withToken($secret)->getJson('/api/v1/team')->assertSuccessful();

    $this->actingAs($owner)
        ->delete(route('api-keys.destroy', ['team' => $team->slug, 'apiKey' => $key->id]))
        ->assertRedirect();

    $this->withToken($secret)->getJson('/api/v1/team')->assertForbidden();
});

test('revoking with a grace period leaves the key working until it lapses', function () {
    [$team, $owner] = teamWithOwner();
    [$key, $secret] = apiKeyFor($team, ['created_by' => $owner->id]);

    $this->actingAs($owner)
        ->delete(route('api-keys.destroy', ['team' => $team->slug, 'apiKey' => $key->id]), ['grace_hours' => 24])
        ->assertRedirect();

    $this->withToken($secret)->getJson('/api/v1/team')->assertSuccessful();

    $this->travel(25)->hours();

    $this->withToken($secret)->getJson('/api/v1/team')->assertForbidden();
});

test('a team cannot revoke another team\'s key', function () {
    [$team, $owner] = teamWithOwner();
    [$other] = teamWithOwner();

    [$key, $secret] = apiKeyFor($other);

    $this->actingAs($owner)
        ->delete(route('api-keys.destroy', ['team' => $team->slug, 'apiKey' => $key->id]))
        ->assertNotFound();

    $this->withToken($secret)->getJson('/api/v1/team')->assertSuccessful();
});

test('keys are listed for the team, not the member who created them', function () {
    [$team, $owner] = teamWithOwner();
    $admin = User::factory()->create();
    $team->members()->attach($admin, ['role' => TeamRole::Admin->value]);

    apiKeyFor($team, ['name' => 'Owner key', 'created_by' => $owner->id]);
    apiKeyFor($team, ['name' => 'Admin key', 'created_by' => $admin->id]);

    $this->actingAs($admin)
        ->get(route('api-keys.index', ['team' => $team->slug]))
        ->assertSuccessful()
        ->assertInertia(fn (Assert $page) => $page->has('keys', 2));
});

test('a departed member leaves their keys behind', function () {
    [$team, $owner] = teamWithOwner();
    $admin = User::factory()->create();
    $team->members()->attach($admin, ['role' => TeamRole::Admin->value]);

    [$key, $secret] = apiKeyFor($team, ['created_by' => $admin->id]);

    $admin->delete();

    $this->withToken($secret)->getJson('/api/v1/team')->assertSuccessful();

    $this->actingAs($owner)
        ->get(route('api-keys.index', ['team' => $team->slug]))
        ->assertSuccessful()
        ->assertInertia(fn (Assert $page) => $page->has('keys', 1)->where('keys.0.id', $key->id));

    expect(ApiKey::find($key->id)->created_by)->toBeNull();
});
