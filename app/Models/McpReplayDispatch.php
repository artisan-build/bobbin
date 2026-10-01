<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $replay_request_id
 * @property int $delivery_id
 * @property string|null $claim_token
 * @property Carbon|null $consumed_at
 *
 * @mixin IdeHelperMcpReplayDispatch
 */
final class McpReplayDispatch extends Model
{
    /** @var list<string> */
    protected $fillable = [
        'replay_request_id',
        'delivery_id',
        'claim_token',
        'consumed_at',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'replay_request_id' => 'integer',
            'delivery_id' => 'integer',
            'consumed_at' => 'immutable_datetime',
        ];
    }
}
