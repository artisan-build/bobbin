<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\ReconcileExpiredReplayRequests;
use Illuminate\Console\Command;

final class ReconcileMcpReplayRequests extends Command
{
    protected $signature = 'mcp-replays:reconcile';

    protected $description = 'Settle expired MCP replay receipts from durable queue evidence';

    public function handle(ReconcileExpiredReplayRequests $reconciler): int
    {
        $reconciler->reconcile();

        return self::SUCCESS;
    }
}
