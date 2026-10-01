<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\McpReplayRequest;

final readonly class ReconcileExpiredReplayRequests
{
    public function __construct(private SettleReplayRequest $settlement) {}

    public function reconcile(): int
    {
        $cutoff = now();
        $settled = 0;

        do {
            $ids = McpReplayRequest::query()
                ->where('outcome', McpReplayRequest::OUTCOME_IN_PROGRESS)
                ->whereNotNull('lease_expires_at')
                ->where('lease_expires_at', '<=', $cutoff)
                ->orderBy('id')
                ->limit(100)
                ->pluck('id');

            foreach ($ids as $id) {
                if ($this->settlement->reconcileExpired((int) $id, $cutoff) !== null) {
                    $settled++;
                }
            }
        } while ($ids->count() === 100);

        return $settled;
    }
}
