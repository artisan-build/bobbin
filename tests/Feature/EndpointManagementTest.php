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

it('protects management routes without mounting capture or deletion routes', function (): void {
    foreach (['endpoints.index', 'endpoints.create', 'endpoints.store', 'endpoints.show'] as $name) {
        expect(Route::getRoutes()->getByName($name)?->gatherMiddleware())->toContain('bfc.auth');
    }

    $uris = collect(Route::getRoutes()->getRoutes())->map->uri();

    expect(Route::has('endpoints.destroy'))->toBeFalse()
        ->and($uris->filter(fn (string $uri): bool => str_starts_with($uri, 'hooks/')))->toBeEmpty();
});
