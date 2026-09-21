<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property string $name
 */
final class Team extends Model
{
    /** @return HasMany<Endpoint, $this> */
    public function endpoints(): HasMany
    {
        return $this->hasMany(Endpoint::class);
    }
}
