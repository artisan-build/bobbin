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
use App\Services\ReplaySettlementFaultInjector;
use ArtisanBuild\BuiltForCloud\Console\ConsoleRole;
use ArtisanBuild\BuiltForCloud\Console\DelegatedActor;
use ArtisanBuild\BuiltForCloud\Credential;
use ArtisanBuild\BuiltForCloud\CredentialKind;
use ArtisanBuild\BuiltForCloud\CredentialPurpose;
use ArtisanBuild\BuiltForCloud\CredentialStatus;
use ArtisanBuild\BuiltForCloud\SubjectType;
use ArtisanBuild\BuiltForCloud\Testing\McpDelegatedTools;
use ArtisanBuild\BuiltForCloud\Testing\McpProductAdmission;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

beforeEach(function (): void {
    config()->set('app.key', 'base64:'.base64_encode(random_bytes(32)));
    Queue::fake();
});

function productMcpToken(string $subjectRef = 'bobbin-installation'): string
{
    $token = 'bobbin-mcp-'.Str::random(32);

    Credential::query()->create([
        'kind' => CredentialKind::Bearer,
        'purpose' => CredentialPurpose::Mcp,
        'subject_type' => SubjectType::Installation,
        'subject_ref' => $subjectRef,
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

/** @return array{token: string, arguments: array<string, int|string>, delivery: Delivery, receipt: McpReplayRequest} */
function pendingProductMcpReplay(string $idempotencyKey): array
{
    $token = productMcpToken();
    $credentialId = Credential::query()->latest('id')->firstOrFail()->id;
    $teamId = (int) DB::table('teams')->value('id');
    $endpoint = productMcpEndpoint($teamId);
    $capture = $endpoint->capturedRequests()->create([
        'method' => 'POST', 'raw_query' => '', 'headers' => [], 'body' => 'payload', 'received_at' => now(),
    ]);
    $destination = $endpoint->destinations()->create([
        'url' => 'https://receiver.example.test/hook', 'is_active' => true,
    ]);
    $delivery = $destination->deliveries()->create(['captured_request_id' => $capture->id]);
    $arguments = [
        'endpoint_id' => $endpoint->id,
        'capture_id' => $capture->id,
        'destination_id' => $destination->id,
        'idempotency_key' => $idempotencyKey,
    ];
    $receipt = McpReplayRequest::query()->create([
        'team_id' => $teamId,
        'actor_identifier' => 'credential:'.$credentialId,
        'idempotency_key' => $idempotencyKey,
        'request_hash' => hash('sha256', json_encode([
            'team_id' => $teamId,
            'endpoint_id' => $endpoint->id,
            'capture_id' => $capture->id,
            'destination_id' => $destination->id,
        ], JSON_THROW_ON_ERROR)),
        'delivery_id' => $delivery->id,
        'delivery_capture_id' => $capture->id,
        'delivery_destination_id' => $destination->id,
        'delivery_status' => $delivery->status,
        'outcome' => McpReplayRequest::OUTCOME_IN_PROGRESS,
        'lease_expires_at' => now()->addSeconds(McpReplayRequest::LEASE_SECONDS),
    ]);

    return compact('token', 'arguments', 'delivery', 'receipt');
}

/** @return array{token: string, arguments: array<string, int|string>, delivery: Delivery, receipt: McpReplayRequest, job: DeliverCapturedRequest} */
function queuedPendingProductMcpReplay(string $idempotencyKey): array
{
    $token = productMcpToken();
    $teamId = (int) DB::table('teams')->value('id');
    $endpoint = productMcpEndpoint($teamId);
    $capture = $endpoint->capturedRequests()->create([
        'method' => 'POST', 'raw_query' => '', 'headers' => [], 'body' => 'payload', 'received_at' => now(),
    ]);
    $destination = $endpoint->destinations()->create([
        'url' => 'https://receiver.example.test/hook', 'is_active' => true,
    ]);
    $arguments = [
        'endpoint_id' => $endpoint->id,
        'capture_id' => $capture->id,
        'destination_id' => $destination->id,
        'idempotency_key' => $idempotencyKey,
    ];
    DB::unprepared(<<<'SQL'
        CREATE TRIGGER fail_replay_acceptance
        BEFORE UPDATE OF outcome ON mcp_replay_requests
        WHEN NEW.outcome = 'accepted'
        BEGIN
            SELECT RAISE(FAIL, 'forced acceptance write failure');
        END
        SQL);

    try {
        $response = productMcpContent(productMcpCall('/mcp/write', 'replay_capture', $arguments, $token));
    } finally {
        DB::unprepared('DROP TRIGGER fail_replay_acceptance');
    }

    $receipt = McpReplayRequest::query()->where('idempotency_key', $idempotencyKey)->firstOrFail();
    $delivery = Delivery::query()->findOrFail($receipt->delivery_id);
    $job = Queue::pushed(DeliverCapturedRequest::class)
        ->first(fn (DeliverCapturedRequest $queued): bool => $queued->replayRequestId === $receipt->id);
    expect($response['outcome'])->toBe('in_progress')
        ->and($job)->toBeInstanceOf(DeliverCapturedRequest::class)
        ->and(Queue::pushed(DeliverCapturedRequest::class)
            ->filter(fn (DeliverCapturedRequest $queued): bool => $queued->replayRequestId === $receipt->id))
        ->toHaveCount(1);

    return compact('token', 'arguments', 'delivery', 'receipt', 'job');
}

it('mounts exact effect doors and advertises four conforming tools without a destructive door', function (): void {
    $token = productMcpToken();
    $teamId = (int) DB::table('teams')->value('id');
    $endpoint = productMcpEndpoint($teamId);
    $capture = $endpoint->capturedRequests()->create([
        'method' => 'POST', 'raw_query' => '', 'headers' => [], 'body' => 'payload', 'received_at' => now(),
    ]);
    $destination = $endpoint->destinations()->create([
        'url' => 'https://receiver.example.test/hook', 'is_active' => true,
    ]);

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

    productMcpCall('/mcp', 'replay_capture', [
        'endpoint_id' => $endpoint->id,
        'capture_id' => $capture->id,
        'destination_id' => $destination->id,
        'idempotency_key' => 'wrong-door',
    ], $token)->assertStatus(400);
    productMcpCall('/mcp/write', 'endpoints', ['limit' => 1], $token)->assertStatus(400);
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

    $snapshotEndpoint = productMcpEndpoint($teamId, ['name' => 'Snapshot controls']);
    $snapshotEndpoint->capturedRequests()->create([
        'method' => 'POST',
        'raw_query' => 'token='.$secret,
        'headers' => ['x-snapshot' => [$secret]],
        'body' => $secret,
        'redacted_header_names' => ['X-Snapshot'],
        'body_redacted' => true,
        'received_at' => now(),
    ]);
    $snapshotEndpoint->capturedRequests()->create([
        'method' => 'POST',
        'raw_query' => '',
        'headers' => ['x-visible' => ['snapshot-visible']],
        'body' => 'visible-body',
        'received_at' => now()->addSecond(),
    ]);

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
    expect($endpointIds)->toHaveCount(107)
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
        ->and($captureFirst['items'][0]['headers']['x-visible'])->toBe(['visible-1'])
        ->and($captureFirst['items'][0]['body'])->toBe(CapturedRequest::REDACTION_MARKER)
        ->and($captureFirstResponse->getContent())->not->toContain($secret);

    $snapshotResponse = productMcpCall('/mcp', 'captures', ['endpoint_id' => $snapshotEndpoint->id], $token);
    $snapshotContent = productMcpContent($snapshotResponse);
    expect($snapshotContent['items'][0]['headers']['x-snapshot'])->toBe([CapturedRequest::REDACTION_MARKER])
        ->and($snapshotContent['items'][0]['body'])->toBe(CapturedRequest::REDACTION_MARKER)
        ->and($snapshotContent['items'][1]['headers']['x-visible'])->toBe(['snapshot-visible'])
        ->and($snapshotContent['items'][1]['body'])->toBe('visible-body')
        ->and($snapshotResponse->getContent())->not->toContain($secret);

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
        ['endpoints', ['limit' => '5']],
        ['endpoints', ['limit' => true]],
        ['endpoints', ['cursor' => 'not-a-cursor']],
        ['delivery_status', ['endpoint_id' => $endpoint->id, 'statuses' => ['unknown']]],
        ['delivery_status', ['endpoint_id' => $endpoint->id, 'statuses' => ['queued', 'queued']]],
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

it('atomically suppresses replay duplicates and keeps unrecoverable dispatch terminally unknown', function (): void {
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

    $changedRequest = productMcpCall('/mcp/write', 'replay_capture', [
        ...$arguments, 'capture_id' => $otherCapture->id,
    ], $token)->assertOk()->assertJsonPath('result.isError', true);
    expect(productMcpContent($changedRequest))->toBe([
        'outcome' => 'refused',
        'reason' => 'idempotency_conflict',
    ])->and(Delivery::query()->count())->toBe(1);

    $foreignTeamId = DB::table('teams')->insertGetId([
        'name' => 'Foreign replay team', 'created_at' => now(), 'updated_at' => now(),
    ]);
    $foreignEndpoint = productMcpEndpoint($foreignTeamId);
    $foreign = productMcpCall('/mcp/write', 'replay_capture', [
        ...$arguments, 'endpoint_id' => $foreignEndpoint->id, 'idempotency_key' => 'foreign',
    ], $token)->assertOk()->assertJsonPath('result.isError', true);
    expect(productMcpContent($foreign))->toBe([
        'outcome' => 'refused',
        'reason' => 'not_found',
    ]);

    $jobs = Mockery::mock(Dispatcher::class);
    $jobs->shouldReceive('dispatch')->once()->andThrow(new RuntimeException('uncertain queue transport'));
    app()->instance(Dispatcher::class, $jobs);
    $unknown = productMcpCall('/mcp/write', 'replay_capture', [
        ...$arguments, 'idempotency_key' => 'uncertain',
    ], $token)->assertOk()->assertJsonPath('result.isError', true);
    $unknownContent = productMcpContent($unknown);
    $unknownDeliveryId = (int) Delivery::query()->latest('id')->value('id');
    expect($unknownContent)->toBe([
        'outcome' => 'outcome_unknown',
        'delivery_id' => $unknownDeliveryId,
    ])
        ->and(Delivery::query()->count())->toBe(2)
        ->and(McpReplayRequest::query()->count())->toBe(2);

    $retry = productMcpCall('/mcp/write', 'replay_capture', [
        ...$arguments, 'idempotency_key' => 'uncertain',
    ], $token)->assertOk()->assertJsonPath('result.isError', true);
    expect(productMcpContent($retry))->toBe($unknownContent)
        ->and(McpReplayRequest::query()->where('idempotency_key', 'uncertain')->firstOrFail()->outcome)
        ->toBe(McpReplayRequest::OUTCOME_UNKNOWN);

    $laterRetry = productMcpCall('/mcp/write', 'replay_capture', [
        ...$arguments, 'idempotency_key' => 'uncertain',
    ], $token)->assertOk()->assertJsonPath('result.isError', true);
    expect(productMcpContent($laterRetry))->toBe($unknownContent);
});

it('reports a durable in-progress replay without redispatching it', function (): void {
    ['token' => $token, 'arguments' => $arguments, 'delivery' => $delivery, 'receipt' => $receipt] = pendingProductMcpReplay('still-dispatching');

    $response = productMcpCall('/mcp/write', 'replay_capture', $arguments, $token)
        ->assertOk()
        ->assertJsonPath('result.isError', false);

    expect(productMcpContent($response))->toBe([
        'outcome' => 'in_progress',
        'delivery' => [
            'id' => $delivery->id,
            'capture_id' => $delivery->captured_request_id,
            'destination_id' => $delivery->destination_id,
            'status' => 'queued',
        ],
    ]);
    Queue::assertNothingPushed();

    (new DeliverCapturedRequest($delivery->id, $receipt->id))->failed(new RuntimeException('settlement exhausted'));

    $exhausted = productMcpCall('/mcp/write', 'replay_capture', $arguments, $token)
        ->assertOk()
        ->assertJsonPath('result.isError', false);
    expect(productMcpContent($exhausted))->toBe([
        'outcome' => 'already_accepted',
        'delivery' => [
            'id' => $delivery->id,
            'capture_id' => $delivery->captured_request_id,
            'destination_id' => $delivery->destination_id,
            'status' => Delivery::STATUS_QUEUED,
        ],
    ])->and($receipt->refresh()->outcome)->toBe(McpReplayRequest::OUTCOME_ACCEPTED)
        ->and($delivery->refresh()->status)->toBe(Delivery::STATUS_FAILED);
    $deliveryStatus = productMcpContent(productMcpCall('/mcp', 'delivery_status', [
        'endpoint_id' => $arguments['endpoint_id'],
        'capture_id' => $arguments['capture_id'],
    ], $token));
    expect($deliveryStatus['items'][0]['status'])->toBe(Delivery::STATUS_FAILED);
    Queue::assertNothingPushed();
});

it('reconciles every failed terminal settlement from durable evidence without redispatching', function (): void {
    $unknownToken = productMcpToken();
    $teamId = (int) DB::table('teams')->value('id');
    $endpoint = productMcpEndpoint($teamId);
    $capture = $endpoint->capturedRequests()->create([
        'method' => 'POST', 'raw_query' => '', 'headers' => [], 'body' => 'payload', 'received_at' => now(),
    ]);
    $destination = $endpoint->destinations()->create([
        'url' => 'https://receiver.example.test/hook', 'is_active' => true,
    ]);
    $unknownArguments = [
        'endpoint_id' => $endpoint->id,
        'capture_id' => $capture->id,
        'destination_id' => $destination->id,
        'idempotency_key' => 'faulted-dispatch-unknown',
    ];
    app()->instance(ReplaySettlementFaultInjector::class, new class extends ReplaySettlementFaultInjector
    {
        public function beforeDispatchExceptionSettlement(): void
        {
            throw new RuntimeException('forced dispatch exception settlement failure');
        }
    });
    $originalJobs = resolve(Dispatcher::class);
    $jobs = Mockery::mock(Dispatcher::class);
    $jobs->shouldReceive('dispatch')->once()->andThrow(new RuntimeException('forced queue transport failure'));
    app()->instance(Dispatcher::class, $jobs);

    productMcpCall('/mcp/write', 'replay_capture', $unknownArguments, $unknownToken)
        ->assertOk()
        ->assertJsonPath('result.isError', true);
    $unknownReceipt = McpReplayRequest::query()->where('idempotency_key', 'faulted-dispatch-unknown')->firstOrFail();
    expect($unknownReceipt->outcome)->toBe(McpReplayRequest::OUTCOME_IN_PROGRESS)
        ->and($unknownReceipt->queue_accepted_at)->toBeNull();

    app()->instance(Dispatcher::class, $originalJobs);
    ['token' => $failedToken, 'arguments' => $failedArguments, 'delivery' => $failedDelivery, 'receipt' => $failedReceipt, 'job' => $failedJob] = queuedPendingProductMcpReplay('faulted-failed-job');
    app()->instance(ReplaySettlementFaultInjector::class, new class extends ReplaySettlementFaultInjector
    {
        public function beforeFailedJobSettlement(): void
        {
            throw new RuntimeException('forced failed job settlement failure');
        }
    });
    expect(fn () => $failedJob->failed(new RuntimeException('job exhausted')))
        ->toThrow(RuntimeException::class, 'forced failed job settlement failure');
    expect($failedReceipt->refresh()->outcome)->toBe(McpReplayRequest::OUTCOME_IN_PROGRESS)
        ->and($failedReceipt->queue_accepted_at)->not->toBeNull()
        ->and($failedDelivery->refresh()->status)->toBe(Delivery::STATUS_FAILED);

    ['token' => $handledToken, 'arguments' => $handledArguments, 'delivery' => $handledDelivery, 'receipt' => $handledReceipt, 'job' => $handledJob] = queuedPendingProductMcpReplay('faulted-handled-job');
    app()->instance(ReplaySettlementFaultInjector::class, new class extends ReplaySettlementFaultInjector
    {
        public function beforeHandledJobSettlement(): void
        {
            throw new RuntimeException('forced handled job settlement failure');
        }
    });
    expect(fn () => $handledJob->handle())
        ->toThrow(RuntimeException::class, 'forced handled job settlement failure');
    expect($handledReceipt->refresh()->outcome)->toBe(McpReplayRequest::OUTCOME_IN_PROGRESS)
        ->and($handledReceipt->queue_accepted_at)->not->toBeNull()
        ->and($handledDelivery->refresh()->status)->toBe(Delivery::STATUS_QUEUED);

    McpReplayRequest::query()->whereKey([
        $unknownReceipt->id,
        $failedReceipt->id,
        $handledReceipt->id,
    ])->update(['lease_expires_at' => now()->subSecond()]);

    expect(Artisan::call('mcp-replays:reconcile'))->toBe(0)
        ->and(Artisan::call('mcp-replays:reconcile'))->toBe(0)
        ->and($unknownReceipt->refresh()->outcome)->toBe(McpReplayRequest::OUTCOME_UNKNOWN)
        ->and($failedReceipt->refresh()->outcome)->toBe(McpReplayRequest::OUTCOME_ACCEPTED)
        ->and($handledReceipt->refresh()->outcome)->toBe(McpReplayRequest::OUTCOME_ACCEPTED)
        ->and(McpReplayRequest::query()->where('outcome', McpReplayRequest::OUTCOME_IN_PROGRESS)->count())->toBe(0);

    $unknownRetry = productMcpCall('/mcp/write', 'replay_capture', $unknownArguments, $unknownToken)
        ->assertOk()->assertJsonPath('result.isError', true);
    $failedRetry = productMcpContent(productMcpCall('/mcp/write', 'replay_capture', $failedArguments, $failedToken));
    $handledRetry = productMcpContent(productMcpCall('/mcp/write', 'replay_capture', $handledArguments, $handledToken));
    expect(productMcpContent($unknownRetry))->toBe([
        'outcome' => 'outcome_unknown',
        'delivery_id' => $unknownReceipt->delivery_id,
    ])->and($failedRetry['outcome'])->toBe('already_accepted')
        ->and($failedRetry['delivery']['id'])->toBe($failedReceipt->delivery_id)
        ->and($handledRetry['outcome'])->toBe('already_accepted')
        ->and($handledRetry['delivery']['id'])->toBe($handledReceipt->delivery_id)
        ->and(Queue::pushed(DeliverCapturedRequest::class)
            ->filter(fn (DeliverCapturedRequest $queued): bool => $queued->replayRequestId === $failedReceipt->id))
        ->toHaveCount(1)
        ->and(Queue::pushed(DeliverCapturedRequest::class)
            ->filter(fn (DeliverCapturedRequest $queued): bool => $queued->replayRequestId === $handledReceipt->id))
        ->toHaveCount(1);
});

it('reconciles expired leases from delivery evidence and never changes terminal receipts', function (): void {
    ['delivery' => $acceptedDelivery, 'receipt' => $acceptedReceipt] = pendingProductMcpReplay('delivery-evidence');
    ['receipt' => $unknownReceipt] = pendingProductMcpReplay('no-delivery-evidence');
    ['receipt' => $activeReceipt] = pendingProductMcpReplay('active-lease');
    ['receipt' => $terminalReceipt] = pendingProductMcpReplay('terminal-is-immutable');
    $acceptedDelivery->update([
        'status' => Delivery::STATUS_RUNNING,
        'attempt_count' => 1,
        'started_at' => now(),
    ]);
    McpReplayRequest::query()->whereKey([$acceptedReceipt->id, $unknownReceipt->id])->update([
        'lease_expires_at' => now()->subSecond(),
    ]);
    $terminalReceipt->update([
        'outcome' => McpReplayRequest::OUTCOME_UNKNOWN,
        'lease_expires_at' => now()->subSecond(),
    ]);

    expect(Artisan::call('mcp-replays:reconcile'))->toBe(0)
        ->and($acceptedReceipt->refresh()->outcome)->toBe(McpReplayRequest::OUTCOME_ACCEPTED)
        ->and($unknownReceipt->refresh()->outcome)->toBe(McpReplayRequest::OUTCOME_UNKNOWN)
        ->and($activeReceipt->refresh()->outcome)->toBe(McpReplayRequest::OUTCOME_IN_PROGRESS)
        ->and($terminalReceipt->refresh()->outcome)->toBe(McpReplayRequest::OUTCOME_UNKNOWN)
        ->and($acceptedReceipt->lease_expires_at)->toBeNull()
        ->and($unknownReceipt->lease_expires_at)->toBeNull()
        ->and($activeReceipt->lease_expires_at)->not->toBeNull()
        ->and($terminalReceipt->lease_expires_at)->not->toBeNull();

    $event = collect(resolve(Schedule::class)->events())
        ->first(fn ($event): bool => str_contains((string) $event->command, 'mcp-replays:reconcile'));
    expect($event)->not->toBeNull()
        ->and($event->expression)->toBe('* * * * *');
});

it('lets the queued delivery settle a confirmed failed acceptance write without redispatching', function (): void {
    $token = productMcpToken();
    $teamId = (int) DB::table('teams')->value('id');
    $endpoint = productMcpEndpoint($teamId);
    $capture = $endpoint->capturedRequests()->create([
        'method' => 'POST', 'raw_query' => '', 'headers' => [], 'body' => 'payload', 'received_at' => now(),
    ]);
    $destination = $endpoint->destinations()->create([
        'url' => 'https://receiver.example.test/hook', 'is_active' => true,
    ]);
    $arguments = [
        'endpoint_id' => $endpoint->id,
        'capture_id' => $capture->id,
        'destination_id' => $destination->id,
        'idempotency_key' => 'acceptance-write-failure',
    ];
    DB::unprepared(<<<'SQL'
        CREATE TRIGGER fail_replay_acceptance
        BEFORE UPDATE OF outcome ON mcp_replay_requests
        WHEN NEW.outcome = 'accepted'
        BEGIN
            SELECT RAISE(FAIL, 'forced acceptance write failure');
        END
        SQL);

    $initial = productMcpContent(productMcpCall('/mcp/write', 'replay_capture', $arguments, $token));
    $receipt = McpReplayRequest::query()->where('idempotency_key', 'acceptance-write-failure')->firstOrFail();

    expect($initial['outcome'])->toBe('in_progress')
        ->and($receipt->outcome)->toBe(McpReplayRequest::OUTCOME_IN_PROGRESS);
    Queue::assertPushedTimes(DeliverCapturedRequest::class, 1);

    $whileQueued = productMcpContent(productMcpCall('/mcp/write', 'replay_capture', $arguments, $token));
    expect($whileQueued['outcome'])->toBe('in_progress');
    Queue::assertPushedTimes(DeliverCapturedRequest::class, 1);

    DB::unprepared('DROP TRIGGER fail_replay_acceptance');
    $deliveryJob = Queue::pushed(DeliverCapturedRequest::class)->sole();
    expect($deliveryJob->replayRequestId)->toBe($receipt->id);

    Delivery::query()->whereKey($receipt->delivery_id)->update([
        'status' => Delivery::STATUS_SUCCEEDED,
        'completed_at' => now(),
    ]);
    $deliveryJob->handle();

    $settled = productMcpContent(productMcpCall('/mcp/write', 'replay_capture', $arguments, $token));
    expect($receipt->refresh()->outcome)->toBe(McpReplayRequest::OUTCOME_ACCEPTED)
        ->and($settled['outcome'])->toBe('already_accepted')
        ->and($settled['delivery'])->toBe($initial['delivery']);
    Queue::assertPushedTimes(DeliverCapturedRequest::class, 1);
});

it('keeps replay receipts and consumed keys after capture retention pruning', function (): void {
    $token = productMcpToken();
    $teamId = (int) DB::table('teams')->value('id');
    $endpoint = productMcpEndpoint($teamId, ['retention_days' => 1]);
    $capture = $endpoint->capturedRequests()->create([
        'method' => 'POST',
        'raw_query' => 'secret=must-not-enter-ledger',
        'headers' => ['authorization' => ['must-not-enter-ledger']],
        'body' => 'must-not-enter-ledger',
        'received_at' => now()->subDays(2),
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
        'idempotency_key' => 'retained-receipt',
    ];

    $accepted = productMcpContent(productMcpCall('/mcp/write', 'replay_capture', $arguments, $token));
    expect(Artisan::call('captures:prune'))->toBe(0)
        ->and(CapturedRequest::query()->whereKey($capture->id)->exists())->toBeFalse()
        ->and(Delivery::query()->whereKey($accepted['delivery']['id'])->exists())->toBeFalse()
        ->and(McpReplayRequest::query()->where('idempotency_key', 'retained-receipt')->value('outcome'))
        ->toBe(McpReplayRequest::OUTCOME_ACCEPTED);

    $retry = productMcpContent(productMcpCall('/mcp/write', 'replay_capture', $arguments, $token));
    expect($retry['outcome'])->toBe('already_accepted')
        ->and($retry['delivery'])->toBe($accepted['delivery']);

    $conflict = productMcpCall('/mcp/write', 'replay_capture', [
        ...$arguments, 'capture_id' => $otherCapture->id,
    ], $token)->assertOk()->assertJsonPath('result.isError', true);
    expect(productMcpContent($conflict))->toBe([
        'outcome' => 'refused',
        'reason' => 'idempotency_conflict',
    ])
        ->and(json_encode(McpReplayRequest::query()->firstOrFail()->toArray(), JSON_THROW_ON_ERROR))
        ->not->toContain('must-not-enter-ledger');
    Queue::assertPushedTimes(DeliverCapturedRequest::class, 1);
});

it('partitions replay idempotency by the authenticated actor', function (): void {
    $firstToken = productMcpToken('installation-a');
    $secondToken = productMcpToken('installation-b');
    $teamId = (int) DB::table('teams')->value('id');
    $endpoint = productMcpEndpoint($teamId);
    $capture = $endpoint->capturedRequests()->create([
        'method' => 'POST', 'raw_query' => '', 'headers' => [], 'body' => 'payload', 'received_at' => now(),
    ]);
    $destination = $endpoint->destinations()->create([
        'url' => 'https://receiver.example.test/hook', 'is_active' => true,
    ]);
    $arguments = [
        'endpoint_id' => $endpoint->id,
        'capture_id' => $capture->id,
        'destination_id' => $destination->id,
        'idempotency_key' => 'actor-shared-key',
    ];

    $first = productMcpContent(productMcpCall('/mcp/write', 'replay_capture', $arguments, $firstToken));
    $second = productMcpContent(productMcpCall('/mcp/write', 'replay_capture', $arguments, $secondToken));

    expect($first['outcome'])->toBe('accepted')
        ->and($second['outcome'])->toBe('accepted')
        ->and($second['delivery']['id'])->not->toBe($first['delivery']['id'])
        ->and(McpReplayRequest::query()->distinct()->count('actor_identifier'))->toBe(2)
        ->and(Delivery::query()->count())->toBe(2);
    Queue::assertPushedTimes(DeliverCapturedRequest::class, 2);
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

    expect(resolve(BobbinMcpScope::class)->actorIdentifier())->toBe('bfc-console:'.$actor->id);
});
