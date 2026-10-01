<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Delivery;
use App\Models\McpReplayRequest;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use UnexpectedValueException;

final readonly class SettleReplayRequest
{
    public function __construct(private ReplaySettlementFaultInjector $faults) {}

    public function recordQueueAcceptance(int $replayRequestId): void
    {
        McpReplayRequest::query()
            ->whereKey($replayRequestId)
            ->where('outcome', McpReplayRequest::OUTCOME_IN_PROGRESS)
            ->update([
                'queue_accepted_at' => now(),
                'lease_expires_at' => now()->addSeconds(McpReplayRequest::LEASE_SECONDS),
            ]);
    }

    public function accepted(int $replayRequestId): string
    {
        return $this->transition($replayRequestId, McpReplayRequest::OUTCOME_ACCEPTED);
    }

    public function acceptedFromFailedJob(int $replayRequestId): string
    {
        $this->faults->beforeFailedJobSettlement();

        return $this->accepted($replayRequestId);
    }

    public function acceptedFromHandledJob(int $replayRequestId): string
    {
        $this->faults->beforeHandledJobSettlement();

        return $this->accepted($replayRequestId);
    }

    public function unknownFromDispatchException(int $replayRequestId): string
    {
        $this->faults->beforeDispatchExceptionSettlement();

        return $this->transition($replayRequestId, McpReplayRequest::OUTCOME_UNKNOWN);
    }

    public function reconcileExpired(int $replayRequestId, CarbonInterface $cutoff): ?string
    {
        return DB::transaction(function () use ($replayRequestId, $cutoff): ?string {
            $replay = McpReplayRequest::query()->lockForUpdate()->find($replayRequestId);

            if ($replay === null
                || $replay->outcome !== McpReplayRequest::OUTCOME_IN_PROGRESS
                || $replay->lease_expires_at === null
                || $replay->lease_expires_at->isAfter($cutoff)) {
                return null;
            }

            $queueAccepted = $replay->queue_accepted_at !== null
                || Delivery::query()
                    ->whereKey($replay->delivery_id)
                    ->where(function ($query): void {
                        $query->where('attempt_count', '>', 0)
                            ->orWhereNotNull('started_at')
                            ->orWhere('status', '!=', Delivery::STATUS_QUEUED);
                    })
                    ->exists();
            $outcome = $queueAccepted
                ? McpReplayRequest::OUTCOME_ACCEPTED
                : McpReplayRequest::OUTCOME_UNKNOWN;

            $updated = McpReplayRequest::query()
                ->whereKey($replayRequestId)
                ->where('outcome', McpReplayRequest::OUTCOME_IN_PROGRESS)
                ->where('lease_expires_at', '<=', $cutoff)
                ->update([
                    'outcome' => $outcome,
                    'lease_expires_at' => null,
                ]);

            return $updated === 1 ? $outcome : null;
        }, 3);
    }

    private function transition(int $replayRequestId, string $outcome): string
    {
        McpReplayRequest::query()
            ->whereKey($replayRequestId)
            ->where('outcome', McpReplayRequest::OUTCOME_IN_PROGRESS)
            ->update([
                'outcome' => $outcome,
                'lease_expires_at' => null,
            ]);

        $persisted = McpReplayRequest::query()->whereKey($replayRequestId)->value('outcome');

        if (! in_array($persisted, [
            McpReplayRequest::OUTCOME_ACCEPTED,
            McpReplayRequest::OUTCOME_UNKNOWN,
        ], true)) {
            throw new UnexpectedValueException('The replay outcome could not be settled.');
        }

        return $persisted;
    }
}
