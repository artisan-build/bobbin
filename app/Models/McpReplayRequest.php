<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

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
 *
 * @mixin IdeHelperMcpReplayRequest
 */
final class McpReplayRequest extends Model
{
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
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'team_id' => 'integer',
            'delivery_id' => 'integer',
            'delivery_capture_id' => 'integer',
            'delivery_destination_id' => 'integer',
        ];
    }
}
