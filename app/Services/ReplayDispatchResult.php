<?php

declare(strict_types=1);

namespace App\Services;

final readonly class ReplayDispatchResult
{
    public function __construct(
        public int $replayRequestId,
        public int $deliveryId,
        public int $captureId,
        public int $destinationId,
        public string $status,
        public bool $duplicate,
        public string $outcome,
    ) {}
}
