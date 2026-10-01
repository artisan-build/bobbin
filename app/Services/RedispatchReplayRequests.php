<?php

declare(strict_types=1);

namespace App\Services;

use App\Jobs\DeliverCapturedRequest;
use App\Models\McpReplayDispatch;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Support\Str;
use Throwable;

final readonly class RedispatchReplayRequests
{
    public const int DELAY_SECONDS = 30;

    public function __construct(private Dispatcher $jobs) {}

    public function redispatch(): int
    {
        $dispatched = 0;

        McpReplayDispatch::query()
            ->whereNull('consumed_at')
            ->where('created_at', '<=', now()->subSeconds(self::DELAY_SECONDS))
            ->orderBy('id')
            ->eachById(function (McpReplayDispatch $intent) use (&$dispatched): void {
                $intent = $intent->fresh();

                if ($intent === null || $intent->consumed_at !== null) {
                    return;
                }

                try {
                    $this->jobs->dispatch(new DeliverCapturedRequest(
                        $intent->delivery_id,
                        $intent->replay_request_id,
                        (string) Str::uuid(),
                    ));
                    $dispatched++;
                } catch (Throwable $exception) {
                    report($exception);
                }
            });

        return $dispatched;
    }
}
