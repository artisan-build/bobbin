<?php

declare(strict_types=1);

namespace App\Services;

use App\Jobs\DeliverCapturedRequest;
use App\Models\CapturedRequest;
use App\Models\Delivery;
use App\Models\Destination;
use Illuminate\Support\Facades\DB;
use UnexpectedValueException;

final class DispatchCapturedRequest
{
    public function dispatch(CapturedRequest $capturedRequest): Delivery
    {
        return DB::transaction(function () use ($capturedRequest): Delivery {
            $destinations = Destination::query()
                ->where('endpoint_id', $capturedRequest->endpoint_id)
                ->where('is_active', true)
                ->limit(2)
                ->get();

            if ($destinations->count() !== 1) {
                throw new UnexpectedValueException('The endpoint must have exactly one active destination.');
            }

            $delivery = $destinations->sole()
                ->deliveries()
                ->create([
                    'captured_request_id' => $capturedRequest->getKey(),
                ]);

            dispatch(new DeliverCapturedRequest($delivery->getKey()))->afterCommit();

            return $delivery;
        });
    }
}
