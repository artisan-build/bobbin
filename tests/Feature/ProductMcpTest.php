<?php

declare(strict_types=1);

use App\Contracts\ResolvesHostAddresses;
use App\Jobs\DeliverCapturedRequest;
use App\Mcp\BobbinMcpScope;
use App\Mcp\BobbinMcpServer;
use App\Mcp\BobbinReadMcpServer;
use App\Mcp\BobbinWriteMcpServer;
use App\Models\CapturedRequest;
use App\Models\Delivery;
use App\Models\Endpoint;
use App\Models\McpReplayDispatch;
use App\Models\McpReplayRequest;
use App\Services\RedispatchReplayRequests;
use App\Services\ReplayDispatchFaultInjector;
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
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Support\ReplayHostResolver;

beforeEach(function (): void {
    config()->set('app.key', 'base64:'.base64_encode(random_bytes(32)));
    app()->instance(ResolvesHostAddresses::class, new ReplayHostResolver([
        'receiver.example.test' => ['93.184.216.34'],
    ]));
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
    $replayTool = collect(productMcpList('/mcp/write', $token)->json('result.tools'))->firstWhere('name', 'replay_capture');
    expect($replayTool['description'])->toContain('durably committed for at-least-once delivery to the queue');

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

it('atomically suppresses replay duplicates and refuses changed key reuse', function (): void {
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

});

it('keeps dispatch failures accepted and redispatches one durable effect', function (): void {
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
        'idempotency_key' => 'dispatch-outbox-recovery',
    ];
    $originalJobs = resolve(Dispatcher::class);
    $jobs = Mockery::mock(Dispatcher::class);
    $jobs->shouldReceive('dispatch')->once()->andThrow(new RuntimeException('forced queue transport failure'));
    app()->instance(Dispatcher::class, $jobs);

    $accepted = productMcpContent(productMcpCall('/mcp/write', 'replay_capture', $arguments, $token));
    $receipt = McpReplayRequest::query()->where('idempotency_key', 'dispatch-outbox-recovery')->firstOrFail();
    $intent = McpReplayDispatch::query()->sole();
    $duplicate = productMcpContent(productMcpCall('/mcp/write', 'replay_capture', $arguments, $token));
    expect($accepted['outcome'])->toBe('accepted')
        ->and($duplicate['outcome'])->toBe('already_accepted')
        ->and($duplicate['delivery'])->toBe($accepted['delivery'])
        ->and($receipt->outcome)->toBe(McpReplayRequest::OUTCOME_ACCEPTED)
        ->and($intent->consumed_at)->toBeNull()
        ->and(McpReplayRequest::query()->count())->toBe(1)
        ->and(Delivery::query()->count())->toBe(1)
        ->and(McpReplayDispatch::query()->count())->toBe(1);

    app()->instance(Dispatcher::class, $originalJobs);
    expect(Artisan::call('mcp-replays:redispatch'))->toBe(0);
    Queue::assertNothingPushed();

    $this->travel(RedispatchReplayRequests::DELAY_SECONDS + 1)->seconds();
    expect(Artisan::call('mcp-replays:redispatch'))->toBe(0)
        ->and(Artisan::call('mcp-replays:redispatch'))->toBe(0);
    $queued = Queue::pushed(DeliverCapturedRequest::class)->values();
    expect($queued)->toHaveCount(2)
        ->and($queued[0]->dispatchToken)->not->toBe($queued[1]->dispatchToken);

    Http::fake(['*' => Http::response('', 204)]);
    $queued[0]->handle();
    $queued[1]->handle();

    Http::assertSentCount(1);
    expect($intent->refresh()->consumed_at)->not->toBeNull()
        ->and($intent->claim_token)->toBe($queued[0]->dispatchToken)
        ->and($receipt->refresh()->outcome)->toBe(McpReplayRequest::OUTCOME_ACCEPTED)
        ->and(Delivery::query()->sole()->status)->toBe(Delivery::STATUS_SUCCEEDED)
        ->and(Delivery::query()->sole()->attempt_count)->toBe(1)
        ->and(McpReplayRequest::query()->count())->toBe(1)
        ->and(McpReplayDispatch::query()->count())->toBe(1);

    $event = collect(resolve(Schedule::class)->events())
        ->first(fn ($event): bool => str_contains((string) $event->command, 'mcp-replays:redispatch'));
    expect($event)->not->toBeNull()
        ->and($event->expression)->toBe('* * * * *');
});

it('never changes a terminally unknown receipt from the delivery job', function (): void {
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
        'idempotency_key' => 'terminal-unknown',
    ];
    productMcpContent(productMcpCall('/mcp/write', 'replay_capture', $arguments, $token));
    $receipt = McpReplayRequest::query()->where('idempotency_key', 'terminal-unknown')->firstOrFail();
    $receipt->update(['outcome' => McpReplayRequest::OUTCOME_UNKNOWN]);
    $job = Queue::pushed(DeliverCapturedRequest::class)->sole();

    Http::fake(['*' => Http::response('', 204)]);
    $job->handle();
    $job->failed(new RuntimeException('duplicate failure callback'));

    $retry = productMcpCall('/mcp/write', 'replay_capture', $arguments, $token)
        ->assertOk()
        ->assertJsonPath('result.isError', true);
    expect(productMcpContent($retry))->toBe([
        'outcome' => 'outcome_unknown',
        'delivery_id' => $receipt->delivery_id,
    ])->and($receipt->refresh()->outcome)->toBe(McpReplayRequest::OUTCOME_UNKNOWN)
        ->and($receipt->delivery_id)->toBe($job->deliveryId);
    Http::assertSentCount(1);
});

it('rolls back every replay outbox write fault and allows the same key to retry', function (): void {
    foreach (['receipt', 'delivery', 'outbox'] as $phase) {
        $token = productMcpToken('fault-'.$phase);
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
            'idempotency_key' => 'rollback-'.$phase,
        ];
        $receiptCount = McpReplayRequest::query()->count();
        $deliveryCount = Delivery::query()->count();
        $dispatchCount = McpReplayDispatch::query()->count();
        app()->instance(ReplayDispatchFaultInjector::class, new class($phase) extends ReplayDispatchFaultInjector
        {
            public function __construct(private readonly string $phase) {}

            public function afterReceiptWrite(): void
            {
                $this->fail('receipt');
            }

            public function afterDeliveryWrite(): void
            {
                $this->fail('delivery');
            }

            public function afterOutboxWrite(): void
            {
                $this->fail('outbox');
            }

            private function fail(string $phase): void
            {
                if ($this->phase === $phase) {
                    throw new RuntimeException('forced '.$phase.' write rollback');
                }
            }
        });

        productMcpCall('/mcp/write', 'replay_capture', $arguments, $token)
            ->assertOk()
            ->assertJsonPath('result.isError', true);
        expect(McpReplayRequest::query()->where('idempotency_key', 'rollback-'.$phase)->count())->toBe(0)
            ->and(Delivery::query()->where('captured_request_id', $capture->id)->count())->toBe(0)
            ->and(McpReplayRequest::query()->count())->toBe($receiptCount)
            ->and(Delivery::query()->count())->toBe($deliveryCount)
            ->and(McpReplayDispatch::query()->count())->toBe($dispatchCount);

        app()->instance(ReplayDispatchFaultInjector::class, new ReplayDispatchFaultInjector);
        $retry = productMcpContent(productMcpCall('/mcp/write', 'replay_capture', $arguments, $token));
        expect($retry['outcome'])->toBe('accepted')
            ->and(McpReplayRequest::query()->where('idempotency_key', 'rollback-'.$phase)->count())->toBe(1)
            ->and(Delivery::query()->where('captured_request_id', $capture->id)->count())->toBe(1)
            ->and(McpReplayDispatch::query()->count())->toBe($dispatchCount + 1);
    }
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
