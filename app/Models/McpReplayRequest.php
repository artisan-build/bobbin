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
 *
 * @mixin IdeHelperMcpReplayRequest
 */
final class McpReplayRequest extends Model
{
    /** @var list<string> */
    protected $fillable = [
        'team_id',
        'actor_identifier',
        'idempotency_key',
        'request_hash',
        'delivery_id',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'team_id' => 'integer',
            'delivery_id' => 'integer',
        ];
    }
}
