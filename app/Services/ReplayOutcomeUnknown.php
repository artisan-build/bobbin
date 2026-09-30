<?php

declare(strict_types=1);

namespace App\Services;

use RuntimeException;
use Throwable;

final class ReplayOutcomeUnknown extends RuntimeException
{
    public function __construct(public readonly int $deliveryId, ?Throwable $previous = null)
    {
        parent::__construct('The replay queue outcome is unknown.', 0, $previous);
    }
}
