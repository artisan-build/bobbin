<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Mcp\BobbinMcpScope;
use App\Mcp\BobbinTool;
use App\Mcp\StableCursor;
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
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('endpoints')]
#[Description('List this installation team\'s webhook endpoints using a stable cursor. Returns replay destination handles but never destination URLs.')]
#[IsReadOnly]
#[ToolClassification(Classification::Metadata)]
#[ToolEffect(Effect::Read)]
final class EndpointsTool extends BobbinTool
{
    use AdvertisesToolClassification, AdvertisesToolEffect {
        AdvertisesToolClassification::toArray insteadof AdvertisesToolEffect;
    }
    use RespectsEffectCeiling;

    public function handle(Request $request, BobbinMcpScope $scope): Response|ResponseFactory
    {
        $team = $scope->team();
        $validated = $this->validateArguments($request, [
            'cursor' => ['sometimes', 'string', 'max:512'],
            'limit' => ['sometimes', 'integer:strict', 'min:1', 'max:100'],
        ]);
        $limit = (int) ($validated['limit'] ?? 25);
        $cursorScope = 'endpoints:'.$team->id;

        try {
            $after = StableCursor::decode($validated['cursor'] ?? null, $cursorScope);
        } catch (\UnexpectedValueException) {
            return Response::error('invalid_cursor');
        }

        $endpoints = Endpoint::query()
            ->where('team_id', $team->id)
            ->where('id', '>', $after)
            ->withCount(['destinations as active_destination_count' => fn ($query) => $query->where('is_active', true)])
            ->addSelect(['active_destination_id' => Destination::query()
                ->select('id')
                ->whereColumn('endpoint_id', 'endpoints.id')
                ->where('is_active', true)
                ->orderBy('id')
                ->limit(1)])
            ->orderBy('id')
            ->limit($limit + 1)
            ->get();
        $hasMore = $endpoints->count() > $limit;
        $items = $endpoints->take($limit)->map(static fn (Endpoint $endpoint): array => [
            'id' => $endpoint->id,
            'name' => $endpoint->name,
            'retention_days' => $endpoint->retention_days,
            'redacted_header_names' => $endpoint->redacted_header_names,
            'body_redaction' => $endpoint->body_redaction,
            'active_destination_id' => $endpoint->getAttribute('active_destination_count') === 1
                ? $endpoint->getAttribute('active_destination_id')
                : null,
            'destination_state' => match ((int) $endpoint->getAttribute('active_destination_count')) {
                0 => 'missing',
                1 => 'ready',
                default => 'conflict',
            },
        ])->values()->all();
        $last = $endpoints->take($limit)->last();

        return Response::structured([
            'items' => $items,
            'next_cursor' => $hasMore && $last instanceof Endpoint
                ? StableCursor::encode($cursorScope, $last->id)
                : null,
            'has_more' => $hasMore,
            'limit' => $limit,
        ]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'cursor' => $schema->string()->max(512)->description('Opaque cursor returned by the previous page.'),
            'limit' => $schema->integer()->min(1)->max(100)->default(25),
        ];
    }
}
