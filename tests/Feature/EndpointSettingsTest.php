<?php

declare(strict_types=1);

use App\Models\Endpoint;
use ArtisanBuild\BuiltForCloud\StandaloneAccess;
use ArtisanBuild\BuiltForCloud\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

beforeEach(function (): void {
    config()->set('app.key', 'base64:'.base64_encode(random_bytes(32)));
});

function settingsTeamId(): int
{
    return (int) DB::table('teams')->value('id');
}

function settingsEndpoint(int $teamId, string $name): Endpoint
{
    $endpoint = new Endpoint(['name' => $name]);
    $endpoint->team_id = $teamId;
    $endpoint->save();

    return $endpoint;
}

function settingsUser(?int $teamId): User
{
    $user = User::query()->create([
        'name' => 'Settings operator',
        'email' => Str::uuid().'@example.test',
        'password' => bcrypt('test-password'),
    ]);

    if ($teamId !== null) {
        DB::table('team_user')->insert([
            'team_id' => $teamId,
            'user_id' => $user->getKey(),
        ]);
    }

    return $user->refresh();
}

function actingAsSettingsUser(TestCase $test, User $user): void
{
    $test->actingAs($user)->withSession([
        StandaloneAccess::SESSION_VERSION_KEY => $user->auth_session_version,
    ]);
}

it('defaults endpoints to bounded retention with redaction disabled', function (): void {
    $endpoint = settingsEndpoint(settingsTeamId(), 'Defaults '.Str::random(12));

    expect($endpoint->retention_days)->toBe(30)
        ->and($endpoint->redacted_header_names)->toBe([])
        ->and($endpoint->body_redaction)->toBeFalse()
        ->and(DB::table('endpoints')->where('id', $endpoint->id)->value('retention_days'))->toBe(30);
});

it('shows settings from endpoint detail and normalizes explicit opt-ins', function (): void {
    $teamId = settingsTeamId();
    $endpoint = settingsEndpoint($teamId, 'Settings '.Str::random(12));
    actingAsSettingsUser($this, settingsUser($teamId));

    $this->get(route('endpoints.show', $endpoint))
        ->assertOk()
        ->assertSee(route('endpoint-settings.edit', $endpoint), escape: false);

    $settings = $this->get(route('endpoint-settings.edit', $endpoint))->assertOk();
    expect(substr_count($settings->getContent(), 'data-testid="endpoint-settings"'))->toBe(1);
    $settings->assertSeeText($endpoint->name)
        ->assertSee('value="30"', escape: false)
        ->assertSeeText('Changes apply only to future captures and never rewrite existing captures.');

    $this->put(route('endpoint-settings.update', $endpoint), [
        'retention_days' => 365,
        'redacted_header_names' => "  X-Api-Key  ,\nauthorization, x-api-key\nX-Trace-ID ",
        'body_redaction' => '1',
    ])->assertRedirect(route('endpoint-settings.edit', $endpoint));

    $endpoint->refresh();

    expect($endpoint->retention_days)->toBe(365)
        ->and($endpoint->redacted_header_names)->toBe([
            'x-api-key',
            'authorization',
            'x-trace-id',
        ])
        ->and($endpoint->body_redaction)->toBeTrue();
});

it('rejects retention outside the inclusive bound and malformed header names', function (
    mixed $retentionDays,
    string $headerNames,
): void {
    $teamId = settingsTeamId();
    $endpoint = settingsEndpoint($teamId, 'Validation '.Str::random(12));
    actingAsSettingsUser($this, settingsUser($teamId));

    $this->from(route('endpoint-settings.edit', $endpoint))
        ->put(route('endpoint-settings.update', $endpoint), [
            'retention_days' => $retentionDays,
            'redacted_header_names' => $headerNames,
            'body_redaction' => '0',
        ])
        ->assertRedirect(route('endpoint-settings.edit', $endpoint))
        ->assertSessionHasErrors();

    expect($endpoint->refresh()->retention_days)->toBe(30)
        ->and($endpoint->redacted_header_names)->toBe([]);
})->with([
    'zero days' => [0, 'x-valid'],
    'more than one year' => [366, 'x-valid'],
    'fractional days' => ['3.5', 'x-valid'],
    'space in header name' => [30, 'x invalid'],
    'colon in header name' => [30, 'x-invalid: value'],
]);

it('does not disclose or change settings for signed-out and cross-team requests', function (): void {
    $firstTeamId = settingsTeamId();
    $secondTeamId = DB::table('teams')->insertGetId([
        'name' => 'Settings second team '.Str::random(12),
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $secretHeader = 'x-secret-'.strtolower(Str::random(12));
    $endpoint = settingsEndpoint($firstTeamId, 'Protected settings '.Str::random(12));
    $endpoint->update([
        'retention_days' => 73,
        'redacted_header_names' => [$secretHeader],
        'body_redaction' => true,
    ]);

    $this->get(route('endpoint-settings.edit', $endpoint))
        ->assertRedirect(route('bfc.login'))
        ->assertDontSee($secretHeader);
    $this->put(route('endpoint-settings.update', $endpoint), [
        'retention_days' => 1,
        'redacted_header_names' => 'x-attacker',
        'body_redaction' => '0',
    ])->assertRedirect(route('bfc.login'))->assertDontSee($secretHeader);

    actingAsSettingsUser($this, settingsUser($secondTeamId));
    $this->get(route('endpoint-settings.edit', $endpoint))
        ->assertNotFound()
        ->assertDontSee($secretHeader);
    $this->put(route('endpoint-settings.update', $endpoint), [
        'retention_days' => 1,
        'redacted_header_names' => 'x-attacker',
        'body_redaction' => '0',
    ])->assertNotFound()->assertDontSee($secretHeader);

    expect($endpoint->refresh()->retention_days)->toBe(73)
        ->and($endpoint->redacted_header_names)->toBe([$secretHeader])
        ->and($endpoint->body_redaction)->toBeTrue();
});

it('denies settings to a no-membership caller without provisioning membership', function (): void {
    $endpoint = settingsEndpoint(settingsTeamId(), 'No membership settings '.Str::random(12));
    $secretHeader = 'x-private-'.strtolower(Str::random(12));
    $endpoint->update(['redacted_header_names' => [$secretHeader]]);
    $user = settingsUser(null);
    actingAsSettingsUser($this, $user);

    $this->get(route('endpoint-settings.edit', $endpoint))
        ->assertForbidden()
        ->assertDontSee($secretHeader);
    $this->put(route('endpoint-settings.update', $endpoint), [
        'retention_days' => 1,
        'redacted_header_names' => 'x-attacker',
        'body_redaction' => '1',
    ])->assertForbidden()->assertDontSee($secretHeader);

    expect(DB::table('team_user')->where('user_id', $user->getKey())->count())->toBe(0)
        ->and($endpoint->refresh()->retention_days)->toBe(30)
        ->and($endpoint->redacted_header_names)->toBe([$secretHeader]);
});
