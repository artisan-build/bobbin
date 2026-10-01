<?php

declare(strict_types=1);

use App\Contracts\ResolvesHostAddresses;
use App\Jobs\DeliverCapturedRequest;
use App\Models\Delivery;
use App\Models\Endpoint;
use App\Models\McpReplayDispatch;
use App\Models\McpReplayRequest;
use App\Services\RedispatchReplayRequests;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Symfony\Component\Process\Process;

require dirname(__DIR__, 2).'/vendor/autoload.php';

foreach ([
    'APP_ENV' => 'testing',
    'APP_KEY' => 'base64:'.base64_encode(str_repeat('a', 32)),
    'CACHE_STORE' => 'array',
    'DB_CONNECTION' => 'pgsql',
    'DB_DATABASE' => 'bobbin_mcp_test',
    'DB_HOST' => '127.0.0.1',
    'DB_PASSWORD' => '',
    'DB_PORT' => '5432',
    'DB_URL' => '',
    'DB_USERNAME' => 'root',
    'QUEUE_CONNECTION' => 'sync',
] as $name => $value) {
    putenv($name.'='.$value);
    $_ENV[$name] = $value;
    $_SERVER[$name] = $value;
}

$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

function concurrencyEnsure(bool $condition, string $message): void
{
    if (! $condition) {
        throw new RuntimeException($message);
    }
}

/** @return array{team_id: int, endpoint_id: int, capture_id: int, destination_id: int, actor_identifier: string, idempotency_key: string} */
function concurrencyRequest(string $key): array
{
    $teamId = (int) DB::table('teams')->value('id');
    $endpoint = new Endpoint(['name' => 'Postgres concurrency '.$key]);
    $endpoint->team_id = $teamId;
    $endpoint->save();
    $capture = $endpoint->capturedRequests()->create([
        'method' => 'POST',
        'raw_query' => '',
        'headers' => [],
        'body' => 'postgres-harness',
        'received_at' => now(),
    ]);
    $destination = $endpoint->destinations()->create([
        'url' => 'https://receiver.example.test/postgres-harness',
        'is_active' => true,
    ]);

    return [
        'team_id' => $teamId,
        'endpoint_id' => $endpoint->id,
        'capture_id' => $capture->id,
        'destination_id' => $destination->id,
        'actor_identifier' => 'credential:postgres-harness',
        'idempotency_key' => $key,
    ];
}

function concurrencyWorker(
    array $request,
    string $gate,
    string $dispatchLog,
    string $name,
    ?string $transactionGate = null,
    bool $dispatchMustFail = false,
    string $faultPhase = '',
): Process {
    return new Process([
        PHP_BINARY,
        __DIR__.'/Support/replay-concurrency-worker.php',
        base64_encode(json_encode($request, JSON_THROW_ON_ERROR)),
        $gate,
        $dispatchLog,
        $name,
        '10000',
        $transactionGate ?? '',
        $dispatchMustFail ? 'fail' : 'succeed',
        $faultPhase,
    ], dirname(__DIR__, 2), timeout: 20);
}

function concurrencyWaitFor(Process $process, string $marker): void
{
    $deadline = microtime(true) + 10;

    while (! str_contains($process->getOutput(), $marker)) {
        concurrencyEnsure($process->isRunning(), 'Worker exited before '.$marker.': '.$process->getErrorOutput().$process->getOutput());
        concurrencyEnsure(microtime(true) < $deadline, 'Worker did not reach '.$marker.'.');
        Sleep::usleep(1_000);
    }
}

/** @return array<string, mixed> */
function concurrencyResult(Process $process): array
{
    $exitCode = $process->wait();
    concurrencyEnsure($exitCode === 0, 'Worker failed: '.$process->getErrorOutput().$process->getOutput());
    concurrencyEnsure(preg_match('/^RESULT (.+)$/m', $process->getOutput(), $matches) === 1, 'Worker emitted no result.');

    return json_decode($matches[1], true, flags: JSON_THROW_ON_ERROR);
}

/** @return list<array<string, mixed>> */
function concurrencyDispatches(string $path): array
{
    if (! is_file($path)) {
        return [];
    }

    return array_map(
        static fn (string $line): array => json_decode($line, true, flags: JSON_THROW_ON_ERROR),
        file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES),
    );
}

function concurrencyOpenGate(string $path): void
{
    file_put_contents($path, 'go');
}

$runId = bin2hex(random_bytes(6));
$runRoot = sys_get_temp_dir().'/bobbin-postgres-concurrency-'.$runId;
concurrencyEnsure(mkdir($runRoot, 0700), 'Could not create harness directory.');

try {
    concurrencyEnsure(config('database.default') === 'pgsql', 'Harness must use the pgsql connection.');
    concurrencyEnsure(config('database.connections.pgsql.database') === 'bobbin_mcp_test', 'Harness must use the disposable database.');
    concurrencyEnsure(Artisan::call('migrate:fresh', ['--force' => true, '--no-interaction' => true]) === 0, 'migrate:fresh failed.');
    $version = (string) DB::selectOne('show server_version')->server_version;

    $same = concurrencyRequest('same-hash-'.$runId);
    $sameGate = $runRoot.'/same.gate';
    $sameTransactionGate = $runRoot.'/same-transaction.gate';
    $sameDispatches = $runRoot.'/same-dispatches.jsonl';
    $sameA = concurrencyWorker($same, $sameGate, $sameDispatches, 'bobbin-same-a-'.$runId, $sameTransactionGate);
    $sameA->start();
    concurrencyWaitFor($sameA, 'READY ');
    concurrencyOpenGate($sameGate);
    concurrencyWaitFor($sameA, 'TRANSACTION_READY ');

    $sameB = concurrencyWorker($same, $sameGate, $sameDispatches, 'bobbin-same-b-'.$runId);
    $sameB->start();
    concurrencyWaitFor($sameB, 'READY ');
    $sameBResult = concurrencyResult($sameB);
    concurrencyEnsure($sameBResult['outcome'] === 'in_progress', 'Held-open exact replay was not in progress.');
    concurrencyEnsure($sameBResult['delivery_id'] === null, 'Held-open replay exposed an uncommitted handle.');
    concurrencyEnsure(McpReplayRequest::query()->where('idempotency_key', $same['idempotency_key'])->doesntExist(), 'Uncommitted receipt became visible.');
    concurrencyEnsure(Delivery::query()->where('captured_request_id', $same['capture_id'])->doesntExist(), 'A second delivery survived the held transaction.');
    concurrencyEnsure(McpReplayDispatch::query()->doesntExist(), 'A second outbox intent survived the held transaction.');

    concurrencyOpenGate($sameTransactionGate);
    $sameAResult = concurrencyResult($sameA);
    concurrencyEnsure($sameAResult['outcome'] === 'accepted', 'Winning held transaction did not commit accepted.');
    concurrencyEnsure(McpReplayRequest::query()->where('idempotency_key', $same['idempotency_key'])->count() === 1, 'Exact race created multiple receipts.');
    concurrencyEnsure(Delivery::query()->where('captured_request_id', $same['capture_id'])->count() === 1, 'Exact race created multiple deliveries.');
    concurrencyEnsure(McpReplayDispatch::query()->count() === 1, 'Exact race created multiple outbox intents.');
    concurrencyEnsure(count(concurrencyDispatches($sameDispatches)) === 1, 'Exact race dispatched more than once.');

    $sameRetry = concurrencyWorker($same, $sameGate, $sameDispatches, 'bobbin-same-retry-'.$runId);
    $sameRetry->start();
    $sameRetryResult = concurrencyResult($sameRetry);
    concurrencyEnsure($sameRetryResult['outcome'] === 'already_accepted', 'Committed exact replay was not already accepted.');
    concurrencyEnsure($sameRetryResult['delivery_id'] === $sameAResult['delivery_id'], 'Committed exact replay changed handles.');
    concurrencyEnsure(count(concurrencyDispatches($sameDispatches)) === 1, 'Committed exact replay redispatched.');

    $changed = concurrencyRequest('changed-hash-'.$runId);
    $changedCapture = Endpoint::query()->findOrFail($changed['endpoint_id'])->capturedRequests()->create([
        'method' => 'POST', 'raw_query' => '', 'headers' => [], 'body' => 'changed', 'received_at' => now(),
    ]);
    $changedOther = [...$changed, 'capture_id' => $changedCapture->id];
    $changedGate = $runRoot.'/changed.gate';
    $changedDispatches = $runRoot.'/changed-dispatches.jsonl';
    $changedA = concurrencyWorker($changed, $changedGate, $changedDispatches, 'bobbin-changed-a-'.$runId);
    $changedB = concurrencyWorker($changedOther, $changedGate, $changedDispatches, 'bobbin-changed-b-'.$runId);
    $changedA->start();
    $changedB->start();
    concurrencyWaitFor($changedA, 'READY ');
    concurrencyWaitFor($changedB, 'READY ');
    concurrencyOpenGate($changedGate);
    $changedResults = [concurrencyResult($changedA), concurrencyResult($changedB)];
    $changedOutcomes = array_column($changedResults, 'outcome');
    sort($changedOutcomes);
    concurrencyEnsure(
        $changedOutcomes === ['accepted', 'refused'],
        'Changed request race did not produce one acceptance and one refusal: '.json_encode($changedResults, JSON_THROW_ON_ERROR),
    );
    concurrencyEnsure(Delivery::query()->whereIn('captured_request_id', [$changed['capture_id'], $changedOther['capture_id']])->count() === 1, 'Changed request race created multiple deliveries.');
    concurrencyEnsure(McpReplayRequest::query()->where('idempotency_key', $changed['idempotency_key'])->count() === 1, 'Changed request race created multiple receipts.');
    concurrencyEnsure(count(concurrencyDispatches($changedDispatches)) === 1, 'Changed request race dispatched more than once.');

    McpReplayDispatch::query()->update(['consumed_at' => now(), 'claim_token' => '00000000-0000-0000-0000-000000000001']);
    $recovery = concurrencyRequest('dispatch-recovery-'.$runId);
    $recoveryGate = $runRoot.'/recovery.gate';
    $recoveryDispatches = $runRoot.'/recovery-dispatches.jsonl';
    concurrencyOpenGate($recoveryGate);
    $recoveryWorker = concurrencyWorker($recovery, $recoveryGate, $recoveryDispatches, 'bobbin-recovery-'.$runId, dispatchMustFail: true);
    $recoveryWorker->start();
    $recoveryResult = concurrencyResult($recoveryWorker);
    concurrencyEnsure($recoveryResult['outcome'] === 'accepted', 'Post-commit dispatch failure was not accepted.');
    $recoveryReceipt = McpReplayRequest::query()->where('idempotency_key', $recovery['idempotency_key'])->firstOrFail();
    $recoveryIntent = McpReplayDispatch::query()->where('replay_request_id', $recoveryReceipt->id)->firstOrFail();
    concurrencyEnsure($recoveryReceipt->outcome === McpReplayRequest::OUTCOME_ACCEPTED, 'Dispatch failure changed the terminal receipt.');
    concurrencyEnsure($recoveryIntent->consumed_at === null, 'Dispatch failure consumed the outbox intent.');

    $recoveryRetry = concurrencyWorker($recovery, $recoveryGate, $recoveryDispatches, 'bobbin-recovery-retry-'.$runId);
    $recoveryRetry->start();
    $recoveryRetryResult = concurrencyResult($recoveryRetry);
    concurrencyEnsure($recoveryRetryResult['outcome'] === 'already_accepted', 'Dispatch failure retry was not already accepted.');
    concurrencyEnsure(count(concurrencyDispatches($recoveryDispatches)) === 1, 'Dispatch failure retry redispatched directly.');

    $redispatchedJobs = [];
    $redispatchJobs = Mockery::mock(Dispatcher::class);
    $redispatchJobs->shouldReceive('dispatch')->twice()->andReturnUsing(
        static function (DeliverCapturedRequest $job) use (&$redispatchedJobs): DeliverCapturedRequest {
            $redispatchedJobs[] = $job;

            return $job;
        },
    );
    McpReplayDispatch::query()
        ->whereKey($recoveryIntent->id)
        ->update(['created_at' => now()->subMinute()]);
    $redispatcher = new RedispatchReplayRequests($redispatchJobs);
    concurrencyEnsure($redispatcher->redispatch() === 1, 'First redispatch did not enqueue the eligible intent.');
    concurrencyEnsure($redispatcher->redispatch() === 1, 'Concurrent-safe repeat did not enqueue the same unconsumed intent.');
    concurrencyEnsure(count($redispatchedJobs) === 2, 'Redispatch count was not exact.');

    app()->instance(ResolvesHostAddresses::class, new class implements ResolvesHostAddresses
    {
        public function resolve(string $host): array
        {
            return ['93.184.216.34'];
        }
    });
    Http::fake(['*' => Http::response('', 204)]);
    $redispatchedJobs[0]->handle();
    $redispatchedJobs[1]->handle();
    concurrencyEnsure(Http::recorded()->count() === 1, 'Duplicate jobs produced more than one outbound HTTP effect.');
    concurrencyEnsure($recoveryIntent->refresh()->consumed_at !== null, 'Delivery job did not consume the outbox intent.');
    concurrencyEnsure($recoveryReceipt->refresh()->outcome === McpReplayRequest::OUTCOME_ACCEPTED, 'Delivery job changed the terminal receipt.');
    concurrencyEnsure(Delivery::query()->whereKey($recoveryReceipt->delivery_id)->value('attempt_count') === 1, 'Duplicate job created another logical delivery attempt.');

    $faultResults = [];

    foreach (['receipt', 'delivery', 'outbox'] as $phase) {
        $fault = concurrencyRequest('fault-'.$phase.'-'.$runId);
        $faultGate = $runRoot.'/fault-'.$phase.'.gate';
        $faultDispatches = $runRoot.'/fault-'.$phase.'-dispatches.jsonl';
        concurrencyOpenGate($faultGate);
        $receiptCount = McpReplayRequest::query()->count();
        $deliveryCount = Delivery::query()->count();
        $outboxCount = McpReplayDispatch::query()->count();
        $faultWorker = concurrencyWorker($fault, $faultGate, $faultDispatches, 'bobbin-fault-'.$phase.'-'.$runId, faultPhase: $phase);
        $faultWorker->start();
        $faultResult = concurrencyResult($faultWorker);
        concurrencyEnsure($faultResult['outcome'] === 'error', 'The '.$phase.' fault did not roll back.');
        concurrencyEnsure(McpReplayRequest::query()->count() === $receiptCount, 'The '.$phase.' fault retained a receipt.');
        concurrencyEnsure(Delivery::query()->count() === $deliveryCount, 'The '.$phase.' fault retained a delivery.');
        concurrencyEnsure(McpReplayDispatch::query()->count() === $outboxCount, 'The '.$phase.' fault retained an outbox intent.');

        $retryWorker = concurrencyWorker($fault, $faultGate, $faultDispatches, 'bobbin-fault-retry-'.$phase.'-'.$runId);
        $retryWorker->start();
        $retryResult = concurrencyResult($retryWorker);
        concurrencyEnsure($retryResult['outcome'] === 'accepted', 'The '.$phase.' fault key could not retry.');
        concurrencyEnsure(count(concurrencyDispatches($faultDispatches)) === 1, 'The '.$phase.' fault retry dispatch count was not one.');
        $faultResults[$phase] = ['fault' => $faultResult, 'retry' => $retryResult, 'dispatches' => 1];
    }

    $rollback = concurrencyRequest('rollback-takeover-'.$runId);
    $rollbackGate = $runRoot.'/rollback.gate';
    $rollbackTransactionGate = $runRoot.'/rollback-transaction.gate';
    $rollbackDispatches = $runRoot.'/rollback-dispatches.jsonl';
    $rollbackA = concurrencyWorker($rollback, $rollbackGate, $rollbackDispatches, 'bobbin-rollback-a-'.$runId, $rollbackTransactionGate, faultPhase: 'outbox');
    $rollbackA->start();
    concurrencyWaitFor($rollbackA, 'READY ');
    concurrencyOpenGate($rollbackGate);
    concurrencyWaitFor($rollbackA, 'TRANSACTION_READY ');
    $rollbackB = concurrencyWorker($rollback, $rollbackGate, $rollbackDispatches, 'bobbin-rollback-b-'.$runId);
    $rollbackB->start();
    $rollbackBResult = concurrencyResult($rollbackB);
    concurrencyEnsure($rollbackBResult['outcome'] === 'in_progress', 'Rollback contender did not observe the held transaction.');
    concurrencyOpenGate($rollbackTransactionGate);
    $rollbackAResult = concurrencyResult($rollbackA);
    concurrencyEnsure($rollbackAResult['outcome'] === 'error', 'Held winner did not roll back.');
    concurrencyEnsure(McpReplayRequest::query()->where('idempotency_key', $rollback['idempotency_key'])->doesntExist(), 'Held rollback retained a receipt.');
    $rollbackTakeover = concurrencyWorker($rollback, $rollbackGate, $rollbackDispatches, 'bobbin-rollback-takeover-'.$runId);
    $rollbackTakeover->start();
    $rollbackTakeoverResult = concurrencyResult($rollbackTakeover);
    concurrencyEnsure($rollbackTakeoverResult['outcome'] === 'accepted', 'Exact retry did not take over after rollback.');
    concurrencyEnsure(count(concurrencyDispatches($rollbackDispatches)) === 1, 'Rollback takeover dispatch count was not one.');

    fwrite(STDOUT, 'POSTGRES_CONCURRENCY_HARNESS '.json_encode([
        'run_id' => $runId,
        'driver' => DB::getDriverName(),
        'database' => config('database.connections.pgsql.database'),
        'server_version' => $version,
        'same_hash' => ['winner' => $sameAResult, 'overlap' => $sameBResult, 'retry' => $sameRetryResult, 'dispatches' => 1],
        'changed_hash' => ['results' => $changedResults, 'dispatches' => 1],
        'dispatch_recovery' => [
            'initial' => $recoveryResult,
            'retry' => $recoveryRetryResult,
            'redispatches' => 2,
            'job_executions' => 2,
            'http_effects' => 1,
            'delivery_attempts' => 1,
        ],
        'transaction_faults' => $faultResults,
        'rollback_takeover' => ['winner' => $rollbackAResult, 'overlap' => $rollbackBResult, 'takeover' => $rollbackTakeoverResult, 'dispatches' => 1],
    ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES).PHP_EOL);
} finally {
    Mockery::close();

    foreach (glob($runRoot.'/*') ?: [] as $path) {
        unlink($path);
    }

    rmdir($runRoot);
}
