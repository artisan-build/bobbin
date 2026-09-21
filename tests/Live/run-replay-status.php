<?php

declare(strict_types=1);

use ArtisanBuild\BuiltForCloud\User;
use GuzzleHttp\Client;
use GuzzleHttp\Cookie\CookieJar;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Sleep;
use Psr\Http\Message\ResponseInterface;
use Symfony\Component\Process\Process;

require dirname(__DIR__, 2).'/vendor/autoload.php';

$basePath = dirname(__DIR__, 2);
$temporaryPath = sys_get_temp_dir().'/bobbin-replay-live-'.bin2hex(random_bytes(8));
$databasePath = $temporaryPath.'/database.sqlite';
$receiverOutput = $temporaryPath.'/receiver.json';
$appPort = availablePort();
$receiverPort = availablePort();
$appUrl = 'http://127.0.0.1:'.$appPort;
$receiverUrl = 'http://127.0.0.1:'.$receiverPort.'/receive?configured=yes';
$email = bin2hex(random_bytes(12)).'@example.test';
$password = bin2hex(random_bytes(24));
$appKey = 'base64:'.base64_encode(random_bytes(32));
$environment = [
    'APP_ENV' => 'local',
    'APP_DEBUG' => 'false',
    'APP_KEY' => $appKey,
    'APP_URL' => $appUrl,
    'BCRYPT_ROUNDS' => '4',
    'BOBBIN_ALLOW_PRIVATE_DESTINATIONS' => 'true',
    'CACHE_STORE' => 'database',
    'DB_CONNECTION' => 'sqlite',
    'DB_DATABASE' => $databasePath,
    'LOG_CHANNEL' => 'stderr',
    'QUEUE_CONNECTION' => 'database',
    'SESSION_DRIVER' => 'database',
];
$appServer = null;
$receiverServer = null;

mkdir($temporaryPath, 0700, true);
touch($databasePath);

try {
    runProcess([PHP_BINARY, 'artisan', 'migrate', '--force', '--no-interaction'], $basePath, $environment);

    foreach ($environment as $name => $value) {
        putenv($name.'='.$value);
        $_ENV[$name] = $value;
        $_SERVER[$name] = $value;
    }

    $app = require $basePath.'/bootstrap/app.php';
    $app->make(Kernel::class)->bootstrap();
    $user = User::query()->create([
        'name' => 'Bobbin live verifier',
        'email' => $email,
        'password' => Hash::make($password),
    ]);
    DB::table('team_user')->insert([
        'team_id' => DB::table('teams')->value('id'),
        'user_id' => $user->getKey(),
    ]);

    $receiverEnvironment = $environment + ['BOBBIN_LIVE_RECEIVER_OUTPUT' => $receiverOutput];
    $receiverServer = new Process([
        PHP_BINARY,
        '-S',
        '127.0.0.1:'.$receiverPort,
        $basePath.'/tests/Live/replay-receiver.php',
    ], $basePath, $receiverEnvironment);
    $receiverServer->start();

    $appServer = new Process([
        PHP_BINARY,
        'artisan',
        'serve',
        '--host=127.0.0.1',
        '--port='.$appPort,
        '--no-reload',
    ], $basePath, $environment);
    $appServer->start();

    waitForUrl($appUrl.'/up');
    waitForUrl('http://127.0.0.1:'.$receiverPort.'/__health');

    $cookies = new CookieJar;
    $client = new Client([
        'base_uri' => $appUrl,
        'cookies' => $cookies,
        'http_errors' => false,
        'allow_redirects' => false,
        'timeout' => 10,
    ]);

    $login = sendHarnessRequest($client, 'GET', '/bfc/login', 200);
    $loginToken = csrfToken($login);
    sendHarnessRequest($client, 'POST', '/bfc/login', 302, [
        'form_params' => [
            '_token' => $loginToken,
            'email' => $email,
            'password' => $password,
        ],
    ]);

    $create = sendHarnessRequest($client, 'GET', '/endpoints/create', 200);
    $created = sendHarnessResponse($client, 'POST', '/endpoints', 302, [
        'form_params' => [
            '_token' => csrfToken($create),
            'name' => 'Live replay '.bin2hex(random_bytes(4)),
        ],
    ]);
    $endpointPath = responseLocation($created);

    if (preg_match('~/endpoints/(\d+)$~', $endpointPath, $endpointMatch) !== 1) {
        throw new RuntimeException('The protected endpoint flow did not return an endpoint location.');
    }

    $endpointId = $endpointMatch[1];
    $endpointDetail = sendHarnessRequest($client, 'GET', $endpointPath, 200);

    if (preg_match('~/hooks/([a-f0-9]{32})~', $endpointDetail, $hookMatch) !== 1) {
        throw new RuntimeException('The endpoint detail did not expose its capture URL.');
    }

    $settingsPath = '/endpoints/'.$endpointId.'/settings';
    $settings = sendHarnessRequest($client, 'GET', $settingsPath, 200);
    sendHarnessRequest($client, 'POST', '/endpoints/'.$endpointId.'/destination', 302, [
        'form_params' => [
            '_token' => csrfToken($settings),
            '_method' => 'PUT',
            'destination_url' => $receiverUrl,
        ],
    ]);

    $rawQuery = 'tag=one&tag=two&blank=&encoded=%2F%2b%20';
    $body = '{"live":"'.bin2hex(random_bytes(8)).'"}';
    sendHarnessRequest($client, 'PATCH', '/hooks/'.$hookMatch[1].'?'.$rawQuery, 204, [
        'body' => $body,
        'headers' => [
            'Content-Type' => 'application/json',
            'X-Bobbin-Live' => ['first-value', 'second-value'],
        ],
    ]);

    $captureIndex = sendHarnessRequest($client, 'GET', '/endpoints/'.$endpointId.'/captures', 200);

    if (preg_match('~href="[^"]*(/endpoints/'.$endpointId.'/captures/(\d+))"~', $captureIndex, $captureMatch) !== 1) {
        throw new RuntimeException('The protected capture index did not expose the captured row.');
    }

    $capturePath = html_entity_decode($captureMatch[1], ENT_QUOTES);
    $captureId = $captureMatch[2];
    $captureDetail = sendHarnessRequest($client, 'GET', $capturePath, 200);
    sendHarnessRequest($client, 'POST', $capturePath.'/replay', 302, [
        'form_params' => ['_token' => csrfToken($captureDetail)],
    ]);

    runProcess([
        PHP_BINARY,
        'artisan',
        'queue:work',
        'database',
        '--stop-when-empty',
        '--sleep=1',
        '--tries=4',
        '--timeout=30',
        '--no-interaction',
    ], $basePath, $environment, 40);

    $terminalDetail = sendHarnessRequest($client, 'GET', $capturePath, 200);

    if (! str_contains($terminalDetail, 'data-testid="delivery-history"')
        || ! str_contains($terminalDetail, 'succeeded')
        || ! str_contains($terminalDetail, '>202<')) {
        throw new RuntimeException('The protected capture detail did not show the successful terminal delivery.');
    }

    if (preg_match('/Delivery #(\d+)/', $terminalDetail, $deliveryMatch) !== 1) {
        throw new RuntimeException('The protected capture detail did not identify the delivery row.');
    }

    $observed = waitForReceiver($receiverOutput);
    $observedValues = array_map(trim(...), explode(',', (string) ($observed['headers']['x-bobbin-live'] ?? '')));

    if (($observed['method'] ?? null) !== 'PATCH'
        || ($observed['query'] ?? null) !== 'configured=yes&'.$rawQuery
        || ($observed['body'] ?? null) !== $body
        || $observedValues !== ['first-value', 'second-value']) {
        throw new RuntimeException('The receiver did not observe the captured request with exact replay fidelity.');
    }

    fwrite(STDOUT, sprintf(
        "LIVE VERIFIED: capture #%s replayed as delivery #%s via the database queue; receiver observed PATCH, exact raw query/body, two header values, and HTTP 202 produced visible succeeded status.\n",
        $captureId,
        $deliveryMatch[1],
    ));
} finally {
    $appServer?->stop(1);
    $receiverServer?->stop(1);

    foreach (glob($temporaryPath.'/*') ?: [] as $path) {
        if (is_file($path)) {
            unlink($path);
        }
    }

    if (is_dir($temporaryPath)) {
        rmdir($temporaryPath);
    }
}

function availablePort(): int
{
    $socket = stream_socket_server('tcp://127.0.0.1:0', $errorCode, $errorMessage);

    if ($socket === false) {
        throw new RuntimeException('Unable to reserve a local verification port.');
    }

    $name = stream_socket_get_name($socket, false);
    fclose($socket);

    if (! is_string($name) || preg_match('/:(\d+)$/', $name, $matches) !== 1) {
        throw new RuntimeException('Unable to read the local verification port.');
    }

    return (int) $matches[1];
}

/** @param array<string, string> $environment */
function runProcess(array $command, string $workingDirectory, array $environment, int $timeout = 30): void
{
    $process = new Process($command, $workingDirectory, $environment);
    $process->setTimeout($timeout);
    $process->run();

    if (! $process->isSuccessful()) {
        throw new RuntimeException('A live verification process failed: '.$process->getErrorOutput().$process->getOutput());
    }
}

function waitForUrl(string $url): void
{
    $client = new Client(['http_errors' => false, 'timeout' => 1]);
    $deadline = microtime(true) + 15;

    do {
        try {
            $response = $client->get($url);

            if ($response->getStatusCode() < 500) {
                return;
            }
        } catch (Throwable) {
        }

        Sleep::usleep(100_000);
    } while (microtime(true) < $deadline);

    throw new RuntimeException('A local live verification server did not become ready.');
}

/** @param array<string, mixed> $options */
function sendHarnessRequest(Client $client, string $method, string $path, int $expectedStatus, array $options = []): string
{
    return (string) sendHarnessResponse($client, $method, $path, $expectedStatus, $options)->getBody();
}

/** @param array<string, mixed> $options */
function sendHarnessResponse(
    Client $client,
    string $method,
    string $path,
    int $expectedStatus,
    array $options = [],
): ResponseInterface {
    $response = $client->request($method, $path, $options);

    if ($response->getStatusCode() !== $expectedStatus) {
        throw new RuntimeException(sprintf(
            'Expected HTTP %d from %s %s, received %d.',
            $expectedStatus,
            $method,
            $path,
            $response->getStatusCode(),
        ));
    }

    return $response;
}

function csrfToken(string $html): string
{
    if (preg_match('/name="_token" value="([^"]+)"/', $html, $matches) !== 1) {
        throw new RuntimeException('A protected form did not expose a CSRF token.');
    }

    return html_entity_decode($matches[1], ENT_QUOTES);
}

function responseLocation(ResponseInterface $response): string
{
    $location = $response->getHeaderLine('Location');
    $path = parse_url($location, PHP_URL_PATH);

    if (! is_string($path) || $path === '') {
        throw new RuntimeException('A protected form response did not include a valid redirect location.');
    }

    return $path;
}

/** @return array<string, mixed> */
function waitForReceiver(string $path): array
{
    $deadline = microtime(true) + 10;

    do {
        if (is_file($path)) {
            $contents = file_get_contents($path);

            if (is_string($contents) && $contents !== '') {
                return json_decode($contents, true, flags: JSON_THROW_ON_ERROR);
            }
        }

        Sleep::usleep(100_000);
    } while (microtime(true) < $deadline);

    throw new RuntimeException('The local receiver did not observe a replay request.');
}
