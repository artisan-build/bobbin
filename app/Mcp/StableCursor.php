<?php

declare(strict_types=1);

namespace App\Mcp;

use JsonException;
use UnexpectedValueException;

final class StableCursor
{
    public static function encode(string $scope, int $after): string
    {
        $payload = self::base64UrlEncode(json_encode([
            'v' => 1,
            'scope' => $scope,
            'after' => $after,
        ], JSON_THROW_ON_ERROR));

        return $payload.'.'.hash_hmac('sha256', $payload, self::key());
    }

    public static function decode(?string $cursor, string $scope): int
    {
        if ($cursor === null) {
            return 0;
        }

        $parts = explode('.', $cursor, 2);
        if (count($parts) !== 2 || ! hash_equals(hash_hmac('sha256', $parts[0], self::key()), $parts[1])) {
            throw new UnexpectedValueException('Invalid cursor.');
        }

        try {
            $decoded = self::base64UrlDecode($parts[0]);
            $payload = json_decode($decoded, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException|UnexpectedValueException) {
            throw new UnexpectedValueException('Invalid cursor.');
        }

        if (! is_array($payload)
            || array_keys($payload) !== ['v', 'scope', 'after']
            || $payload['v'] !== 1
            || $payload['scope'] !== $scope
            || ! is_int($payload['after'])
            || $payload['after'] < 1) {
            throw new UnexpectedValueException('Invalid cursor.');
        }

        return $payload['after'];
    }

    private static function key(): string
    {
        $key = config('app.key');

        if (! is_string($key) || $key === '') {
            throw new UnexpectedValueException('Cursor signing is unavailable.');
        }

        return $key;
    }

    private static function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    private static function base64UrlDecode(string $value): string
    {
        if ($value === '' || preg_match('/^[A-Za-z0-9_-]+$/D', $value) !== 1) {
            throw new UnexpectedValueException('Invalid cursor.');
        }

        $decoded = base64_decode(strtr($value, '-_', '+/'), true);
        if (! is_string($decoded)) {
            throw new UnexpectedValueException('Invalid cursor.');
        }

        return $decoded;
    }
}
