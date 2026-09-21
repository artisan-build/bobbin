<?php

declare(strict_types=1);

namespace App\Services;

use App\Contracts\ResolvesHostAddresses;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\IpUtils;
use Throwable;

final readonly class DestinationUrlPolicy
{
    /** @var list<string> */
    private const array NON_PUBLIC_NETWORKS = [
        '0.0.0.0/8',
        '10.0.0.0/8',
        '100.64.0.0/10',
        '127.0.0.0/8',
        '169.254.0.0/16',
        '172.16.0.0/12',
        '192.0.0.0/24',
        '192.0.2.0/24',
        '192.88.99.0/24',
        '192.168.0.0/16',
        '198.18.0.0/15',
        '198.51.100.0/24',
        '203.0.113.0/24',
        '224.0.0.0/4',
        '240.0.0.0/4',
        '::/128',
        '::1/128',
        '::ffff:0:0/96',
        '64:ff9b::/96',
        '64:ff9b:1::/48',
        '100::/64',
        '2001::/23',
        '2001:db8::/32',
        '2002::/16',
        '3fff::/20',
        '5f00::/16',
        'fc00::/7',
        'fe80::/10',
        'fec0::/10',
        'ff00::/8',
    ];

    public function __construct(private ResolvesHostAddresses $resolver) {}

    public function assertSafe(string $url): void
    {
        if (str_contains($url, '{') || str_contains($url, '}') || filter_var($url, FILTER_VALIDATE_URL) === false) {
            $this->reject();
        }

        try {
            $parts = parse_url($url);
        } catch (Throwable) {
            $this->reject();
        }

        if (! is_array($parts)
            || ! isset($parts['scheme'], $parts['host'])
            || ! in_array(strtolower((string) $parts['scheme']), ['http', 'https'], true)
            || (string) $parts['host'] === ''
            || isset($parts['user'])
            || isset($parts['pass'])
            || isset($parts['fragment'])) {
            $this->reject();
        }

        $host = strtolower(rtrim(trim((string) $parts['host'], '[]'), '.'));
        $allowPrivate = config('bobbin.allow_private_destinations') === true && app()->environment('local');

        if ($host === 'localhost' || str_ends_with($host, '.localhost')) {
            if ($allowPrivate) {
                return;
            }

            $this->reject();
        }

        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            if (! $allowPrivate && ! $this->isPublicAddress($host)) {
                $this->reject();
            }

            return;
        }

        try {
            $addresses = $this->resolver->resolve($host);
        } catch (Throwable) {
            $this->reject();
        }

        if ($addresses === []) {
            $this->reject();
        }

        foreach ($addresses as $address) {
            if (filter_var($address, FILTER_VALIDATE_IP) === false
                || (! $allowPrivate && ! $this->isPublicAddress($address))) {
                $this->reject();
            }
        }
    }

    private function isPublicAddress(string $address): bool
    {
        return filter_var($address, FILTER_VALIDATE_IP) !== false
            && ! IpUtils::checkIp($address, self::NON_PUBLIC_NETWORKS);
    }

    private function reject(): never
    {
        throw new InvalidArgumentException('The destination must be an absolute HTTP or HTTPS URL with a publicly routable host.');
    }
}
