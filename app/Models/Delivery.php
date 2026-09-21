<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $destination_id
 * @property int $captured_request_id
 * @property string $status
 * @property int $attempt_count
 * @property int|null $response_status
 * @property string|null $last_error
 * @property Carbon|null $started_at
 * @property Carbon|null $completed_at
 *
 * @mixin IdeHelperDelivery
 */
final class Delivery extends Model
{
    public const string STATUS_QUEUED = 'queued';

    public const string STATUS_RUNNING = 'running';

    public const string STATUS_SUCCEEDED = 'succeeded';

    public const string STATUS_FAILED = 'failed';

    /** @var list<string> */
    protected $fillable = [
        'captured_request_id',
        'status',
        'attempt_count',
        'response_status',
        'last_error',
        'started_at',
        'completed_at',
    ];

    /** @var array<string, mixed> */
    protected $attributes = [
        'status' => self::STATUS_QUEUED,
        'attempt_count' => 0,
    ];

    /** @return BelongsTo<Destination, $this> */
    public function destination(): BelongsTo
    {
        return $this->belongsTo(Destination::class);
    }

    /** @return BelongsTo<CapturedRequest, $this> */
    public function capturedRequest(): BelongsTo
    {
        return $this->belongsTo(CapturedRequest::class);
    }

    public function isTerminal(): bool
    {
        return in_array($this->status, [self::STATUS_SUCCEEDED, self::STATUS_FAILED], true);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'attempt_count' => 'integer',
            'response_status' => 'integer',
            'started_at' => 'immutable_datetime',
            'completed_at' => 'immutable_datetime',
        ];
    }
}
