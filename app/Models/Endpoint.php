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
 *
 * @mixin IdeHelperEndpoint
 */
final class Endpoint extends Model
{
    /** @var list<string> */
    protected $fillable = ['name'];

    /** @return HasMany<CapturedRequest, $this> */
    public function capturedRequests(): HasMany
    {
        return $this->hasMany(CapturedRequest::class);
    }

    public function captureUrl(): string
    {
        return url('/hooks/'.$this->public_id);
    }

    protected static function booted(): void
    {
        self::creating(function (Endpoint $endpoint): void {
            $endpoint->public_id = bin2hex(random_bytes(16));
        });
    }
}
