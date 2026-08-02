<?php

use App\Enums\TeamRole;
use App\Models\Team;
use App\Models\User;

test('the team endpoint describes the key owner', function () {
    [$team] = teamWithOwner();
    [, $secret] = apiKeyFor($team);

    $this->withToken($secret)
        ->getJson('/api/v1/team')
        ->assertSuccessful()
        ->assertJsonPath('data.id', (string) $team->id)
        ->assertJsonPath('data.name', $team->name)
        ->assertJsonPath('data.slug', $team->slug)
        ->assertJsonPath('data.personal', false)
        ->assertJsonStructure(['data' => ['id', 'name', 'slug', 'personal', 'created_at']]);
});

test('a personal team reports itself as personal', function () {
    $owner = User::factory()->create();
    $team = Team::factory()->create(['is_personal' => true]);
    $team->members()->attach($owner, ['role' => TeamRole::Owner->value]);

    [, $secret] = apiKeyFor($team);

    $this->withToken($secret)
        ->getJson('/api/v1/team')
        ->assertSuccessful()
        ->assertJsonPath('data.personal', true);
});

test('the members endpoint lists the team with roles', function () {
    [$team, $owner] = teamWithOwner();
    $member = User::factory()->create();
    $team->members()->attach($member, ['role' => TeamRole::Member->value]);

    [, $secret] = apiKeyFor($team);

    $this->withToken($secret)
        ->getJson('/api/v1/members')
        ->assertSuccessful()
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('data.0.id', (string) $owner->id)
        ->assertJsonPath('data.0.role', 'owner')
        ->assertJsonPath('data.1.id', (string) $member->id)
        ->assertJsonPath('data.1.role', 'member')
        ->assertJsonStructure(['data' => [['id', 'name', 'email', 'role', 'joined_at']]]);
});

test('the members endpoint pages by cursor', function () {
    [$team] = teamWithOwner();
    $team->members()->attach(User::factory()->count(4)->create(), ['role' => TeamRole::Member->value]);

    [, $secret] = apiKeyFor($team);

    $response = $this->withToken($secret)
        ->getJson('/api/v1/members?per_page=2')
        ->assertSuccessful()
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('meta.per_page', 2);

    expect($response->json('meta.next_cursor'))->toBeString()->not->toBeEmpty()
        ->and($response->json('meta.prev_cursor'))->toBeNull();
});

test('walking the cursor returns every member exactly once', function () {
    [$team] = teamWithOwner();
    $team->members()->attach(User::factory()->count(6)->create(), ['role' => TeamRole::Member->value]);

    [, $secret] = apiKeyFor($team);

    $seen = [];
    $cursor = null;
    $pages = 0;

    do {
        $response = $this->withToken($secret)
            ->getJson('/api/v1/members?per_page=2'.($cursor ? "&cursor={$cursor}" : ''))
            ->assertSuccessful();

        $seen = [...$seen, ...$response->json('data.*.id')];
        $cursor = $response->json('meta.next_cursor');
        $pages++;
    } while ($cursor !== null && $pages < 10);

    expect($seen)->toHaveCount(7)
        ->and(array_unique($seen))->toHaveCount(7)
        ->and($pages)->toBe(4);
});

test('a member added mid-walk does not shift the rows already returned', function () {
    [$team] = teamWithOwner();
    $team->members()->attach(User::factory()->count(3)->create(), ['role' => TeamRole::Member->value]);

    [, $secret] = apiKeyFor($team);

    $first = $this->withToken($secret)->getJson('/api/v1/members?per_page=2')->assertSuccessful();

    // Offset paging would push a row across the page boundary here and the caller would never see it.
    $team->members()->attach(User::factory()->create(), ['role' => TeamRole::Member->value]);

    $second = $this->withToken($secret)
        ->getJson('/api/v1/members?per_page=2&cursor='.$first->json('meta.next_cursor'))
        ->assertSuccessful();

    expect(array_intersect($first->json('data.*.id'), $second->json('data.*.id')))->toBeEmpty();
});

test('an unusable cursor is rejected rather than silently ignored', function () {
    [$team] = teamWithOwner();
    [, $secret] = apiKeyFor($team);

    $this->withToken($secret)
        ->getJson('/api/v1/members?cursor=not-a-cursor')
        ->assertBadRequest()
        ->assertJsonPath('status', 400);
});

test('the page size is capped, so one caller cannot ask for the whole table', function () {
    [$team] = teamWithOwner();
    $team->members()->attach(User::factory()->count(3)->create(), ['role' => TeamRole::Member->value]);

    [, $secret] = apiKeyFor($team);

    $this->withToken($secret)
        ->getJson('/api/v1/members?per_page=5000')
        ->assertSuccessful()
        ->assertJsonPath('meta.per_page', config('api.pagination.max_per_page'));
});

test('a single member can be fetched', function () {
    [$team, $owner] = teamWithOwner();
    [, $secret] = apiKeyFor($team);

    $this->withToken($secret)
        ->getJson("/api/v1/members/{$owner->id}")
        ->assertSuccessful()
        ->assertJsonPath('data.id', (string) $owner->id)
        ->assertJsonPath('data.email', $owner->email);
});

test('a key cannot see another team', function () {
    [$team] = teamWithOwner();
    [$other] = teamWithOwner();

    [, $secret] = apiKeyFor($team);

    $this->withToken($secret)
        ->getJson('/api/v1/team')
        ->assertSuccessful()
        ->assertJsonPath('data.id', (string) $team->id)
        ->assertJsonMissing(['id' => (string) $other->id]);
});

test('a key cannot list another team\'s members', function () {
    [$team] = teamWithOwner();
    [$other, $outsider] = teamWithOwner();

    [, $secret] = apiKeyFor($team);

    $response = $this->withToken($secret)->getJson('/api/v1/members')->assertSuccessful();

    expect($response->json('data.*.id'))->not->toContain((string) $outsider->id)
        ->and($other->members()->count())->toBe(1);
});

test('a member of another team is not found, not forbidden', function () {
    [$team] = teamWithOwner();
    [, $outsider] = teamWithOwner();

    [, $secret] = apiKeyFor($team);

    $this->withToken($secret)
        ->getJson("/api/v1/members/{$outsider->id}")
        ->assertNotFound()
        ->assertJsonPath('status', 404);
});

test('an unknown member is not found', function () {
    [$team] = teamWithOwner();
    [, $secret] = apiKeyFor($team);

    $this->withToken($secret)
        ->getJson('/api/v1/members/999999')
        ->assertNotFound();
});

test('every response carries the trace id of the request behind it', function () {
    [$team] = teamWithOwner();
    [, $secret] = apiKeyFor($team);

    $response = $this->withToken($secret)->getJson('/api/v1/team')->assertSuccessful();

    expect($response->headers->get('X-Trace-Id'))->toBeString()->not->toBeEmpty();
});
