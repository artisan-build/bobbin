<?php

declare(strict_types=1);

use App\Jobs\DeliverCapturedRequest;
use App\Models\Delivery;
use App\Models\McpReplayRequest;
use App\Services\ReconcileExpiredReplayRequests;
use App\Services\ReplaySettlementFaultInjector;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Sleep;

require dirname(__DIR__, 3).'/vendor/autoload.php';

foreach ([
    'APP_ENV' => 'testing',
    'APP_KEY' => 'base64:'.base64_encode(str_repeat('c', 32)),
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

$mode = $argv[1];
$replayRequestId = (int) $argv[2];
$deliveryId = (int) $argv[3];
$gatePath = $argv[4];
$applicationName = $argv[5];

$app = require dirname(__DIR__, 3).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
DB::statement("select set_config('application_name', ?, false)", [$applicationName]);
DB::statement("select set_config('statement_timeout', ?, false)", ['10000']);

if ($mode === 'handle-fault') {
    app()->instance(ReplaySettlementFaultInjector::class, new class extends ReplaySettlementFaultInjector
    {
        public function beforeHandledJobSettlement(): void
        {
            throw new RuntimeException('forced PostgreSQL handled-job settlement failure');
        }
    });
}

fwrite(STDOUT, 'READY '.getmypid().PHP_EOL);
fflush(STDOUT);
$deadline = microtime(true) + 10;

while (! is_file($gatePath)) {
    if (microtime(true) >= $deadline) {
        throw new RuntimeException('Settlement gate did not open.');
    }

    Sleep::usleep(1_000);
}

$exception = null;

try {
    if ($mode === 'failed') {
        (new DeliverCapturedRequest($deliveryId, $replayRequestId))->failed(new RuntimeException('PostgreSQL race failure'));
    } elseif ($mode === 'handle-fault') {
        (new DeliverCapturedRequest($deliveryId, $replayRequestId))->handle();
    } elseif ($mode === 'reconcile') {
        resolve(ReconcileExpiredReplayRequests::class)->reconcile();
    } else {
        throw new InvalidArgumentException('Unknown settlement worker mode.');
    }
} catch (Throwable $caught) {
    $exception = $caught::class;
}

$receipt = McpReplayRequest::query()->findOrFail($replayRequestId);
$delivery = Delivery::query()->findOrFail($deliveryId);
fwrite(STDOUT, 'RESULT '.json_encode([
    'mode' => $mode,
    'outcome' => $receipt->outcome,
    'delivery_status' => $delivery->status,
    'exception' => $exception,
], JSON_THROW_ON_ERROR).PHP_EOL);
fflush(STDOUT);
