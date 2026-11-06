<?php

declare(strict_types=1);

namespace App\Casts;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;

/**
 * A captured request body, kept byte for byte in a binary column.
 *
 * PostgreSQL reads a plain string bound to `bytea` as escape-format input, so
 * a backslash — which every PHP sender's json_encode puts in a URL — fails the
 * insert and loses the webhook. On PostgreSQL the body is written in bytea's
 * hex format instead, which carries any bytes at all. Reads come back as a
 * stream there and are turned back into the string every caller expects.
 *
 * @implements CastsAttributes<string, string>
 */
final class BinaryBody implements CastsAttributes
{
    /**
     * Turn whatever the driver returned into the body's bytes.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function get(Model $model, string $key, mixed $value, array $attributes): string
    {
        if (is_resource($value)) {
            rewind($value);

            return (string) stream_get_contents($value);
        }

        return (string) $value;
    }

    /**
     * Encode the body so the connection's binary column accepts every byte of it.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function set(Model $model, string $key, mixed $value, array $attributes): string
    {
        $body = (string) $value;

        return $model->getConnection()->getDriverName() === 'pgsql'
            ? '\\x'.bin2hex($body)
            : $body;
    }
}
