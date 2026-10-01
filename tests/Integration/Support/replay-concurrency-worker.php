<?php

declare(strict_types=1);

use App\Models\McpReplayRequest;
use App\Services\DispatchCapturedRequest;
use App\Services\ReplayDispatchFaultInjector;
use App\Services\ReplayIdempotencyConflict;
use App\Services\ReplayOutcomeUnknown;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Sleep;

require dirname(__DIR__, 3).'/vendor/autoload.php';

foreach ([
    'APP_ENV' => 'testing',
    'APP_KEY' => 'base64:'.base64_encode(str_repeat('b', 32)),
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

$request = json_decode(base64_decode($argv[1], true), true, flags: JSON_THROW_ON_ERROR);
$gatePath = $argv[2];
$dispatchLog = $argv[3];
$applicationName = $argv[4];
$statementTimeout = (int) $argv[5];
$transactionGate = $argv[6] !== '' ? $argv[6] : null;
$dispatchMustFail = $argv[7] === 'fail';
$faultPhase = $argv[8] ?? '';

$app = require dirname(__DIR__, 3).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
DB::statement("select set_config('application_name', ?, false)", [$applicationName]);
DB::statement("select set_config('statement_timeout', ?, false)", [(string) $statementTimeout]);

$jobs = Mockery::mock(Dispatcher::class);
$jobs->shouldReceive('dispatch')->zeroOrMoreTimes()->andReturnUsing(
    static function (object $job) use ($dispatchLog, $dispatchMustFail): object {
        file_put_contents($dispatchLog, json_encode([
            'pid' => getmypid(),
            'delivery_id' => $job->deliveryId,
            'replay_request_id' => $job->replayRequestId,
            'dispatch_token' => $job->dispatchToken,
        ], JSON_THROW_ON_ERROR).PHP_EOL, FILE_APPEND | LOCK_EX);

        if ($dispatchMustFail) {
            throw new RuntimeException('Deliberately unrecoverable dispatch result.');
        }

        return $job;
    },
);
$faults = new class($faultPhase, $transactionGate) extends ReplayDispatchFaultInjector
{
    public function __construct(
        private readonly string $phase,
        private readonly ?string $transactionGate,
    ) {}

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
        if ($this->transactionGate !== null) {
            fwrite(STDOUT, 'TRANSACTION_READY '.getmypid().PHP_EOL);
            fflush(STDOUT);

            while (! is_file($this->transactionGate)) {
                Sleep::usleep(1_000);
            }
        }

        $this->fail('outbox');
    }

    private function fail(string $phase): void
    {
        if ($this->phase === $phase) {
            throw new RuntimeException('Forced '.$phase.' transaction rollback.');
        }
    }
};

fwrite(STDOUT, 'READY '.getmypid().PHP_EOL);
fflush(STDOUT);
$deadline = microtime(true) + 10;

while (! is_file($gatePath)) {
    if (microtime(true) >= $deadline) {
        throw new RuntimeException('Concurrency gate did not open.');
    }

    Sleep::usleep(1_000);
}

$startedAt = microtime(true);

try {
    $result = (new DispatchCapturedRequest($jobs, $faults))->dispatchIdempotently(
        teamId: (int) $request['team_id'],
        endpointId: (int) $request['endpoint_id'],
        captureId: (int) $request['capture_id'],
        destinationId: (int) $request['destination_id'],
        actorIdentifier: (string) $request['actor_identifier'],
        idempotencyKey: (string) $request['idempotency_key'],
    );
    $payload = [
        'outcome' => match ($result->outcome) {
            McpReplayRequest::OUTCOME_ACCEPTED => $result->duplicate ? 'already_accepted' : 'accepted',
            McpReplayRequest::OUTCOME_IN_PROGRESS => 'in_progress',
            default => throw new RuntimeException('Unexpected replay result.'),
        },
        'delivery_id' => $result->deliveryId,
        'started_at' => $startedAt,
        'finished_at' => microtime(true),
    ];
} catch (ReplayOutcomeUnknown $exception) {
    $payload = [
        'outcome' => 'outcome_unknown',
        'delivery_id' => $exception->deliveryId,
        'started_at' => $startedAt,
        'finished_at' => microtime(true),
    ];
} catch (ReplayIdempotencyConflict) {
    $payload = [
        'outcome' => 'refused',
        'reason' => 'idempotency_conflict',
        'started_at' => $startedAt,
        'finished_at' => microtime(true),
    ];
} catch (Throwable $exception) {
    $payload = [
        'outcome' => 'error',
        'exception' => $exception::class,
        'started_at' => $startedAt,
        'finished_at' => microtime(true),
    ];
}

fwrite(STDOUT, 'RESULT '.json_encode($payload, JSON_THROW_ON_ERROR).PHP_EOL);
fflush(STDOUT);
Mockery::close();
