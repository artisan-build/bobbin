<?php

declare(strict_types=1);

use App\Models\CapturedRequest;
use App\Models\Endpoint;
use ArtisanBuild\BuiltForCloud\StandaloneAccess;
use ArtisanBuild\BuiltForCloud\User;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

beforeEach(function (): void {
    config()->set('app.key', 'base64:'.base64_encode(random_bytes(32)));
});

function captureTeamId(): int
{
    return (int) DB::table('teams')->value('id');
}

function captureTestUser(?int $teamId): User
{
    $user = User::query()->create([
        'name' => 'Capture operator',
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

function captureEndpoint(int $teamId, string $name): Endpoint
{
    $endpoint = new Endpoint(['name' => $name]);
    $endpoint->team_id = $teamId;
    $endpoint->save();

    return $endpoint;
}

/**
 * @param  array<string, list<string>>  $headers
 */
function capturedRequestFor(
    Endpoint $endpoint,
    string $body,
    string $rawQuery,
    array $headers = ['x-capture-source' => ['fixture']],
): CapturedRequest {
    return $endpoint->capturedRequests()->create([
        'method' => 'POST',
        'raw_query' => $rawQuery,
        'headers' => $headers,
        'body' => $body,
        'received_at' => now(),
    ]);
}

/**
 * @param  array<string, list<string>>  $headers
 */
function sendCapture(
    Endpoint $endpoint,
    string $method,
    string $body = '',
    string $rawQuery = '',
    array $headers = [],
): TestResponse {
    $uri = '/hooks/'.$endpoint->public_id.($rawQuery === '' ? '' : '?'.$rawQuery);
    $request = Request::create($uri, $method, content: $body);

    foreach ($headers as $name => $values) {
        $request->headers->set($name, $values);
    }

    $response = app(Kernel::class)->handle($request);

    return TestResponse::fromBaseResponse($response);
}

function actingAsCaptureUser(TestCase $test, User $user): void
{
    $test->actingAs($user)->withSession([
        StandaloneAccess::SESSION_VERSION_KEY => $user->auth_session_version,
    ]);
}

it('persists raw request fidelity before acknowledging the endpoint', function (): void {
    $endpoint = captureEndpoint(captureTeamId(), 'Fidelity '.Str::random(12));
    $rawQuery = 'z=last&tag=one&tag=two&blank=&encoded=%2F%2b%20';
    $body = '{"event":"'.Str::random(12).'","active":true}';
    $firstHeader = 'first-'.Str::random(8);
    $secondHeader = 'second-'.Str::random(8);

    sendCapture($endpoint, 'PATCH', $body, $rawQuery, [
        'Content-Type' => ['application/json'],
        'X-Bobbin-Trace' => [$firstHeader, $secondHeader],
    ])->assertNoContent();

    $capture = CapturedRequest::query()->sole();

    expect($capture->endpoint_id)->toBe($endpoint->id)
        ->and($capture->method)->toBe('PATCH')
        ->and($capture->raw_query)->toBe($rawQuery)
        ->and($capture->body)->toBe($body)
        ->and($capture->headers['x-bobbin-trace'][0])->toBe($firstHeader)
        ->and($capture->headers['x-bobbin-trace'][1])->toBe($secondHeader)
        ->and($capture->headers['content-type'][0])->toBe('application/json')
        ->and($capture->received_at)->not->toBeNull();
});

it('stores empty JSON and binary bodies byte exactly', function (string $body, string $contentType): void {
    $endpoint = captureEndpoint(captureTeamId(), 'Body fidelity '.Str::random(12));

    sendCapture($endpoint, 'POST', $body, headers: [
        'Content-Type' => [$contentType],
    ])->assertNoContent();

    expect(CapturedRequest::query()->sole()->body)->toBe($body);
})->with([
    'empty' => ['', 'application/octet-stream'],
    'JSON' => ['{"message":"capture '.Str::random(12).'"}', 'application/json'],
    'binary' => ["\x00\xFF\xFE\x80binary\x00", 'application/octet-stream'],
]);

it('accepts Laravel webhook methods', function (string $method): void {
    $endpoint = captureEndpoint(captureTeamId(), $method.' '.Str::random(12));

    sendCapture($endpoint, $method)->assertNoContent();

    expect(CapturedRequest::query()->sole()->method)->toBe($method);
})->with(['GET', 'HEAD', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS']);

it('does not create a capture for an unknown public identifier', function (): void {
    $unknownPublicId = str_repeat('0', 32);

    $this->call('POST', '/hooks/'.$unknownPublicId, content: 'unknown-'.Str::random(12))
        ->assertNotFound();

    expect(CapturedRequest::query()->count())->toBe(0);
});

it('does not acknowledge a request when persistence fails', function (): void {
    $endpoint = captureEndpoint(captureTeamId(), 'Failure '.Str::random(12));
    CapturedRequest::creating(function (): never {
        throw new RuntimeException('Forced capture persistence failure.');
    });

    $response = $this->withExceptionHandling()
        ->call('POST', '/hooks/'.$endpoint->public_id, content: 'must-not-acknowledge');
    CapturedRequest::flushEventListeners();

    $response->assertServerError();
    expect($response->getStatusCode())->not->toBe(204)
        ->and(CapturedRequest::query()->count())->toBe(0);
});

it('lets a member list and inspect captures only within the selected endpoint', function (): void {
    $teamId = captureTeamId();
    $user = captureTestUser($teamId);
    $endpoint = captureEndpoint($teamId, 'Inspection '.Str::random(12));
    $otherEndpoint = captureEndpoint($teamId, 'Other endpoint '.Str::random(12));
    $querySecret = 'delivery='.Str::random(12);
    $headerSecret = 'header-'.Str::random(12);
    $bodySecret = 'body-'.Str::random(12);
    $otherSecret = 'other-'.Str::random(12);
    $capture = capturedRequestFor($endpoint, $bodySecret, $querySecret, [
        'x-inspection-secret' => [$headerSecret, 'repeat-'.Str::random(12)],
    ]);
    capturedRequestFor($otherEndpoint, $otherSecret, 'other-query=1');
    actingAsCaptureUser($this, $user);

    $index = $this->get(route('captures.index', $endpoint))->assertOk();
    expect(substr_count($index->getContent(), 'data-testid="captures-index"'))->toBe(1);
    $index->assertSeeText($endpoint->name)
        ->assertSeeText($querySecret)
        ->assertDontSee($otherSecret);

    $detail = $this->get(route('captures.show', [$endpoint, $capture]))->assertOk();
    expect(substr_count($detail->getContent(), 'data-testid="capture-detail"'))->toBe(1);
    $detail->assertSeeText($querySecret)
        ->assertSeeText($headerSecret)
        ->assertSeeText($bodySecret);

    $this->get(route('captures.show', [$otherEndpoint, $capture]))
        ->assertNotFound()
        ->assertDontSee($bodySecret);
});

it('renders empty and binary bodies with explicit reversible representations', function (): void {
    $teamId = captureTeamId();
    $user = captureTestUser($teamId);
    $endpoint = captureEndpoint($teamId, 'Body display '.Str::random(12));
    $empty = capturedRequestFor($endpoint, '', '');
    $binaryBody = "\x00binary-".Str::random(12);
    $binary = capturedRequestFor($endpoint, $binaryBody, 'format=binary', [
        'content-type' => ['application/octet-stream'],
    ]);
    actingAsCaptureUser($this, $user);

    $this->get(route('captures.show', [$endpoint, $empty]))
        ->assertOk()
        ->assertSeeText('No query string received.')
        ->assertSeeText('No body bytes received.');

    $this->get(route('captures.show', [$endpoint, $binary]))
        ->assertOk()
        ->assertSeeText('Base64 encoded')
        ->assertSeeText('Decode this Base64 value to recover the exact stored bytes.')
        ->assertSeeText(base64_encode($binaryBody));
});

it('does not disclose captures to a signed-out request', function (): void {
    $endpoint = captureEndpoint(captureTeamId(), 'Signed out '.Str::random(12));
    $secret = 'signed-out-secret-'.Str::random(12);
    $capture = capturedRequestFor($endpoint, $secret, 'secret='.$secret, [
        'x-secret' => [$secret],
    ]);

    $this->get(route('captures.index', $endpoint))
        ->assertRedirect(route('bfc.login'))
        ->assertDontSee($secret);
    $this->get(route('captures.show', [$endpoint, $capture]))
        ->assertRedirect(route('bfc.login'))
        ->assertDontSee($secret);
});

it('does not disclose captures across teams', function (): void {
    $firstTeamId = captureTeamId();
    $secondTeamId = DB::table('teams')->insertGetId([
        'name' => 'Other team '.Str::random(12),
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $endpoint = captureEndpoint($firstTeamId, 'Cross team '.Str::random(12));
    $secret = 'cross-team-secret-'.Str::random(12);
    $capture = capturedRequestFor($endpoint, $secret, 'secret='.$secret, [
        'x-secret' => [$secret],
    ]);
    actingAsCaptureUser($this, captureTestUser($secondTeamId));

    $this->get(route('captures.index', $endpoint))
        ->assertNotFound()
        ->assertDontSee($secret);
    $this->get(route('captures.show', [$endpoint, $capture]))
        ->assertNotFound()
        ->assertDontSee($secret);
});

it('denies capture inspection without provisioning a team membership', function (): void {
    $endpoint = captureEndpoint(captureTeamId(), 'No membership '.Str::random(12));
    $secret = 'no-membership-secret-'.Str::random(12);
    $capture = capturedRequestFor($endpoint, $secret, 'secret='.$secret, [
        'x-secret' => [$secret],
    ]);
    $user = captureTestUser(null);
    actingAsCaptureUser($this, $user);

    $this->get(route('captures.index', $endpoint))
        ->assertForbidden()
        ->assertDontSee($secret);
    $this->get(route('captures.show', [$endpoint, $capture]))
        ->assertForbidden()
        ->assertDontSee($secret);

    expect(DB::table('team_user')->where('user_id', $user->getKey())->count())->toBe(0);
});
