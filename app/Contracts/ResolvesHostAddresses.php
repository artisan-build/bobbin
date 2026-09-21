<?php

declare(strict_types=1);

namespace App\Contracts;

interface ResolvesHostAddresses
{
    /** @return list<string> */
    public function resolve(string $host): array;
}
