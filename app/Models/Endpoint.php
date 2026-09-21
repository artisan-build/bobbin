<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int $team_id
 * @property string $name
 * @property string $public_id
 */
final class Endpoint extends Model
{
    /** @var list<string> */
    protected $fillable = ['name'];

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
