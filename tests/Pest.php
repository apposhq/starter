<?php

use App\Enums\ApiKeyMode;
use App\Enums\TeamRole;
use App\Models\ApiKey;
use App\Models\Team;
use App\Models\User;
use App\Models\WebhookDelivery;
use App\Models\WebhookEndpoint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind different classes or traits.
|
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

/**
 * Mint a real API key for a team and hand back the model with its plaintext secret.
 *
 * Goes through the same minting the controller uses rather than a factory, so tests authenticate with a
 * secret the application would actually have issued — a fixture with a hand-written token would pass
 * even if minting and lookup disagreed.
 *
 * @param  array<string, mixed>  $attributes
 * @return array{0: ApiKey, 1: string}
 */
function apiKeyFor(Team $team, array $attributes = []): array
{
    $mode = $attributes['mode'] ?? ApiKeyMode::Live;

    [$secret, $hint] = ApiKey::mintSecret($mode);

    $key = $team->apiKeys()->create([
        'name' => 'Test key',
        'token' => hash('sha256', $secret),
        'abilities' => ['*'],
        'mode' => $mode,
        'last_four' => $hint,
        ...$attributes,
    ]);

    return [$key, $secret];
}

/**
 * A team with one owner, which is the shape every API request assumes.
 *
 * @return array{0: Team, 1: User}
 */
function teamWithOwner(): array
{
    $owner = User::factory()->create();
    $team = Team::factory()->create();

    $team->members()->attach($owner, ['role' => TeamRole::Owner->value]);

    return [$team, $owner];
}

/**
 * A webhook endpoint for a team, with a real signing secret.
 *
 * @param  array<string, mixed>  $attributes
 */
function endpointFor(Team $team, array $attributes = []): WebhookEndpoint
{
    return $team->webhooks()->create([
        'url' => 'https://8.8.8.8/hooks',
        'events' => [WebhookEndpoint::ALL_EVENTS],
        'secret' => WebhookEndpoint::mintSecret(),
        ...$attributes,
    ]);
}

/**
 * A queued delivery, shaped the way Webhooks::send would leave it.
 */
function deliveryFor(WebhookEndpoint $endpoint, string $type = 'member.added'): WebhookDelivery
{
    $eventId = (string) Str::ulid();

    return $endpoint->deliveries()->create([
        'event_id' => $eventId,
        'event_type' => $type,
        'payload' => ['id' => $eventId, 'type' => $type, 'created_at' => now()->toIso8601String(), 'data' => []],
    ]);
}
