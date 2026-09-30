<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Delivery;

final readonly class ReplayDispatchResult
{
    public function __construct(
        public Delivery $delivery,
        public bool $duplicate,
    ) {}
}
