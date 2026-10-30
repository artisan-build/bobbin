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

    $response = resolve(Kernel::class)->handle($request);

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
    'JSON with escaped slashes' => ['{"url":"https:\\/\\/example.test\\/hooks"}', 'application/json'],
    'literal backslashes' => ['C:\\temp\\new \\x00 \\\\', 'text/plain'],
]);

it('writes bodies to a PostgreSQL binary column in hex so a backslash cannot fail the insert', function (): void {
    $capture = new CapturedRequest;
    $capture->setConnection('pgsql');
    $capture->body = '{"url":"https:\\/\\/example.test"}';

    expect($capture->getAttributes()['body'])->toBe('\\x'.bin2hex('{"url":"https:\\/\\/example.test"}'));
});

it('reads a body the PostgreSQL driver returns as a stream back as its bytes', function (): void {
    $stream = fopen('php://memory', 'r+');
    fwrite($stream, "\x00binary\\body\xFF");

    $capture = (new CapturedRequest)->setRawAttributes(['body' => $stream]);

    expect($capture->body)->toBe("\x00binary\\body\xFF");
});

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

it('redacts every selected header value case-insensitively and preserves sentinel values', function (): void {
    $endpoint = captureEndpoint(captureTeamId(), 'Header redaction '.Str::random(12));
    $selectedFirst = 'selected-first-'.Str::random(12);
    $selectedSecond = 'selected-second-'.Str::random(12);
    $sentinelFirst = CapturedRequest::REDACTION_MARKER;
    $sentinelSecond = 'sentinel-second-'.Str::random(12);
    $endpoint->update(['redacted_header_names' => ['x-secret-token', 'x-absent-token']]);

    sendCapture($endpoint, 'POST', headers: [
        'X-SeCrEt-ToKeN' => [$selectedFirst, $selectedSecond],
        'X-Unselected-Sentinel' => [$sentinelFirst, $sentinelSecond],
    ])->assertNoContent();

    $capture = CapturedRequest::query()->sole();
    $storedHeaders = json_encode($capture->headers, JSON_THROW_ON_ERROR);

    expect($storedHeaders)->not->toContain($selectedFirst)
        ->not->toContain($selectedSecond)
        ->and($capture->headers['x-secret-token'])->toBe([
            CapturedRequest::REDACTION_MARKER,
            CapturedRequest::REDACTION_MARKER,
        ])
        ->and($capture->redacted_header_names)->toBe(['x-secret-token'])
        ->and(in_array('x-absent-token', $capture->redacted_header_names, true))->toBeFalse()
        ->and($capture->headers['x-unselected-sentinel'])->toBe([$sentinelFirst, $sentinelSecond]);
});

it('drops synthetic basic authentication headers before persistence', function (): void {
    $endpoint = captureEndpoint(captureTeamId(), 'Basic authentication '.Str::random(12));
    $username = 'basic-user-'.Str::random(12);
    $password = 'basic-password-'.Str::random(12);
    $endpoint->update(['redacted_header_names' => ['authorization']]);
    $request = Request::create('/hooks/'.$endpoint->public_id, 'POST', server: [
        'HTTP_AUTHORIZATION' => 'Basic '.base64_encode($username.':'.$password),
    ]);

    TestResponse::fromBaseResponse(resolve(Kernel::class)->handle($request))->assertNoContent();

    $capture = CapturedRequest::query()->sole();
    $storedHeaders = json_encode($capture->headers, JSON_THROW_ON_ERROR);

    expect($storedHeaders)->not->toContain($username)
        ->not->toContain($password)
        ->and(array_key_exists('php-auth-user', $capture->headers))->toBeFalse()
        ->and(array_key_exists('php-auth-pw', $capture->headers))->toBeFalse()
        ->and(array_key_exists('php-auth-digest', $capture->headers))->toBeFalse()
        ->and($capture->headers['authorization'])->toBe([CapturedRequest::REDACTION_MARKER])
        ->and($capture->redacted_header_names)->toBe(['authorization']);
});

it('drops synthetic authentication keys even when authorization stays unredacted', function (): void {
    $endpoint = captureEndpoint(captureTeamId(), 'Unredacted authorization '.Str::random(12));
    $username = 'plain-user-'.Str::random(12);
    $password = 'plain-password-'.Str::random(12);
    $wireAuthorization = 'Basic '.base64_encode($username.':'.$password);
    $request = Request::create('/hooks/'.$endpoint->public_id, 'POST', server: [
        'HTTP_AUTHORIZATION' => $wireAuthorization,
    ]);

    TestResponse::fromBaseResponse(resolve(Kernel::class)->handle($request))->assertNoContent();

    $capture = CapturedRequest::query()->sole();
    $storedHeaders = json_encode($capture->headers, JSON_THROW_ON_ERROR);

    expect($storedHeaders)
        ->not->toContain($username)
        ->not->toContain($password)
        ->not->toContain('php-auth-user')
        ->not->toContain('php-auth-pw')
        ->not->toContain('php-auth-digest')
        ->and(array_key_exists('php-auth-user', $capture->headers))->toBeFalse()
        ->and(array_key_exists('php-auth-pw', $capture->headers))->toBeFalse()
        ->and(array_key_exists('php-auth-digest', $capture->headers))->toBeFalse()
        ->and($capture->headers['authorization'])->toBe([$wireAuthorization])
        ->and($capture->redacted_header_names)->toBe([]);
});

it('drops the synthetic digest authentication header before persistence', function (): void {
    $endpoint = captureEndpoint(captureTeamId(), 'Digest authentication '.Str::random(12));
    $digestResponse = 'digest-response-'.Str::random(12);
    $endpoint->update(['redacted_header_names' => ['authorization']]);
    $request = Request::create('/hooks/'.$endpoint->public_id, 'POST', server: [
        'HTTP_AUTHORIZATION' => 'Digest username="digest-user", realm="bobbin", nonce="nonce", uri="/", response="'.$digestResponse.'"',
    ]);

    TestResponse::fromBaseResponse(resolve(Kernel::class)->handle($request))->assertNoContent();

    $capture = CapturedRequest::query()->sole();
    $storedHeaders = json_encode($capture->headers, JSON_THROW_ON_ERROR);

    expect($storedHeaders)->not->toContain($digestResponse)
        ->and(array_key_exists('php-auth-digest', $capture->headers))->toBeFalse()
        ->and($capture->headers['authorization'])->toBe([CapturedRequest::REDACTION_MARKER])
        ->and($capture->redacted_header_names)->toBe(['authorization']);
});

it('redacts enabled bodies before persistence and preserves disabled body bytes', function (): void {
    $endpoint = captureEndpoint(captureTeamId(), 'Body redaction '.Str::random(12));
    $redactedBody = "\x00enabled-secret-".Str::random(12)."\xFF";
    $preservedBody = "\x00disabled-secret-".Str::random(12)."\xFE";
    $endpoint->update(['body_redaction' => true]);

    sendCapture($endpoint, 'POST', $redactedBody)->assertNoContent();
    $redacted = CapturedRequest::query()->sole();

    expect($redacted->body)->toBe(CapturedRequest::REDACTION_MARKER)
        ->not->toContain($redactedBody)
        ->and($redacted->body_redacted)->toBeTrue();

    $endpoint->update(['body_redaction' => false]);
    sendCapture($endpoint, 'POST', $preservedBody)->assertNoContent();
    $preserved = CapturedRequest::query()->latest('id')->firstOrFail();

    expect($preserved->body)->toBe($preservedBody)
        ->and($preserved->body_redacted)->toBeFalse();
});

it('applies changed redaction settings only to subsequent captures', function (): void {
    $endpoint = captureEndpoint(captureTeamId(), 'Prospective redaction '.Str::random(12));
    $earlierBody = 'earlier-body-'.Str::random(12);
    $earlierHeader = 'earlier-header-'.Str::random(12);
    $laterBody = 'later-body-'.Str::random(12);
    $laterHeader = 'later-header-'.Str::random(12);

    sendCapture($endpoint, 'POST', $earlierBody, headers: [
        'X-Prospective-Secret' => [$earlierHeader],
    ])->assertNoContent();
    $earlier = CapturedRequest::query()->sole();
    $earlierAttributes = $earlier->getRawOriginal();

    $endpoint->update([
        'redacted_header_names' => ['x-prospective-secret'],
        'body_redaction' => true,
    ]);
    sendCapture($endpoint, 'POST', $laterBody, headers: [
        'X-Prospective-Secret' => [$laterHeader],
    ])->assertNoContent();

    $earlier->refresh();
    $later = CapturedRequest::query()->latest('id')->firstOrFail();

    expect($earlier->getRawOriginal())->toBe($earlierAttributes)
        ->and($earlier->body)->toBe($earlierBody)
        ->and($earlier->headers['x-prospective-secret'])->toBe([$earlierHeader])
        ->and($earlier->redacted_header_names)->toBe([])
        ->and($earlier->body_redacted)->toBeFalse()
        ->and($later->body)->toBe(CapturedRequest::REDACTION_MARKER)
        ->and($later->headers['x-prospective-secret'])->toBe([CapturedRequest::REDACTION_MARKER])
        ->and(json_encode($later->headers, JSON_THROW_ON_ERROR))->not->toContain($laterHeader)
        ->and($later->redacted_header_names)->toBe(['x-prospective-secret'])
        ->and($later->body_redacted)->toBeTrue();
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
    $otherCapture = capturedRequestFor($otherEndpoint, $otherSecret, 'other-query=1');
    actingAsCaptureUser($this, $user);

    $index = $this->get(route('captures.index', $endpoint))->assertOk();
    expect(substr_count($index->getContent(), 'data-testid="captures-index"'))->toBe(1);
    $index->assertSeeText($endpoint->name)
        ->assertSeeText($querySecret)
        ->assertDontSee($otherSecret)
        ->assertDontSee('other-query=1')
        ->assertDontSee(route('captures.show', [$endpoint, $otherCapture]));

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

it('visibly labels only redactions recorded in capture metadata', function (): void {
    $teamId = captureTeamId();
    $user = captureTestUser($teamId);
    $endpoint = captureEndpoint($teamId, 'Redaction labels '.Str::random(12));
    $endpoint->update([
        'redacted_header_names' => ['x-labelled-secret'],
        'body_redaction' => true,
    ]);
    sendCapture($endpoint, 'POST', 'display-secret-'.Str::random(12), headers: [
        'X-Labelled-Secret' => ['header-secret-'.Str::random(12)],
        'X-Marker-Collision' => [CapturedRequest::REDACTION_MARKER],
    ])->assertNoContent();
    $capture = CapturedRequest::query()->sole();
    actingAsCaptureUser($this, $user);

    $detail = $this->get(route('captures.show', [$endpoint, $capture]))->assertOk();

    expect(substr_count($detail->getContent(), 'data-testid="capture-header-redaction"'))->toBe(1)
        ->and(substr_count($detail->getContent(), 'data-testid="capture-body-redaction"'))->toBe(1);
    $detail->assertSeeText('Body bytes were removed before this capture was persisted.')
        ->assertSeeText('x-marker-collision')
        ->assertSeeText(CapturedRequest::REDACTION_MARKER);
});

it('does not disclose captures to a signed-out request', function (): void {
    $endpoint = captureEndpoint(captureTeamId(), 'Signed out '.Str::random(12));
    $secret = 'signed-out-secret-'.Str::random(12);
    $capture = capturedRequestFor($endpoint, $secret, 'secret='.$secret, [
        'x-secret' => [$secret],
    ]);

    $this->get(route('captures.index', $endpoint))
        ->assertRedirect(route('bfc.login', ['intended' => route('captures.index', $endpoint, false)]))
        ->assertDontSee($secret);
    $this->get(route('captures.show', [$endpoint, $capture]))
        ->assertRedirect(route('bfc.login', ['intended' => route('captures.show', [$endpoint, $capture], false)]))
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
