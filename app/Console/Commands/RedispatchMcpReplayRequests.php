<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\RedispatchReplayRequests;
use Illuminate\Console\Command;

final class RedispatchMcpReplayRequests extends Command
{
    protected $signature = 'mcp-replays:redispatch';

    protected $description = 'Redispatch unconsumed MCP replay outbox intents';

    public function handle(RedispatchReplayRequests $redispatcher): int
    {
        $redispatcher->redispatch();

        return self::SUCCESS;
    }
}
