<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property int $team_id
 * @property string $name
 * @property string $public_id
 * @property int $retention_days
 * @property list<string> $redacted_header_names
 * @property bool $body_redaction
 *
 * @mixin IdeHelperEndpoint
 */
final class Endpoint extends Model
{
    /** @var list<string> */
    protected $fillable = [
        'name',
        'retention_days',
        'redacted_header_names',
        'body_redaction',
    ];

    /** @var array<string, mixed> */
    protected $attributes = [
        'retention_days' => 30,
        'redacted_header_names' => '[]',
        'body_redaction' => false,
    ];

    /** @return HasMany<CapturedRequest, $this> */
    public function capturedRequests(): HasMany
    {
        return $this->hasMany(CapturedRequest::class);
    }

    /** @return HasMany<Destination, $this> */
    public function destinations(): HasMany
    {
        return $this->hasMany(Destination::class);
    }

    public function captureUrl(): string
    {
        return url('/hooks/'.$this->public_id);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'retention_days' => 'integer',
            'redacted_header_names' => 'array',
            'body_redaction' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        self::creating(function (Endpoint $endpoint): void {
            $endpoint->public_id = bin2hex(random_bytes(16));
        });
    }
}
