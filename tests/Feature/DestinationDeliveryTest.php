<?php

declare(strict_types=1);

use App\Jobs\DeliverCapturedRequest;
use App\Models\CapturedRequest;
use App\Models\Delivery;
use App\Models\Destination;
use App\Models\Endpoint;
use App\Services\DispatchCapturedRequest;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

function deliveryEndpoint(): Endpoint
{
    $endpoint = new Endpoint(['name' => 'Delivery '.Str::random(12)]);
    $endpoint->team_id = (int) DB::table('teams')->value('id');
    $endpoint->save();

    return $endpoint;
}

function deliveryDestination(Endpoint $endpoint, bool $active = true): Destination
{
    return $endpoint->destinations()->create([
        'url' => 'https://destination.example.test/receive/'.Str::random(8),
        'is_active' => $active,
    ]);
}

/**
 * @param  array<string, list<string>>  $headers
 */
function deliveryCapture(
    Endpoint $endpoint,
    string $rawQuery = 'event=created',
    string $body = '{"delivery":true}',
    array $headers = ['content-type' => ['application/json']],
): CapturedRequest {
    return $endpoint->capturedRequests()->create([
        'method' => 'POST',
        'raw_query' => $rawQuery,
        'headers' => $headers,
        'body' => $body,
        'received_at' => now(),
    ]);
}

function deliveryRecord(Destination $destination, CapturedRequest $capturedRequest): Delivery
{
    return $destination->deliveries()->create([
        'captured_request_id' => $capturedRequest->getKey(),
    ]);
}

function expectRetry(DeliverCapturedRequest $job): void
{
    try {
        $job->handle();
        test()->fail('Expected the delivery attempt to request a retry.');
    } catch (RuntimeException $exception) {
        expect($exception->getMessage())->toBe('Destination delivery will be retried.');
    }
}

it('models additive destination-linked delivery history without an endpoint foreign key', function (): void {
    $endpoint = deliveryEndpoint();
    $firstDestination = deliveryDestination($endpoint);
    $secondDestination = deliveryDestination($endpoint, false);
    $capture = deliveryCapture($endpoint);
    $delivery = deliveryRecord($firstDestination, $capture);

    expect(Schema::hasColumns('deliveries', [
        'destination_id',
        'captured_request_id',
        'status',
        'attempt_count',
        'response_status',
        'last_error',
        'started_at',
        'completed_at',
    ]))->toBeTrue()
        ->and(Schema::hasColumn('deliveries', 'endpoint_id'))->toBeFalse()
        ->and($endpoint->destinations()->count())->toBe(2)
        ->and($delivery->destination->is($firstDestination))->toBeTrue()
        ->and($delivery->capturedRequest->is($capture))->toBeTrue()
        ->and($capture->deliveries()->sole()->is($delivery))->toBeTrue()
        ->and($secondDestination->deliveries()->count())->toBe(0);
});

it('creates exactly one queued delivery job for exactly one active destination', function (): void {
    Queue::fake();
    $endpoint = deliveryEndpoint();
    $activeDestination = deliveryDestination($endpoint);
    deliveryDestination($endpoint, false);
    $capture = deliveryCapture($endpoint);

    $delivery = resolve(DispatchCapturedRequest::class)->dispatch($capture);

    expect($delivery->destination_id)->toBe($activeDestination->id)
        ->and($delivery->captured_request_id)->toBe($capture->id)
        ->and($delivery->status)->toBe(Delivery::STATUS_QUEUED)
        ->and($delivery->attempt_count)->toBe(0)
        ->and(Delivery::query()->count())->toBe(1);
    Queue::assertPushed(DeliverCapturedRequest::class, 1);
    Queue::assertPushed(
        DeliverCapturedRequest::class,
        fn (DeliverCapturedRequest $job): bool => $job->deliveryId === $delivery->id,
    );
});

it('fails closed without a delivery or job unless exactly one destination is active', function (int $activeDestinations): void {
    Queue::fake();
    $endpoint = deliveryEndpoint();
    $capture = deliveryCapture($endpoint);

    for ($index = 0; $index < $activeDestinations; $index++) {
        deliveryDestination($endpoint);
    }

    expect(fn () => resolve(DispatchCapturedRequest::class)->dispatch($capture))
        ->toThrow(UnexpectedValueException::class, 'exactly one active destination');
    expect(Delivery::query()->count())->toBe(0);
    Queue::assertNothingPushed();
})->with([
    'zero active destinations' => 0,
    'two active destinations' => 2,
]);

it('forwards the stored request with exact query body and permitted multi-value headers', function (): void {
    $endpoint = deliveryEndpoint();
    $destination = deliveryDestination($endpoint);
    $destination->update(['url' => 'https://destination.example.test/webhook']);
    $rawQuery = 'tag=one&tag=two&blank=&encoded=%2F%2b%20';
    $body = "\x00stored-body-".Str::random(12)."\xFF";
    $capture = deliveryCapture($endpoint, $rawQuery, $body, [
        'Host' => ['captured-host.example.test'],
        'Content-Length' => ['99999'],
        'Connection' => ['keep-alive, X-Connection-Only'],
        'Keep-Alive' => ['timeout=5'],
        'Proxy-Authenticate' => ['captured-proxy-authenticate'],
        'Proxy-Authorization' => ['captured-proxy-authorization'],
        'TE' => ['trailers'],
        'Trailer' => ['X-Trailer'],
        'Transfer-Encoding' => ['chunked'],
        'Upgrade' => ['websocket'],
        'X-Connection-Only' => ['must-not-survive'],
        'X-Multi-Value-Sentinel' => ['first-value', 'second-value'],
    ]);
    $capture->update(['method' => 'PATCH']);
    $delivery = deliveryRecord($destination, $capture);
    Http::fake(fn (): mixed => Http::response('', 202));

    (new DeliverCapturedRequest($delivery->id))->handle();

    Http::assertSent(function (Request $request) use ($rawQuery, $body): bool {
        expect($request->method())->toBe('PATCH')
            ->and($request->url())->toBe('https://destination.example.test/webhook?'.$rawQuery)
            ->and($request->body())->toBe($body)
            ->and($request->header('X-Multi-Value-Sentinel'))->toBe(['first-value', 'second-value'])
            ->and($request->header('Host'))->not->toContain('captured-host.example.test')
            ->and($request->header('Content-Length'))->not->toContain('99999')
            ->and($request->hasHeader('Connection'))->toBeFalse()
            ->and($request->hasHeader('Keep-Alive'))->toBeFalse()
            ->and($request->hasHeader('Proxy-Authenticate'))->toBeFalse()
            ->and($request->hasHeader('Proxy-Authorization'))->toBeFalse()
            ->and($request->hasHeader('TE'))->toBeFalse()
            ->and($request->hasHeader('Trailer'))->toBeFalse()
            ->and($request->hasHeader('Transfer-Encoding'))->toBeFalse()
            ->and($request->hasHeader('Upgrade'))->toBeFalse()
            ->and($request->hasHeader('X-Connection-Only'))->toBeFalse();

        return true;
    });

    $delivery->refresh();
    expect($delivery->status)->toBe(Delivery::STATUS_SUCCEEDED)
        ->and($delivery->attempt_count)->toBe(1)
        ->and($delivery->response_status)->toBe(202)
        ->and($delivery->last_error)->toBeNull()
        ->and($delivery->started_at)->not->toBeNull()
        ->and($delivery->completed_at)->not->toBeNull();

    (new DeliverCapturedRequest($delivery->id))->handle();
    Http::assertSentCount(1);
    expect($delivery->refresh()->attempt_count)->toBe(1);
});

it('fails a non-429 client response immediately and does not resend a terminal delivery', function (): void {
    $endpoint = deliveryEndpoint();
    $delivery = deliveryRecord(deliveryDestination($endpoint), deliveryCapture($endpoint));
    Http::fake(fn (): mixed => Http::response('must not be persisted', 422, [
        'X-Response-Secret' => 'must-not-be-persisted',
    ]));
    $job = new DeliverCapturedRequest($delivery->id);

    $job->handle();
    $job->handle();

    $delivery->refresh();
    Http::assertSentCount(1);
    expect($delivery->status)->toBe(Delivery::STATUS_FAILED)
        ->and($delivery->attempt_count)->toBe(1)
        ->and($delivery->response_status)->toBe(422)
        ->and($delivery->last_error)->toBe('Destination returned HTTP 422.')
        ->and($delivery->last_error)->not->toContain('must not be persisted', 'must-not-be-persisted')
        ->and($delivery->completed_at)->not->toBeNull();
});

it('returns retryable HTTP responses to queued state with latest attempt facts', function (int $status): void {
    $endpoint = deliveryEndpoint();
    $delivery = deliveryRecord(deliveryDestination($endpoint), deliveryCapture($endpoint));
    Http::fake(fn (): mixed => Http::response('response secret', $status));

    expectRetry(new DeliverCapturedRequest($delivery->id));

    $delivery->refresh();
    expect($delivery->status)->toBe(Delivery::STATUS_QUEUED)
        ->and($delivery->attempt_count)->toBe(1)
        ->and($delivery->response_status)->toBe($status)
        ->and($delivery->last_error)->toBe("Destination returned HTTP {$status}.")
        ->and($delivery->last_error)->not->toContain('response secret')
        ->and($delivery->started_at)->not->toBeNull()
        ->and($delivery->completed_at)->toBeNull();
})->with([
    'rate limited' => 429,
    'server error' => 503,
]);

it('replaces retry failure facts when a later attempt succeeds', function (): void {
    $endpoint = deliveryEndpoint();
    $delivery = deliveryRecord(deliveryDestination($endpoint), deliveryCapture($endpoint));
    Http::fake([
        '*' => Http::sequence()
            ->push('first response secret', 503)
            ->push('', 204),
    ]);
    $job = new DeliverCapturedRequest($delivery->id);

    expectRetry($job);
    $job->handle();

    $delivery->refresh();
    Http::assertSentCount(2);
    expect($delivery->status)->toBe(Delivery::STATUS_SUCCEEDED)
        ->and($delivery->attempt_count)->toBe(2)
        ->and($delivery->response_status)->toBe(204)
        ->and($delivery->last_error)->toBeNull()
        ->and($delivery->completed_at)->not->toBeNull();
});

it('returns network errors to queued state without persisting exception details', function (): void {
    $endpoint = deliveryEndpoint();
    $delivery = deliveryRecord(deliveryDestination($endpoint), deliveryCapture($endpoint));
    $secret = 'destination-credential-'.Str::random(12);
    Http::fake(Http::failedConnection('Connection failed with '.$secret));

    expectRetry(new DeliverCapturedRequest($delivery->id));

    $delivery->refresh();
    expect($delivery->status)->toBe(Delivery::STATUS_QUEUED)
        ->and($delivery->attempt_count)->toBe(1)
        ->and($delivery->response_status)->toBeNull()
        ->and($delivery->last_error)->toBe('Destination connection failed.')
        ->and($delivery->last_error)->not->toContain($secret)
        ->and($delivery->completed_at)->toBeNull();
});

it('exhausts the declared finite retry policy and leaves a terminal failure', function (): void {
    $endpoint = deliveryEndpoint();
    $delivery = deliveryRecord(deliveryDestination($endpoint), deliveryCapture($endpoint));
    Http::fake(fn (): mixed => Http::response('', 500));
    $job = new DeliverCapturedRequest($delivery->id);

    expect($job->tries)->toBe(4)
        ->and($job->backoff)->toBe([10, 60, 300]);

    expectRetry($job);
    expectRetry($job);
    expectRetry($job);
    $job->handle();

    $delivery->refresh();
    Http::assertSentCount(4);
    expect($delivery->status)->toBe(Delivery::STATUS_FAILED)
        ->and($delivery->attempt_count)->toBe(4)
        ->and($delivery->response_status)->toBe(500)
        ->and($delivery->last_error)->toBe('Destination returned HTTP 500.')
        ->and($delivery->completed_at)->not->toBeNull();

    $job->handle();
    Http::assertSentCount(4);
    expect($delivery->refresh()->attempt_count)->toBe(4);
});
