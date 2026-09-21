<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\CapturedRequest;
use App\Models\Endpoint;
use Illuminate\Console\Command;

final class PruneCapturedRequests extends Command
{
    protected $signature = 'captures:prune';

    protected $description = 'Delete captures older than each endpoint retention window.';

    public function handle(): int
    {
        $now = now();
        $deleted = 0;

        Endpoint::query()
            ->select(['id', 'retention_days'])
            ->eachById(function (Endpoint $endpoint) use ($now, &$deleted): void {
                $deleted += CapturedRequest::query()
                    ->where('endpoint_id', $endpoint->id)
                    ->where('received_at', '<', $now->copy()->subDays($endpoint->retention_days))
                    ->delete();
            });

        $this->components->info("Pruned {$deleted} captured request(s).");

        return self::SUCCESS;
    }
}
