<?php

declare(strict_types=1);

use App\Jobs\DeliverCapturedRequest;
use App\Mcp\BobbinMcpScope;
use App\Mcp\BobbinMcpServer;
use App\Mcp\BobbinReadMcpServer;
use App\Mcp\BobbinWriteMcpServer;
use App\Models\CapturedRequest;
use App\Models\Delivery;
use App\Models\Endpoint;
use App\Models\McpReplayRequest;
use ArtisanBuild\BuiltForCloud\Console\ConsoleRole;
use ArtisanBuild\BuiltForCloud\Console\DelegatedActor;
use ArtisanBuild\BuiltForCloud\Credential;
use ArtisanBuild\BuiltForCloud\CredentialKind;
use ArtisanBuild\BuiltForCloud\CredentialPurpose;
use ArtisanBuild\BuiltForCloud\CredentialStatus;
use ArtisanBuild\BuiltForCloud\SubjectType;
use ArtisanBuild\BuiltForCloud\Testing\McpDelegatedTools;
use ArtisanBuild\BuiltForCloud\Testing\McpProductAdmission;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

beforeEach(function (): void {
    config()->set('app.key', 'base64:'.base64_encode(random_bytes(32)));
    Queue::fake();
});

function productMcpToken(): string
{
    $token = 'bobbin-mcp-'.Str::random(32);

    Credential::query()->create([
        'kind' => CredentialKind::Bearer,
        'purpose' => CredentialPurpose::Mcp,
        'subject_type' => SubjectType::Installation,
        'subject_ref' => 'bobbin-installation',
        'name' => 'product-mcp',
        'status' => CredentialStatus::Active,
        'secret_hash' => hash('sha256', $token),
    ]);

    return $token;
}

/** @param array<string, mixed>|object $arguments */
function productMcpCall(string $path, string $tool, array|object $arguments, string $token): TestResponse
{
    return test()->postJson($path, [
        'jsonrpc' => '2.0',
        'id' => Str::random(8),
        'method' => 'tools/call',
        'params' => ['name' => $tool, 'arguments' => $arguments],
    ], ['Authorization' => 'Bearer '.$token]);
}

function productMcpList(string $path, string $token): TestResponse
{
    return test()->postJson($path, [
        'jsonrpc' => '2.0',
        'id' => Str::random(8),
        'method' => 'tools/list',
    ], ['Authorization' => 'Bearer '.$token]);
}

/** @return array<string, mixed> */
function productMcpContent(TestResponse $response): array
{
    $response->assertOk();
    $text = $response->json('result.content.0.text');
    expect($text)->toBeString();

    try {
        return json_decode($text, true, flags: JSON_THROW_ON_ERROR);
    } catch (JsonException $exception) {
        throw new RuntimeException('Non-JSON MCP content: '.$text, previous: $exception);
    }
}

function productMcpEndpoint(int $teamId, array $attributes = []): Endpoint
{
    $endpoint = new Endpoint(['name' => $attributes['name'] ?? 'MCP endpoint '.Str::random(8)]);
    $endpoint->team_id = $teamId;
    $endpoint->fill($attributes);
    $endpoint->save();

    return $endpoint;
}

it('mounts exact effect doors and advertises four conforming tools without a destructive door', function (): void {
    $token = productMcpToken();

    McpDelegatedTools::assertConforms(BobbinMcpServer::class);
    McpDelegatedTools::assertConforms(BobbinReadMcpServer::class);
    McpDelegatedTools::assertConforms(BobbinWriteMcpServer::class);
    McpProductAdmission::assert();

    $meta = $this->getJson('/bfc/meta')->assertOk();
    expect($meta->json('capabilities'))->toContain('mcp-serve', 'mcp-delegated', 'mcp-effect-scoped')
        ->and($meta->json('endpoints'))->toBe(['mcp' => '/mcp', 'mcp_write' => '/mcp/write']);

    $readNames = productMcpList('/mcp', $token)->assertOk()->json('result.tools.*.name');
    $writeNames = productMcpList('/mcp/write', $token)->assertOk()->json('result.tools.*.name');
    sort($readNames);
    sort($writeNames);

    expect($readNames)->toBe(['captures', 'delivery_status', 'endpoints'])
        ->and($writeNames)->toBe(['replay_capture']);

    productMcpCall('/mcp', 'replay_capture', [], $token)->assertStatus(400);
    productMcpCall('/mcp/write', 'endpoints', [], $token)->assertStatus(400);
    productMcpCall('/mcp/write', 'destroy_everything', [], $token)->assertStatus(400);
    expect(Delivery::query()->count())->toBe(0);

    foreach ([...productMcpList('/mcp', $token)->json('result.tools'), ...productMcpList('/mcp/write', $token)->json('result.tools')] as $tool) {
        expect($tool['inputSchema']['additionalProperties'] ?? null)->toBeFalse();
    }
});

it('scopes and stably paginates every read while redacting content before serialization', function (): void {
    $token = productMcpToken();
    $teamId = (int) DB::table('teams')->value('id');
    $foreignTeamId = DB::table('teams')->insertGetId([
        'name' => 'Foreign team', 'created_at' => now(), 'updated_at' => now(),
    ]);
    $secret = 'sentinel-'.Str::random(24);
    $endpoint = productMcpEndpoint($teamId, [
        'name' => 'Scoped endpoint',
        'redacted_header_names' => ['x-secret'],
        'body_redaction' => true,
    ]);
    $destination = $endpoint->destinations()->create([
        'url' => 'https://receiver.example.test/hook', 'is_active' => true,
    ]);

    foreach (range(1, 105) as $index) {
        $capture = $endpoint->capturedRequests()->create([
            'method' => 'POST',
            'raw_query' => 'token='.$secret,
            'headers' => ['x-secret' => [$secret], 'x-visible' => ['visible-'.$index]],
            'body' => $secret,
            'received_at' => now()->addMicroseconds($index),
        ]);
        $destination->deliveries()->create(['captured_request_id' => $capture->id]);
        productMcpEndpoint($teamId);
    }

    $foreignEndpoint = productMcpEndpoint($foreignTeamId, ['name' => 'Foreign endpoint']);
    $foreignCapture = $foreignEndpoint->capturedRequests()->create([
        'method' => 'POST', 'raw_query' => '', 'headers' => [], 'body' => 'foreign', 'received_at' => now(),
    ]);
    $foreignDestination = $foreignEndpoint->destinations()->create([
        'url' => 'https://foreign.example.test', 'is_active' => true,
    ]);
    $foreignDestination->deliveries()->create(['captured_request_id' => $foreignCapture->id]);

    $endpointFirst = productMcpContent(productMcpCall('/mcp', 'endpoints', ['limit' => 100], $token));
    $endpointSecond = productMcpContent(productMcpCall('/mcp', 'endpoints', [
        'limit' => 100, 'cursor' => $endpointFirst['next_cursor'],
    ], $token));
    $endpointIds = array_column([...$endpointFirst['items'], ...$endpointSecond['items']], 'id');
    expect($endpointIds)->toHaveCount(106)
        ->and($endpointIds)->toBe(array_values(array_unique($endpointIds)))
        ->and($endpointIds)->not->toContain($foreignEndpoint->id);

    $captureFirstResponse = productMcpCall('/mcp', 'captures', ['endpoint_id' => $endpoint->id, 'limit' => 100], $token);
    $captureFirst = productMcpContent($captureFirstResponse);
    $captureSecond = productMcpContent(productMcpCall('/mcp', 'captures', [
        'endpoint_id' => $endpoint->id, 'limit' => 100, 'cursor' => $captureFirst['next_cursor'],
    ], $token));
    $captureIds = array_column([...$captureFirst['items'], ...$captureSecond['items']], 'id');
    expect($captureIds)->toHaveCount(105)->toBe(array_values(array_unique($captureIds)))
        ->and($captureFirst['items'][0]['headers']['x-secret'])->toBe([CapturedRequest::REDACTION_MARKER])
        ->and($captureFirst['items'][0]['body'])->toBe(CapturedRequest::REDACTION_MARKER)
        ->and($captureFirstResponse->getContent())->not->toContain($secret);

    $deliveryFirst = productMcpContent(productMcpCall('/mcp', 'delivery_status', [
        'endpoint_id' => $endpoint->id, 'statuses' => ['queued'], 'limit' => 100,
    ], $token));
    $deliverySecond = productMcpContent(productMcpCall('/mcp', 'delivery_status', [
        'endpoint_id' => $endpoint->id, 'statuses' => ['queued'], 'limit' => 100,
        'cursor' => $deliveryFirst['next_cursor'],
    ], $token));
    $deliveryIds = array_column([...$deliveryFirst['items'], ...$deliverySecond['items']], 'id');
    expect($deliveryIds)->toHaveCount(105)->toBe(array_values(array_unique($deliveryIds)));

    productMcpCall('/mcp', 'captures', ['endpoint_id' => $foreignEndpoint->id], $token)
        ->assertOk()->assertJsonPath('result.isError', true);
    productMcpCall('/mcp', 'delivery_status', ['endpoint_id' => $endpoint->id, 'capture_id' => $foreignCapture->id], $token)
        ->assertOk()->assertJsonPath('result.isError', true);
    productMcpCall('/mcp', 'delivery_status', ['endpoint_id' => $endpoint->id, 'destination_id' => $foreignDestination->id], $token)
        ->assertOk()->assertJsonPath('result.isError', true);
});

it('rejects malformed closed arguments lists enums cursors and limits', function (): void {
    $token = productMcpToken();
    $teamId = (int) DB::table('teams')->value('id');
    $endpoint = productMcpEndpoint($teamId);
    $cases = [
        ['endpoints', ['unknown' => true]],
        ['endpoints', ['limit' => 0]],
        ['endpoints', ['limit' => 101]],
        ['endpoints', ['cursor' => 'not-a-cursor']],
        ['delivery_status', ['endpoint_id' => $endpoint->id, 'statuses' => ['unknown']]],
        ['delivery_status', ['endpoint_id' => $endpoint->id, 'statuses' => ['queued' => true]]],
    ];

    foreach ($cases as [$tool, $arguments]) {
        $response = productMcpCall('/mcp', $tool, $arguments, $token);
        expect($response->getStatusCode())->toBeIn([200, 400]);

        if ($response->getStatusCode() === 200) {
            $response->assertJsonPath('result.isError', true);
        }
    }

    $numeric = productMcpCall('/mcp', 'endpoints', (object) [0 => 'numeric'], $token);
    expect($numeric->getStatusCode())->toBeIn([200, 400]);

    if ($numeric->getStatusCode() === 200) {
        $numeric->assertJsonPath('result.isError', true);
    }

    $statusSchema = collect(productMcpList('/mcp', $token)->json('result.tools'))
        ->firstWhere('name', 'delivery_status')['inputSchema'];
    expect($statusSchema['additionalProperties'])->toBeFalse()
        ->and($statusSchema['properties']['statuses']['type'])->toBe('array')
        ->and($statusSchema['properties']['statuses']['items']['enum'])->toBe([
            'queued', 'running', 'succeeded', 'failed',
        ]);
});

it('atomically suppresses replay duplicates conflicts on changed requests and reports uncertain dispatch', function (): void {
    $token = productMcpToken();
    $teamId = (int) DB::table('teams')->value('id');
    $endpoint = productMcpEndpoint($teamId);
    $capture = $endpoint->capturedRequests()->create([
        'method' => 'POST', 'raw_query' => '', 'headers' => [], 'body' => 'payload', 'received_at' => now(),
    ]);
    $otherCapture = $endpoint->capturedRequests()->create([
        'method' => 'POST', 'raw_query' => '', 'headers' => [], 'body' => 'other', 'received_at' => now(),
    ]);
    $destination = $endpoint->destinations()->create([
        'url' => 'https://receiver.example.test/hook', 'is_active' => true,
    ]);
    $arguments = [
        'endpoint_id' => $endpoint->id,
        'capture_id' => $capture->id,
        'destination_id' => $destination->id,
        'idempotency_key' => 'same-request',
    ];

    $accepted = productMcpContent(productMcpCall('/mcp/write', 'replay_capture', $arguments, $token));
    $duplicate = productMcpContent(productMcpCall('/mcp/write', 'replay_capture', $arguments, $token));
    expect($accepted['outcome'])->toBe('accepted')
        ->and($duplicate['outcome'])->toBe('already_accepted')
        ->and($duplicate['delivery']['id'])->toBe($accepted['delivery']['id'])
        ->and(Delivery::query()->count())->toBe(1)
        ->and(McpReplayRequest::query()->count())->toBe(1);
    Queue::assertPushedTimes(DeliverCapturedRequest::class, 1);

    $destination->update(['is_active' => false]);
    $duplicateAfterDeactivation = productMcpContent(productMcpCall('/mcp/write', 'replay_capture', $arguments, $token));
    expect($duplicateAfterDeactivation['outcome'])->toBe('already_accepted')
        ->and($duplicateAfterDeactivation['delivery']['id'])->toBe($accepted['delivery']['id']);
    $destination->update(['is_active' => true]);

    productMcpCall('/mcp/write', 'replay_capture', [
        ...$arguments, 'capture_id' => $otherCapture->id,
    ], $token)->assertOk()->assertJsonPath('result.isError', true);
    expect(Delivery::query()->count())->toBe(1);

    $foreignTeamId = DB::table('teams')->insertGetId([
        'name' => 'Foreign replay team', 'created_at' => now(), 'updated_at' => now(),
    ]);
    $foreignEndpoint = productMcpEndpoint($foreignTeamId);
    productMcpCall('/mcp/write', 'replay_capture', [
        ...$arguments, 'endpoint_id' => $foreignEndpoint->id, 'idempotency_key' => 'foreign',
    ], $token)->assertOk()->assertJsonPath('result.isError', true);

    $jobs = Mockery::mock(Dispatcher::class);
    $jobs->shouldReceive('dispatch')->once()->andThrow(new RuntimeException('uncertain queue transport'));
    app()->instance(Dispatcher::class, $jobs);
    $unknown = productMcpCall('/mcp/write', 'replay_capture', [
        ...$arguments, 'idempotency_key' => 'uncertain',
    ], $token)->assertOk()->assertJsonPath('result.isError', true);
    expect($unknown->getContent())->toContain('outcome_unknown')
        ->and(Delivery::query()->count())->toBe(2)
        ->and(McpReplayRequest::query()->count())->toBe(2);

    $retry = productMcpContent(productMcpCall('/mcp/write', 'replay_capture', [
        ...$arguments, 'idempotency_key' => 'uncertain',
    ], $token));
    expect($retry['outcome'])->toBe('already_accepted');
});

it('preserves the type-qualified delegated actor identifier', function (): void {
    $actor = DelegatedActor::query()->create([
        'identity_hash' => DelegatedActor::identityHash('https://scalpels.test', 'operator-1'),
        'issuer' => 'https://scalpels.test',
        'subject' => 'operator-1',
        'last_handoff_display_name' => 'Operator',
        'last_handoff_role' => ConsoleRole::Member,
    ]);
    request()->setUserResolver(static fn (): DelegatedActor => $actor);

    expect(app(BobbinMcpScope::class)->actorIdentifier())->toBe('bfc-console:'.$actor->id);
});
