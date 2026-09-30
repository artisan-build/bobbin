<?php

declare(strict_types=1);

namespace App\Services;

use App\Jobs\DeliverCapturedRequest;
use App\Models\CapturedRequest;
use App\Models\Delivery;
use App\Models\Destination;
use App\Models\Endpoint;
use App\Models\McpReplayRequest;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Throwable;
use UnexpectedValueException;

final readonly class DispatchCapturedRequest
{
    public function __construct(private Dispatcher $jobs) {}

    public function dispatch(CapturedRequest $capturedRequest): Delivery
    {
        return DB::transaction(function () use ($capturedRequest): Delivery {
            Endpoint::query()->whereKey($capturedRequest->endpoint_id)->lockForUpdate()->firstOrFail();

            $destinations = Destination::query()
                ->where('endpoint_id', $capturedRequest->endpoint_id)
                ->where('is_active', true)
                ->limit(2)
                ->get();

            if ($destinations->count() !== 1) {
                throw new UnexpectedValueException('The endpoint must have exactly one active destination.');
            }

            $delivery = $destinations->sole()
                ->deliveries()
                ->create([
                    'captured_request_id' => $capturedRequest->getKey(),
                ]);

            dispatch(new DeliverCapturedRequest($delivery->getKey()))->afterCommit();

            return $delivery;
        });
    }

    public function dispatchIdempotently(
        int $teamId,
        int $endpointId,
        int $captureId,
        int $destinationId,
        string $actorIdentifier,
        string $idempotencyKey,
    ): ReplayDispatchResult {
        $requestHash = hash('sha256', json_encode([
            'team_id' => $teamId,
            'endpoint_id' => $endpointId,
            'capture_id' => $captureId,
            'destination_id' => $destinationId,
        ], JSON_THROW_ON_ERROR));

        $result = DB::transaction(function () use (
            $teamId,
            $endpointId,
            $captureId,
            $destinationId,
            $actorIdentifier,
            $idempotencyKey,
            $requestHash,
        ): ReplayDispatchResult {
            $now = now();
            $inserted = McpReplayRequest::query()->insertOrIgnore([
                'team_id' => $teamId,
                'actor_identifier' => $actorIdentifier,
                'idempotency_key' => $idempotencyKey,
                'request_hash' => $requestHash,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $replay = McpReplayRequest::query()
                ->where('team_id', $teamId)
                ->where('actor_identifier', $actorIdentifier)
                ->where('idempotency_key', $idempotencyKey)
                ->lockForUpdate()
                ->firstOrFail();

            if (! hash_equals($replay->request_hash, $requestHash)) {
                throw new ReplayIdempotencyConflict('The idempotency key belongs to a different replay request.');
            }

            if ($replay->delivery_id !== null) {
                return new ReplayDispatchResult(
                    Delivery::query()->findOrFail($replay->delivery_id),
                    true,
                );
            }

            if ($inserted !== 1) {
                throw (new ModelNotFoundException)->setModel(McpReplayRequest::class);
            }

            Endpoint::query()
                ->where('team_id', $teamId)
                ->whereKey($endpointId)
                ->lockForUpdate()
                ->firstOrFail();
            $capture = CapturedRequest::query()
                ->where('endpoint_id', $endpointId)
                ->whereKey($captureId)
                ->lockForUpdate()
                ->firstOrFail();
            $destination = Destination::query()
                ->where('endpoint_id', $endpointId)
                ->where('is_active', true)
                ->whereKey($destinationId)
                ->lockForUpdate()
                ->firstOrFail();

            $delivery = $destination->deliveries()->create([
                'captured_request_id' => $capture->id,
            ]);
            $replay->update(['delivery_id' => $delivery->id]);

            return new ReplayDispatchResult($delivery, false);
        });

        if (! $result->duplicate) {
            try {
                $this->jobs->dispatch(new DeliverCapturedRequest($result->delivery->id));
            } catch (Throwable $exception) {
                throw new ReplayOutcomeUnknown($result->delivery->id, $exception);
            }
        }

        return $result;
    }
}
