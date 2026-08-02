<?php

use App\Enums\ApiKeyMode;

test('a response advertises the budget and what is left of it', function () {
    config(['api.rate_limits.live' => 10]);

    [$team] = teamWithOwner();
    [, $secret] = apiKeyFor($team);

    $response = $this->withToken($secret)->getJson('/api/v1/team')->assertSuccessful();

    expect($response->headers->get('X-RateLimit-Limit'))->toBe('10')
        ->and($response->headers->get('X-RateLimit-Remaining'))->toBe('9');
});

test('the remaining count falls with each request', function () {
    config(['api.rate_limits.live' => 5]);

    [$team] = teamWithOwner();
    [, $secret] = apiKeyFor($team);

    $remaining = collect(range(1, 3))->map(fn (): string => $this->withToken($secret)
        ->getJson('/api/v1/team')
        ->headers->get('X-RateLimit-Remaining'));

    expect($remaining->all())->toBe(['4', '3', '2']);
});

test('exceeding the budget answers 429', function () {
    config(['api.rate_limits.live' => 2]);

    [$team] = teamWithOwner();
    [, $secret] = apiKeyFor($team);

    $this->withToken($secret)->getJson('/api/v1/team')->assertSuccessful();
    $this->withToken($secret)->getJson('/api/v1/team')->assertSuccessful();

    $this->withToken($secret)->getJson('/api/v1/team')->assertTooManyRequests();
});

test('a throttled response says when to come back', function () {
    config(['api.rate_limits.live' => 1]);

    [$team] = teamWithOwner();
    [, $secret] = apiKeyFor($team);

    $this->withToken($secret)->getJson('/api/v1/team')->assertSuccessful();

    $response = $this->withToken($secret)->getJson('/api/v1/team')->assertTooManyRequests();

    // The exception's own headers, which the problem-details renderer has to carry through — a 429
    // without Retry-After leaves the caller guessing.
    expect($response->headers->get('Retry-After'))->toBeNumeric()
        ->and((int) $response->headers->get('Retry-After'))->toBeGreaterThan(0)
        ->and($response->headers->get('X-RateLimit-Reset'))->toBeNumeric();
});

test('a throttled response is still RFC 9457', function () {
    config(['api.rate_limits.live' => 1]);

    [$team] = teamWithOwner();
    [, $secret] = apiKeyFor($team);

    $this->withToken($secret)->getJson('/api/v1/team');

    $response = $this->withToken($secret)->getJson('/api/v1/team')->assertTooManyRequests();

    expect($response->headers->get('Content-Type'))->toContain('application/problem+json');

    $response->assertJsonPath('status', 429)
        ->assertJsonPath('title', 'Too Many Requests')
        ->assertJsonStructure(['type', 'title', 'status', 'detail', 'instance']);
});

test('the budget belongs to the key, not the address', function () {
    config(['api.rate_limits.live' => 1]);

    [$team] = teamWithOwner();
    [, $first] = apiKeyFor($team);
    [, $second] = apiKeyFor($team);

    $this->withToken($first)->getJson('/api/v1/team')->assertSuccessful();
    $this->withToken($first)->getJson('/api/v1/team')->assertTooManyRequests();

    // Same team, same IP, different key — spending one budget must not spend the other.
    $this->withToken($second)->getJson('/api/v1/team')->assertSuccessful();
});

test('one team cannot spend another team\'s budget', function () {
    config(['api.rate_limits.live' => 1]);

    [$team] = teamWithOwner();
    [$other] = teamWithOwner();

    [, $secret] = apiKeyFor($team);
    [, $otherSecret] = apiKeyFor($other);

    $this->withToken($secret)->getJson('/api/v1/team')->assertSuccessful();
    $this->withToken($secret)->getJson('/api/v1/team')->assertTooManyRequests();

    $this->withToken($otherSecret)->getJson('/api/v1/team')->assertSuccessful();
});

test('test keys get a smaller budget than live keys', function () {
    config(['api.rate_limits.live' => 9, 'api.rate_limits.test' => 3]);

    [$team] = teamWithOwner();
    [, $live] = apiKeyFor($team, ['mode' => ApiKeyMode::Live]);
    [, $test] = apiKeyFor($team, ['mode' => ApiKeyMode::Test]);

    expect($this->withToken($live)->getJson('/api/v1/team')->headers->get('X-RateLimit-Limit'))->toBe('9')
        ->and($this->withToken($test)->getJson('/api/v1/team')->headers->get('X-RateLimit-Limit'))->toBe('3');
});

test('the budget refills once the window passes', function () {
    config(['api.rate_limits.live' => 1]);

    [$team] = teamWithOwner();
    [, $secret] = apiKeyFor($team);

    $this->withToken($secret)->getJson('/api/v1/team')->assertSuccessful();
    $this->withToken($secret)->getJson('/api/v1/team')->assertTooManyRequests();

    $this->travel(61)->seconds();

    $this->withToken($secret)->getJson('/api/v1/team')->assertSuccessful();
});
