<?php

use App\Enums\ApiKeyMode;
use App\Models\ApiKey;
use App\Models\Team;
use App\Models\User;

test('a minted secret carries its mode in the prefix', function (ApiKeyMode $mode) {
    [$secret] = ApiKey::mintSecret($mode);

    expect($secret)->toStartWith("sk_{$mode->value}_");
})->with([
    'live' => ApiKeyMode::Live,
    'test' => ApiKeyMode::Test,
]);

test('a minted secret ends with a checksum of its random portion', function () {
    [$secret] = ApiKey::mintSecret(ApiKeyMode::Live);

    $body = str($secret)->after('sk_live_')->value();

    expect(substr($body, 43))->toBe(ApiKey::checksum(substr($body, 0, 43)));
});

test('a tampered secret fails its own checksum, so a scanner can reject it without a lookup', function () {
    [$secret] = ApiKey::mintSecret(ApiKeyMode::Live);

    $body = str($secret)->after('sk_live_')->value();
    $tampered = ($body[0] === 'a' ? 'b' : 'a').substr($body, 1, 42);

    expect(substr($body, 43))->not->toBe(ApiKey::checksum($tampered));
});

test('the hint is the tail of the secret, so a key stays recognisable in a list', function () {
    [$secret, $hint] = ApiKey::mintSecret(ApiKeyMode::Live);

    expect($hint)->toHaveLength(ApiKey::HINT_LENGTH)
        ->and($secret)->toEndWith($hint);
});

test('every secret is unique', function () {
    $secrets = collect(range(1, 50))->map(fn (): string => ApiKey::mintSecret(ApiKeyMode::Live)[0]);

    expect($secrets->unique())->toHaveCount(50);
});

test('the masked form reveals nothing beyond the hint', function () {
    [, $hint] = ApiKey::mintSecret(ApiKeyMode::Test);

    $key = new ApiKey(['mode' => ApiKeyMode::Test, 'last_four' => $hint]);

    expect($key->masked())->toBe('sk_test_••••••••'.$hint);
});

test('a key is active until it expires', function () {
    $key = new ApiKey;

    expect($key->isActive())->toBeTrue();

    $key->expires_at = now()->addDay();
    expect($key->isActive())->toBeTrue();

    $key->expires_at = now()->subSecond();
    expect($key->isActive())->toBeFalse()
        ->and($key->isExpired())->toBeTrue();
});

test('revocation scheduled in the future leaves the key usable until then', function () {
    [$key] = apiKeyFor(Team::factory()->create());

    $key->revoke(now()->addHours(24));

    expect($key->isActive())->toBeTrue()
        ->and($key->isRevoked())->toBeFalse();

    $this->travel(25)->hours();

    expect($key->fresh()->isActive())->toBeFalse()
        ->and($key->fresh()->isRevoked())->toBeTrue();
});

test('revoking without a grace period takes effect immediately', function () {
    [$key] = apiKeyFor(Team::factory()->create());

    $key->revoke();

    expect($key->isActive())->toBeFalse();
});

test('a revoked key is kept, so a caller still sending it can be identified', function () {
    [$key] = apiKeyFor(Team::factory()->create());

    $key->revoke();

    $this->assertDatabaseHas('personal_access_tokens', ['id' => $key->id]);
});

test('the active scope excludes revoked and expired keys', function () {
    $team = Team::factory()->create();

    [$usable] = apiKeyFor($team);
    apiKeyFor($team)[0]->revoke();
    apiKeyFor($team, ['expires_at' => now()->subDay()]);

    expect($team->apiKeys()->count())->toBe(3)
        ->and($team->apiKeys()->active()->pluck('id')->all())->toBe([$usable->id]);
});

test('a key belongs to the team, not the member who created it', function () {
    $creator = User::factory()->create();
    $team = Team::factory()->create();

    [$key] = apiKeyFor($team, ['created_by' => $creator->id]);

    expect($key->tokenable->is($team))->toBeTrue()
        ->and($key->creator->is($creator))->toBeTrue();
});

test('a key outlives the member who created it', function () {
    $creator = User::factory()->create();
    $team = Team::factory()->create();

    [$key] = apiKeyFor($team, ['created_by' => $creator->id]);

    $creator->delete();

    expect($key->fresh())->not->toBeNull()
        ->and($key->fresh()->isActive())->toBeTrue()
        ->and($key->fresh()->created_by)->toBeNull();
});

test('only the hash of the secret is stored', function () {
    [$key, $secret] = apiKeyFor(Team::factory()->create());

    expect($key->token)->toBe(hash('sha256', $secret));

    $this->assertDatabaseMissing('personal_access_tokens', ['token' => $secret]);
});
