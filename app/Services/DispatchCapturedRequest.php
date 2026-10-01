<?php

declare(strict_types=1);

namespace App\Services;

use App\Jobs\DeliverCapturedRequest;
use App\Models\CapturedRequest;
use App\Models\Delivery;
use App\Models\Destination;
use App\Models\Endpoint;
use App\Models\McpReplayDispatch;
use App\Models\McpReplayRequest;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;
use UnexpectedValueException;

final readonly class DispatchCapturedRequest
{
    public function __construct(
        private Dispatcher $jobs,
        private ReplayDispatchFaultInjector $faults,
    ) {}

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
            if (! $this->claimTransaction($actorIdentifier, $idempotencyKey, $requestHash)) {
                return new ReplayDispatchResult(
                    null,
                    null,
                    null,
                    null,
                    null,
                    true,
                    McpReplayRequest::OUTCOME_IN_PROGRESS,
                );
            }

            $now = now();
            $inserted = McpReplayRequest::query()->insertOrIgnore([
                'team_id' => $teamId,
                'actor_identifier' => $actorIdentifier,
                'idempotency_key' => $idempotencyKey,
                'request_hash' => $requestHash,
                'outcome' => McpReplayRequest::OUTCOME_IN_PROGRESS,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            if ($inserted === 1) {
                $this->faults->afterReceiptWrite();
            }
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
                if ($replay->delivery_capture_id === null
                    || $replay->delivery_destination_id === null
                    || $replay->delivery_status === null) {
                    throw (new ModelNotFoundException)->setModel(McpReplayRequest::class);
                }

                return new ReplayDispatchResult(
                    $replay->id,
                    $replay->delivery_id,
                    $replay->delivery_capture_id,
                    $replay->delivery_destination_id,
                    $replay->delivery_status,
                    true,
                    $replay->outcome,
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
            $this->faults->afterDeliveryWrite();
            $replay->update([
                'delivery_id' => $delivery->id,
                'delivery_capture_id' => $delivery->captured_request_id,
                'delivery_destination_id' => $delivery->destination_id,
                'delivery_status' => $delivery->status,
                'outcome' => McpReplayRequest::OUTCOME_ACCEPTED,
            ]);
            McpReplayDispatch::query()->create([
                'replay_request_id' => $replay->id,
                'delivery_id' => $delivery->id,
            ]);
            $this->faults->afterOutboxWrite();

            return new ReplayDispatchResult(
                $replay->id,
                $delivery->id,
                $delivery->captured_request_id,
                $delivery->destination_id,
                $delivery->status,
                false,
                McpReplayRequest::OUTCOME_ACCEPTED,
            );
        });

        if ($result->duplicate) {
            if ($result->outcome === McpReplayRequest::OUTCOME_UNKNOWN) {
                if ($result->deliveryId === null) {
                    throw (new ModelNotFoundException)->setModel(McpReplayRequest::class);
                }

                throw new ReplayOutcomeUnknown($result->deliveryId);
            }

            return $result;
        }

        if ($result->deliveryId === null || $result->replayRequestId === null) {
            return $result;
        }

        try {
            $this->jobs->dispatch(new DeliverCapturedRequest(
                $result->deliveryId,
                $result->replayRequestId,
                (string) Str::uuid(),
            ));
        } catch (Throwable $exception) {
            report($exception);
        }

        return $result;
    }

    private function claimTransaction(string $actorIdentifier, string $idempotencyKey, string $requestHash): bool
    {
        if (DB::getDriverName() !== 'pgsql') {
            return true;
        }

        $claim = DB::selectOne(
            'select case when pg_try_advisory_xact_lock(hashtextextended(?, 0)) then 1 else 0 end as acquired',
            [$actorIdentifier."\0".$idempotencyKey."\0".$requestHash],
        );

        return (int) $claim->acquired === 1;
    }
}
