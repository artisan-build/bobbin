<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Contracts\ResolvesHostAddresses;

final class ReplayHostResolver implements ResolvesHostAddresses
{
    /** @param array<string, list<string>> $answers */
    public function __construct(public array $answers = []) {}

    public function resolve(string $host): array
    {
        return $this->answers[$host] ?? [];
    }
}
