<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Mcp\BobbinMcpScope;
use App\Mcp\BobbinTool;
use App\Mcp\StableCursor;
use App\Models\CapturedRequest;
use App\Models\Delivery;
use App\Models\Destination;
use App\Models\Endpoint;
use ArtisanBuild\BuiltForCloud\Mcp\AdvertisesToolClassification;
use ArtisanBuild\BuiltForCloud\Mcp\AdvertisesToolEffect;
use ArtisanBuild\BuiltForCloud\Mcp\Classification;
use ArtisanBuild\BuiltForCloud\Mcp\Effect;
use ArtisanBuild\BuiltForCloud\Mcp\RespectsEffectCeiling;
use ArtisanBuild\BuiltForCloud\Mcp\ToolClassification;
use ArtisanBuild\BuiltForCloud\Mcp\ToolEffect;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Validation\Rule;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('delivery_status')]
#[Description('List delivery state for one scoped endpoint, optionally narrowed to an exact capture, destination, or list of statuses.')]
#[IsReadOnly]
#[ToolClassification(Classification::Metadata)]
#[ToolEffect(Effect::Read)]
final class DeliveryStatusTool extends BobbinTool
{
    use AdvertisesToolClassification, AdvertisesToolEffect {
        AdvertisesToolClassification::toArray insteadof AdvertisesToolEffect;
    }
    use RespectsEffectCeiling;

    private const array STATUSES = [
        Delivery::STATUS_QUEUED,
        Delivery::STATUS_RUNNING,
        Delivery::STATUS_SUCCEEDED,
        Delivery::STATUS_FAILED,
    ];

    public function handle(Request $request, BobbinMcpScope $scope): Response|ResponseFactory
    {
        $validated = $this->validateArguments($request, [
            'endpoint_id' => ['required', 'integer', 'min:1'],
            'capture_id' => ['sometimes', 'integer', 'min:1'],
            'destination_id' => ['sometimes', 'integer', 'min:1'],
            'statuses' => ['sometimes', 'array', 'list', 'min:1', 'max:4'],
            'statuses.*' => ['required', 'string', Rule::in(self::STATUSES)],
            'cursor' => ['sometimes', 'string', 'max:512'],
            'limit' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);
        $team = $scope->team();
        $endpoint = Endpoint::query()->where('team_id', $team->id)->find((int) $validated['endpoint_id']);

        if (! $endpoint instanceof Endpoint) {
            return Response::error('not_found');
        }

        $captureId = isset($validated['capture_id']) ? (int) $validated['capture_id'] : null;
        $destinationId = isset($validated['destination_id']) ? (int) $validated['destination_id'] : null;

        if (($captureId !== null && ! CapturedRequest::query()->where('endpoint_id', $endpoint->id)->whereKey($captureId)->exists())
            || ($destinationId !== null && ! Destination::query()->where('endpoint_id', $endpoint->id)->whereKey($destinationId)->exists())) {
            return Response::error('not_found');
        }

        $statuses = $validated['statuses'] ?? [];
        $limit = (int) ($validated['limit'] ?? 25);
        $cursorScope = implode(':', [
            'deliveries',
            $team->id,
            $endpoint->id,
            $captureId ?? '-',
            $destinationId ?? '-',
            implode(',', $statuses),
        ]);

        try {
            $after = StableCursor::decode($validated['cursor'] ?? null, $cursorScope);
        } catch (\UnexpectedValueException) {
            return Response::error('invalid_cursor');
        }

        $deliveries = Delivery::query()
            ->whereHas('capturedRequest', fn ($query) => $query->where('endpoint_id', $endpoint->id))
            ->whereHas('destination', fn ($query) => $query->where('endpoint_id', $endpoint->id))
            ->when($captureId !== null, fn ($query) => $query->where('captured_request_id', $captureId))
            ->when($destinationId !== null, fn ($query) => $query->where('destination_id', $destinationId))
            ->when($statuses !== [], fn ($query) => $query->whereIn('status', $statuses))
            ->where('id', '>', $after)
            ->orderBy('id')
            ->limit($limit + 1)
            ->get();
        $hasMore = $deliveries->count() > $limit;
        $page = $deliveries->take($limit);
        $items = $page->map(static fn (Delivery $delivery): array => [
            'id' => $delivery->id,
            'capture_id' => $delivery->captured_request_id,
            'destination_id' => $delivery->destination_id,
            'status' => $delivery->status,
            'attempt_count' => $delivery->attempt_count,
            'response_status' => $delivery->response_status,
            'last_error' => $delivery->last_error,
            'started_at' => $delivery->started_at?->toJSON(),
            'completed_at' => $delivery->completed_at?->toJSON(),
        ])->values()->all();
        $last = $page->last();

        return Response::structured([
            'items' => $items,
            'next_cursor' => $hasMore && $last instanceof Delivery
                ? StableCursor::encode($cursorScope, $last->id)
                : null,
            'has_more' => $hasMore,
            'limit' => $limit,
        ]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'endpoint_id' => $schema->integer()->min(1)->required(),
            'capture_id' => $schema->integer()->min(1),
            'destination_id' => $schema->integer()->min(1),
            'statuses' => $schema->array()->items($schema->string()->enum(self::STATUSES))->min(1)->max(4)->unique(),
            'cursor' => $schema->string()->max(512)->description('Opaque cursor returned by the previous page.'),
            'limit' => $schema->integer()->min(1)->max(100)->default(25),
        ];
    }
}
