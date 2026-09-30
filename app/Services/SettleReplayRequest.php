<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\McpReplayRequest;
use UnexpectedValueException;

final class SettleReplayRequest
{
    public function accepted(int $replayRequestId): string
    {
        return $this->transition($replayRequestId, McpReplayRequest::OUTCOME_ACCEPTED);
    }

    public function unknown(int $replayRequestId): string
    {
        return $this->transition($replayRequestId, McpReplayRequest::OUTCOME_UNKNOWN);
    }

    private function transition(int $replayRequestId, string $outcome): string
    {
        McpReplayRequest::query()
            ->whereKey($replayRequestId)
            ->where('outcome', McpReplayRequest::OUTCOME_IN_PROGRESS)
            ->update(['outcome' => $outcome]);

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
