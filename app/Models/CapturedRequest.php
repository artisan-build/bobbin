<?php

declare(strict_types=1);

namespace App\Models;

use App\Casts\BinaryBody;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $endpoint_id
 * @property string $method
 * @property string $raw_query
 * @property array<string, list<string|null>> $headers
 * @property string $body
 * @property list<string> $redacted_header_names
 * @property bool $body_redacted
 * @property Carbon $received_at
 *
 * @mixin IdeHelperCapturedRequest
 */
final class CapturedRequest extends Model
{
    public const string REDACTION_MARKER = '[redacted by Bobbin]';

    public $timestamps = false;

    /** @var array<string, mixed> */
    protected $attributes = [
        'redacted_header_names' => '[]',
        'body_redacted' => false,
    ];

    /** @var list<string> */
    protected $fillable = [
        'method',
        'raw_query',
        'headers',
        'body',
        'redacted_header_names',
        'body_redacted',
        'received_at',
    ];

    /** @return BelongsTo<Endpoint, $this> */
    public function endpoint(): BelongsTo
    {
        return $this->belongsTo(Endpoint::class);
    }

    /** @return HasMany<Delivery, $this> */
    public function deliveries(): HasMany
    {
        return $this->hasMany(Delivery::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'headers' => 'array',
            'body' => BinaryBody::class,
            'redacted_header_names' => 'array',
            'body_redacted' => 'boolean',
            'received_at' => 'immutable_datetime',
        ];
    }
}
