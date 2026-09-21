<?php

declare(strict_types=1);

namespace App\Services;

use App\Contracts\ResolvesHostAddresses;

final class NativeHostAddressResolver implements ResolvesHostAddresses
{
    public function resolve(string $host): array
    {
        $records = dns_get_record($host, DNS_A | DNS_AAAA);

        if ($records === false) {
            return [];
        }

        $addresses = [];

        foreach ($records as $record) {
            $address = $record['ip'] ?? $record['ipv6'] ?? null;

            if (is_string($address)) {
                $addresses[$address] = true;
            }
        }

        return array_keys($addresses);
    }
}
