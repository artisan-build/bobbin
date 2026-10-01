<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\CapturedRequest;
use App\Models\Delivery;
use App\Services\DestinationUrlPolicy;
use App\Services\SettleReplayRequest;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

final class DeliverCapturedRequest implements ShouldQueue
{
    use Queueable;

    public const int MAX_ATTEMPTS = 4;

    /** @var list<int> */
    public array $backoff = [10, 60, 300];

    public int $tries = self::MAX_ATTEMPTS;

    public int $timeout = 30;

    public function __construct(
        public int $deliveryId,
        public ?int $replayRequestId = null,
    ) {}

    /** @return list<WithoutOverlapping> */
    public function middleware(): array
    {
        return [
            (new WithoutOverlapping('delivery:'.$this->deliveryId))
                ->shared()
                ->dontRelease()
                ->expireAfter($this->timeout + 30),
        ];
    }

    public function handle(): void
    {
        if ($this->replayRequestId !== null) {
            $settlement = resolve(SettleReplayRequest::class);
            $settlement->recordQueueAcceptance($this->replayRequestId);
            $settlement->acceptedFromHandledJob($this->replayRequestId);
        }

        $shouldSend = DB::transaction(function (): bool {
            $delivery = Delivery::query()->lockForUpdate()->findOrFail($this->deliveryId);

            if ($delivery->isTerminal()) {
                return false;
            }

            if ($delivery->attempt_count >= self::MAX_ATTEMPTS) {
                $delivery->update([
                    'status' => Delivery::STATUS_FAILED,
                    'completed_at' => now(),
                ]);

                return false;
            }

            $delivery->update([
                'status' => Delivery::STATUS_RUNNING,
                'attempt_count' => $delivery->attempt_count + 1,
                'response_status' => null,
                'last_error' => null,
                'started_at' => $delivery->started_at ?? now(),
                'completed_at' => null,
            ]);

            return true;
        });

        if (! $shouldSend) {
            return;
        }

        $delivery = Delivery::query()
            ->with(['destination', 'capturedRequest'])
            ->findOrFail($this->deliveryId);
        $capturedRequest = $delivery->capturedRequest;

        if (in_array('connection', $capturedRequest->redacted_header_names, true)) {
            $this->recordFailure(null, 'Cannot safely replay a redacted Connection header.', false);

            return;
        }

        try {
            resolve(DestinationUrlPolicy::class)->assertSafe($delivery->destination->url);
        } catch (InvalidArgumentException) {
            $this->recordFailure(null, 'Destination is no longer safe to contact.', false);

            return;
        }

        try {
            $response = Http::timeout(15)
                ->connectTimeout(5)
                ->withoutRedirecting()
                ->send($capturedRequest->method, $this->targetUrl($delivery->destination->url, $capturedRequest), [
                    'headers' => $this->forwardedHeaders($capturedRequest),
                    'body' => $capturedRequest->body,
                ]);
        } catch (ConnectionException) {
            $this->recordFailure(null, 'Destination connection failed.', true);

            return;
        }

        $status = $response->status();

        if ($status >= 200 && $status < 300) {
            $delivery->update([
                'status' => Delivery::STATUS_SUCCEEDED,
                'response_status' => $status,
                'last_error' => null,
                'completed_at' => now(),
            ]);

            return;
        }

        $this->recordFailure(
            $status,
            "Destination returned HTTP {$status}.",
            $status === 429 || ($status >= 500 && $status < 600),
        );
    }

    public function failed(?Throwable $exception): void
    {
        if ($this->replayRequestId !== null) {
            resolve(SettleReplayRequest::class)->recordQueueAcceptance($this->replayRequestId);
        }

        Delivery::query()
            ->whereKey($this->deliveryId)
            ->whereNotIn('status', [Delivery::STATUS_SUCCEEDED, Delivery::STATUS_FAILED])
            ->update([
                'status' => Delivery::STATUS_FAILED,
                'response_status' => null,
                'last_error' => 'Delivery failed after an unexpected queue error.',
                'completed_at' => now(),
            ]);

        if ($this->replayRequestId !== null) {
            resolve(SettleReplayRequest::class)->acceptedFromFailedJob($this->replayRequestId);
        }
    }

    private function recordFailure(?int $status, string $error, bool $retryable): void
    {
        $delivery = Delivery::query()->findOrFail($this->deliveryId);
        $willRetry = $retryable && $delivery->attempt_count < self::MAX_ATTEMPTS;

        $delivery->update([
            'status' => $willRetry ? Delivery::STATUS_QUEUED : Delivery::STATUS_FAILED,
            'response_status' => $status,
            'last_error' => mb_substr($error, 0, 255),
            'completed_at' => $willRetry ? null : now(),
        ]);

        if ($willRetry) {
            throw new RuntimeException('Destination delivery will be retried.');
        }
    }

    private function targetUrl(string $destinationUrl, CapturedRequest $capturedRequest): string
    {
        if ($capturedRequest->raw_query === '') {
            return $destinationUrl;
        }

        [$url, $fragment] = array_pad(explode('#', $destinationUrl, 2), 2, null);
        $endsWithSeparator = str_ends_with($url, '?') || str_ends_with($url, '&');
        $separator = str_contains($url, '?')
            ? ($endsWithSeparator ? '' : '&')
            : '?';

        $rawQuery = str_replace(['{', '}'], ['%7B', '%7D'], $capturedRequest->raw_query);

        return $url.$separator.$rawQuery.($fragment === null ? '' : '#'.$fragment);
    }

    /** @return array<string, list<string>> */
    private function forwardedHeaders(CapturedRequest $capturedRequest): array
    {
        $headers = $capturedRequest->headers;
        $blocked = array_fill_keys([
            'connection',
            'content-length',
            'host',
            'keep-alive',
            'proxy-authenticate',
            'proxy-authorization',
            'te',
            'trailer',
            'transfer-encoding',
            'upgrade',
        ], true);

        foreach ($headers as $name => $values) {
            if (strtolower($name) !== 'connection') {
                continue;
            }

            foreach ($values as $value) {
                foreach (explode(',', (string) $value) as $nominatedHeader) {
                    $blocked[strtolower(trim($nominatedHeader))] = true;
                }
            }
        }

        return collect($headers)
            ->reject(fn (array $values, string $name): bool => isset($blocked[strtolower($name)]))
            ->map(fn (array $values): array => array_map(
                static fn (?string $value): string => $value ?? '',
                $values,
            ))
            ->all();
    }
}
