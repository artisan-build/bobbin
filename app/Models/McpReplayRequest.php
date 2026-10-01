<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $team_id
 * @property string $actor_identifier
 * @property string $idempotency_key
 * @property string $request_hash
 * @property int|null $delivery_id
 * @property int|null $delivery_capture_id
 * @property int|null $delivery_destination_id
 * @property string|null $delivery_status
 * @property string $outcome
 * @property Carbon|null $queue_accepted_at
 * @property Carbon|null $lease_expires_at
 *
 * @mixin IdeHelperMcpReplayRequest
 */
final class McpReplayRequest extends Model
{
    public const int LEASE_SECONDS = 300;

    public const string OUTCOME_ACCEPTED = 'accepted';

    public const string OUTCOME_IN_PROGRESS = 'in_progress';

    public const string OUTCOME_UNKNOWN = 'outcome_unknown';

    /** @var list<string> */
    protected $fillable = [
        'team_id',
        'actor_identifier',
        'idempotency_key',
        'request_hash',
        'delivery_id',
        'delivery_capture_id',
        'delivery_destination_id',
        'delivery_status',
        'outcome',
        'queue_accepted_at',
        'lease_expires_at',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'team_id' => 'integer',
            'delivery_id' => 'integer',
            'delivery_capture_id' => 'integer',
            'delivery_destination_id' => 'integer',
            'queue_accepted_at' => 'immutable_datetime',
            'lease_expires_at' => 'immutable_datetime',
        ];
    }
}
