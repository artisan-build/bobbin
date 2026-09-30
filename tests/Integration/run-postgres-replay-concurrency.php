<?php

declare(strict_types=1);

use App\Models\Delivery;
use App\Models\Endpoint;
use App\Models\McpReplayRequest;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
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
function concurrencyRequest(string $key, string $actor = 'credential:postgres-harness'): array
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
        'actor_identifier' => $actor,
        'idempotency_key' => $key,
    ];
}

function concurrencyWorker(
    array $request,
    string $gate,
    string $dispatchLog,
    string $name,
    int $timeout = 10_000,
    ?string $dispatchGate = null,
    bool $dispatchMustFail = false,
): Process
{
    return new Process([
        PHP_BINARY,
        __DIR__.'/Support/replay-concurrency-worker.php',
        base64_encode(json_encode($request, JSON_THROW_ON_ERROR)),
        $gate,
        $dispatchLog,
        $name,
        (string) $timeout,
        $dispatchGate ?? '',
        $dispatchMustFail ? 'fail' : 'succeed',
    ], dirname(__DIR__, 2), timeout: 20);
}

function concurrencyWaitForDispatches(string $path, int $count): void
{
    $deadline = microtime(true) + 10;

    while (count(concurrencyDispatches($path)) < $count) {
        concurrencyEnsure(microtime(true) < $deadline, 'Worker did not reach dispatch.');
        Sleep::usleep(1_000);
    }
}

function concurrencyWaitReady(Process $process): void
{
    $deadline = microtime(true) + 10;

    while (! str_contains($process->getOutput(), 'READY ')) {
        concurrencyEnsure($process->isRunning(), 'Worker exited before reaching its barrier: '.$process->getErrorOutput());
        concurrencyEnsure(microtime(true) < $deadline, 'Worker did not reach its barrier.');
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
    $sameDispatchGate = $runRoot.'/same-dispatch.gate';
    $sameDispatches = $runRoot.'/same-dispatches.jsonl';
    $sameA = concurrencyWorker($same, $sameGate, $sameDispatches, 'bobbin-same-a-'.$runId, dispatchGate: $sameDispatchGate);
    $sameA->start();
    concurrencyWaitReady($sameA);
    concurrencyOpenGate($sameGate);
    concurrencyWaitForDispatches($sameDispatches, 1);

    $sameB = concurrencyWorker($same, $sameGate, $sameDispatches, 'bobbin-same-b-'.$runId);
    $sameB->start();
    concurrencyWaitReady($sameB);
    $sameBResult = concurrencyResult($sameB);
    concurrencyEnsure($sameBResult['outcome'] === 'in_progress', 'In-flight same-hash retry was not in progress.');
    concurrencyOpenGate($sameDispatchGate);
    $sameAResult = concurrencyResult($sameA);
    $sameResults = [$sameAResult, $sameBResult];
    $sameOutcomes = array_column($sameResults, 'outcome');
    $sameDeliveryIds = array_values(array_unique(array_column($sameResults, 'delivery_id')));
    concurrencyEnsure(max(array_column($sameResults, 'started_at')) < min(array_column($sameResults, 'finished_at')), 'Same-hash workers did not overlap.');
    sort($sameOutcomes);
    concurrencyEnsure($sameOutcomes === ['accepted', 'in_progress'], 'Same-hash race did not produce accepted and in-progress outcomes.');
    concurrencyEnsure(count($sameDeliveryIds) === 1, 'Same-hash callers did not receive one handle.');
    concurrencyEnsure(Delivery::query()->where('captured_request_id', $same['capture_id'])->count() === 1, 'Same-hash race created multiple deliveries.');
    concurrencyEnsure(McpReplayRequest::query()->where('idempotency_key', $same['idempotency_key'])->count() === 1, 'Same-hash race created multiple receipts.');
    concurrencyEnsure(McpReplayRequest::query()->where('idempotency_key', $same['idempotency_key'])->whereNull('delivery_id')->doesntExist(), 'Same-hash race stranded a null handle.');
    concurrencyEnsure(count(concurrencyDispatches($sameDispatches)) === 1, 'Same-hash race dispatched more than once.');

    $sameRetryGate = $runRoot.'/same-retry.gate';
    concurrencyOpenGate($sameRetryGate);
    $sameRetry = concurrencyWorker($same, $sameRetryGate, $sameDispatches, 'bobbin-same-retry-'.$runId);
    $sameRetry->start();
    $sameRetryResult = concurrencyResult($sameRetry);
    concurrencyEnsure($sameRetryResult['outcome'] === 'already_accepted', 'Settled same-hash retry was not already accepted.');
    concurrencyEnsure($sameRetryResult['delivery_id'] === $sameDeliveryIds[0], 'Settled same-hash retry changed handles.');
    concurrencyEnsure(count(concurrencyDispatches($sameDispatches)) === 1, 'Settled same-hash retry redispatched.');

    $unknown = concurrencyRequest('terminal-unknown-'.$runId);
    $unknownGate = $runRoot.'/unknown.gate';
    $unknownDispatches = $runRoot.'/unknown-dispatches.jsonl';
    concurrencyOpenGate($unknownGate);
    $unknownInitial = concurrencyWorker($unknown, $unknownGate, $unknownDispatches, 'bobbin-unknown-initial-'.$runId, dispatchMustFail: true);
    $unknownInitial->start();
    $unknownInitialResult = concurrencyResult($unknownInitial);
    concurrencyEnsure($unknownInitialResult['outcome'] === 'outcome_unknown', 'Unrecoverable dispatch was not terminally unknown.');

    $unknownRetryResults = [];

    foreach (['first', 'later'] as $retryName) {
        $unknownRetry = concurrencyWorker($unknown, $unknownGate, $unknownDispatches, 'bobbin-unknown-'.$retryName.'-'.$runId);
        $unknownRetry->start();
        $unknownRetryResults[] = concurrencyResult($unknownRetry);
    }

    concurrencyEnsure(array_column($unknownRetryResults, 'outcome') === ['outcome_unknown', 'outcome_unknown'], 'A terminally unknown retry changed outcome.');
    concurrencyEnsure(count(array_unique(array_column([$unknownInitialResult, ...$unknownRetryResults], 'delivery_id'))) === 1, 'Terminally unknown retries changed handles.');
    concurrencyEnsure(McpReplayRequest::query()->where('idempotency_key', $unknown['idempotency_key'])->value('outcome') === McpReplayRequest::OUTCOME_UNKNOWN, 'Terminally unknown state was not durable.');
    concurrencyEnsure(count(concurrencyDispatches($unknownDispatches)) === 1, 'Terminally unknown retry redispatched.');

    $changed = concurrencyRequest('changed-hash-'.$runId);
    $changedCapture = Endpoint::query()->findOrFail($changed['endpoint_id'])->capturedRequests()->create([
        'method' => 'POST',
        'raw_query' => '',
        'headers' => [],
        'body' => 'changed-postgres-harness',
        'received_at' => now(),
    ]);
    $changedOther = [...$changed, 'capture_id' => $changedCapture->id];
    $changedGate = $runRoot.'/changed.gate';
    $changedDispatches = $runRoot.'/changed-dispatches.jsonl';
    $changedA = concurrencyWorker($changed, $changedGate, $changedDispatches, 'bobbin-changed-a-'.$runId);
    $changedB = concurrencyWorker($changedOther, $changedGate, $changedDispatches, 'bobbin-changed-b-'.$runId);
    $changedA->start();
    $changedB->start();
    concurrencyWaitReady($changedA);
    concurrencyWaitReady($changedB);
    concurrencyOpenGate($changedGate);
    $changedResults = [concurrencyResult($changedA), concurrencyResult($changedB)];
    $changedOutcomes = array_column($changedResults, 'outcome');
    sort($changedOutcomes);
    concurrencyEnsure(max(array_column($changedResults, 'started_at')) < min(array_column($changedResults, 'finished_at')), 'Changed-hash workers did not overlap.');
    concurrencyEnsure($changedOutcomes === ['accepted', 'refused'], 'Changed-hash race did not produce one winner and one refusal.');
    concurrencyEnsure(Delivery::query()->whereIn('captured_request_id', [$changed['capture_id'], $changedOther['capture_id']])->count() === 1, 'Changed-hash race created multiple deliveries.');
    concurrencyEnsure(McpReplayRequest::query()->where('idempotency_key', $changed['idempotency_key'])->whereNull('delivery_id')->doesntExist(), 'Changed-hash race stranded a null handle.');
    concurrencyEnsure(count(concurrencyDispatches($changedDispatches)) === 1, 'Changed-hash race dispatched more than once.');
    $changedLoser = $changedResults[0]['outcome'] === 'refused' ? $changed : $changedOther;
    $changedRetryGate = $runRoot.'/changed-retry.gate';
    concurrencyOpenGate($changedRetryGate);
    $changedRetry = concurrencyWorker($changedLoser, $changedRetryGate, $changedDispatches, 'bobbin-changed-retry-'.$runId);
    $changedRetry->start();
    $changedRetryResult = concurrencyResult($changedRetry);
    concurrencyEnsure($changedRetryResult['outcome'] === 'refused' && $changedRetryResult['reason'] === 'idempotency_conflict', 'Changed-hash refusal was not stable.');
    concurrencyEnsure(count(concurrencyDispatches($changedDispatches)) === 1, 'Changed-hash retry dispatched.');

    $rollback = concurrencyRequest('rollback-'.$runId);
    $rollbackGate = $runRoot.'/rollback.gate';
    $rollbackDispatches = $runRoot.'/rollback-dispatches.jsonl';
    DB::beginTransaction();
    DB::select('select id from endpoints where id = ? for update', [$rollback['endpoint_id']]);
    $rollbackA = concurrencyWorker($rollback, $rollbackGate, $rollbackDispatches, 'bobbin-rollback-a-'.$runId, 5_000);
    $rollbackA->start();
    concurrencyWaitReady($rollbackA);
    concurrencyOpenGate($rollbackGate);
    $lockDeadline = microtime(true) + 5;

    do {
        DB::statement('select pg_stat_clear_snapshot()');
        $winnerWaiting = (bool) DB::selectOne(
            "select exists(select 1 from pg_stat_activity where application_name = ? and wait_event_type = 'Lock') as waiting",
            ['bobbin-rollback-a-'.$runId],
        )->waiting;
        concurrencyEnsure(microtime(true) < $lockDeadline, 'Rollback winner never reached the forced lock wait.');
        Sleep::usleep(5_000);
    } while (! $winnerWaiting);

    $rollbackB = concurrencyWorker($rollback, $rollbackGate, $rollbackDispatches, 'bobbin-rollback-b-'.$runId, 10_000);
    $rollbackB->start();
    concurrencyWaitReady($rollbackB);
    $contenderDeadline = microtime(true) + 4;

    do {
        DB::statement('select pg_stat_clear_snapshot()');
        $contenderActivity = DB::selectOne(
            'select state, wait_event_type, wait_event from pg_stat_activity where application_name = ?',
            ['bobbin-rollback-b-'.$runId],
        );
        $contenderWaiting = $contenderActivity !== null && $contenderActivity->wait_event_type === 'Lock';
        concurrencyEnsure(microtime(true) < $contenderDeadline, 'Rollback contender never overlapped the first winner.');
        Sleep::usleep(5_000);
    } while (! $contenderWaiting);

    $rollbackAResult = concurrencyResult($rollbackA);
    concurrencyEnsure($rollbackAResult['outcome'] === 'error', 'Forced winner did not roll back.');
    DB::commit();
    $rollbackBResult = concurrencyResult($rollbackB);
    concurrencyEnsure($rollbackBResult['outcome'] === 'accepted', 'Contender did not take over after winner rollback.');
    concurrencyEnsure(Delivery::query()->where('captured_request_id', $rollback['capture_id'])->count() === 1, 'Rollback recovery did not create exactly one delivery.');
    concurrencyEnsure(McpReplayRequest::query()->where('idempotency_key', $rollback['idempotency_key'])->whereNull('delivery_id')->doesntExist(), 'Rollback recovery stranded a null handle.');
    concurrencyEnsure(count(concurrencyDispatches($rollbackDispatches)) === 1, 'Rollback recovery did not dispatch exactly once.');

    fwrite(STDOUT, 'POSTGRES_CONCURRENCY_HARNESS '.json_encode([
        'run_id' => $runId,
        'driver' => DB::getDriverName(),
        'database' => config('database.connections.pgsql.database'),
        'server_version' => $version,
        'same_hash' => ['results' => $sameResults, 'overlap_verified' => true, 'dispatches' => 1, 'delivery_id' => $sameDeliveryIds[0]],
        'terminal_unknown' => ['initial' => $unknownInitialResult, 'retries' => $unknownRetryResults, 'dispatches' => 1],
        'changed_hash' => ['results' => $changedResults, 'overlap_verified' => true, 'stable_conflict' => true, 'dispatches' => 1],
        'winner_rollback' => ['first' => $rollbackAResult, 'contender' => $rollbackBResult, 'overlap_verified' => true, 'dispatches' => 1],
        'null_handle_rows' => 0,
    ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES).PHP_EOL);
} finally {
    if (DB::transactionLevel() > 0) {
        DB::rollBack();
    }

    foreach (glob($runRoot.'/*') ?: [] as $path) {
        unlink($path);
    }

    rmdir($runRoot);
}
