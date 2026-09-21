<?php

declare(strict_types=1);

use App\Models\Endpoint;
use ArtisanBuild\BuiltForCloud\StandaloneAccess;
use ArtisanBuild\BuiltForCloud\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

beforeEach(function (): void {
    config()->set('app.key', 'base64:'.base64_encode(random_bytes(32)));
});

function endpointTestUser(int $teamId): User
{
    $user = User::query()->create([
        'name' => 'Endpoint operator',
        'email' => Str::uuid().'@example.test',
        'password' => Hash::make('test-password'),
    ]);
    DB::table('team_user')->insert([
        'team_id' => $teamId,
        'user_id' => $user->getKey(),
    ]);

    return $user->refresh();
}

function endpointForTeam(int $teamId, string $name): Endpoint
{
    $endpoint = new Endpoint(['name' => $name]);
    $endpoint->team_id = $teamId;
    $endpoint->save();

    return $endpoint;
}

function endpointTeamId(): int
{
    return (int) DB::table('teams')->value('id');
}

it('lets an authenticated operator create list and view a named endpoint', function (): void {
    $user = endpointTestUser(endpointTeamId());
    $name = 'Orders '.Str::random(12);
    $this->actingAs($user)->withSession([
        StandaloneAccess::SESSION_VERSION_KEY => $user->auth_session_version,
    ]);

    $create = $this->get(route('endpoints.create'))->assertOk();
    expect(substr_count($create->getContent(), 'data-testid="endpoints-create"'))->toBe(1);

    $this->post(route('endpoints.store'), ['name' => $name])
        ->assertRedirect();

    $endpoint = Endpoint::query()->where('name', $name)->sole();
    expect($endpoint->team_id)->toBe(endpointTeamId());
    $detail = $this->get(route('endpoints.show', $endpoint))->assertOk();
    expect(substr_count($detail->getContent(), 'data-testid="endpoint-detail"'))->toBe(1);
    $detail->assertSeeText($name)->assertSeeText($endpoint->captureUrl());

    $index = $this->get(route('endpoints.index'))->assertOk();
    expect(substr_count($index->getContent(), 'data-testid="endpoints-index"'))->toBe(1);
    $index->assertSeeText($name)->assertSeeText($endpoint->captureUrl());
});

it('generates distinct identifiers with at least 128 random bits for duplicate names', function (): void {
    $teamId = endpointTeamId();
    $name = 'Duplicate '.Str::random(12);
    $first = endpointForTeam($teamId, $name);
    $second = endpointForTeam($teamId, $name);

    expect($first->public_id)
        ->toMatch('/^[a-f0-9]{32}$/')
        ->not->toBe((string) $first->getKey())
        ->not->toBe($second->public_id)
        ->and($second->public_id)->toMatch('/^[a-f0-9]{32}$/')
        ->and($first->captureUrl())->not->toBe($second->captureUrl());
});

it('does not disclose endpoint data to an unauthenticated request', function (): void {
    $name = 'Private '.Str::random(12);
    $endpoint = endpointForTeam(endpointTeamId(), $name);

    $this->get(route('endpoints.show', $endpoint))
        ->assertRedirect(route('bfc.login'))
        ->assertDontSee($name);
});

it('isolates endpoint data between teams', function (): void {
    $firstTeamId = endpointTeamId();
    $secondTeamId = DB::table('teams')->insertGetId([
        'name' => 'Second '.Str::random(12),
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $firstUser = endpointTestUser($firstTeamId);
    $secondUser = endpointTestUser($secondTeamId);
    $secretName = 'First team secret '.Str::random(12);
    $endpoint = endpointForTeam($firstTeamId, $secretName);

    $this->actingAs($firstUser)->withSession([
        StandaloneAccess::SESSION_VERSION_KEY => $firstUser->auth_session_version,
    ])->get(route('endpoints.index'))
        ->assertOk()
        ->assertSeeText($secretName);

    $this->actingAs($secondUser)->withSession([
        StandaloneAccess::SESSION_VERSION_KEY => $secondUser->auth_session_version,
    ])->get(route('endpoints.index'))
        ->assertOk()
        ->assertDontSee($secretName);

    $this->get(route('endpoints.show', $endpoint))
        ->assertNotFound()
        ->assertDontSee($secretName);
});

it('denies endpoint management to an authenticated user without a team', function (): void {
    $secretName = 'Unassigned user secret '.Str::random(12);
    $attemptedName = 'Forbidden endpoint '.Str::random(12);
    $endpoint = endpointForTeam(endpointTeamId(), $secretName);
    $user = User::query()->create([
        'name' => 'Unassigned operator',
        'email' => Str::uuid().'@example.test',
        'password' => Hash::make('test-password'),
    ])->refresh();

    $this->actingAs($user)->withSession([
        StandaloneAccess::SESSION_VERSION_KEY => $user->auth_session_version,
    ]);

    $this->get(route('endpoints.index'))
        ->assertForbidden()
        ->assertDontSee($secretName);
    $this->get(route('endpoints.create'))
        ->assertForbidden()
        ->assertDontSee($secretName);
    $this->post(route('endpoints.store'), ['name' => $attemptedName])
        ->assertForbidden()
        ->assertDontSee($secretName);
    $this->get(route('endpoints.show', $endpoint))
        ->assertForbidden()
        ->assertDontSee($secretName);

    expect(DB::table('team_user')->where('user_id', $user->getKey())->count())->toBe(0)
        ->and(Endpoint::query()->where('name', $attemptedName)->exists())->toBeFalse();
});

it('protects management and inspection routes while leaving only ingest public', function (): void {
    foreach (['endpoints.index', 'endpoints.create', 'endpoints.store', 'endpoints.show'] as $name) {
        expect(Route::getRoutes()->getByName($name)?->gatherMiddleware())->toContain('bfc.auth');
    }

    foreach (['captures.index', 'captures.show'] as $name) {
        expect(Route::getRoutes()->getByName($name)?->gatherMiddleware())->toContain('bfc.auth');
    }

    foreach (['endpoint-settings.edit', 'endpoint-settings.update'] as $name) {
        expect(Route::getRoutes()->getByName($name)?->gatherMiddleware())->toContain('bfc.auth');
    }

    expect(Route::has('endpoints.destroy'))->toBeFalse()
        ->and(Route::getRoutes()->getByName('hooks.capture')?->gatherMiddleware())->not->toContain('bfc.auth');
});
