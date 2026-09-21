<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $endpoint_id
 * @property string $method
 * @property string $raw_query
 * @property array<string, list<string|null>> $headers
 * @property string $body
 * @property Carbon $received_at
 *
 * @mixin IdeHelperCapturedRequest
 */
final class CapturedRequest extends Model
{
    public $timestamps = false;

    /** @var list<string> */
    protected $fillable = [
        'method',
        'raw_query',
        'headers',
        'body',
        'received_at',
    ];

    /** @return BelongsTo<Endpoint, $this> */
    public function endpoint(): BelongsTo
    {
        return $this->belongsTo(Endpoint::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'headers' => 'array',
            'received_at' => 'immutable_datetime',
        ];
    }
}
