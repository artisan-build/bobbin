<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Mcp\BobbinMcpScope;
use App\Mcp\BobbinTool;
use App\Mcp\StableCursor;
use App\Models\CapturedRequest;
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

#[Name('captures')]
#[Description('List a scoped endpoint\'s captured request headers and body. Bobbin applies both capture-time and current endpoint redaction before constructing the response; query strings are never returned.')]
#[IsReadOnly]
#[ToolClassification(Classification::Content)]
#[ToolEffect(Effect::Read)]
final class CapturesTool extends BobbinTool
{
    use AdvertisesToolClassification, AdvertisesToolEffect {
        AdvertisesToolClassification::toArray insteadof AdvertisesToolEffect;
    }
    use RespectsEffectCeiling;

    public function handle(Request $request, BobbinMcpScope $scope): Response|ResponseFactory
    {
        $validated = $this->validateArguments($request, [
            'endpoint_id' => ['required', 'integer', 'min:1'],
            'cursor' => ['sometimes', 'string', 'max:512'],
            'limit' => ['sometimes', 'integer:strict', 'min:1', 'max:100'],
        ]);
        $team = $scope->team();
        $endpoint = Endpoint::query()
            ->where('team_id', $team->id)
            ->find((int) $validated['endpoint_id']);

        if (! $endpoint instanceof Endpoint) {
            return Response::error('not_found');
        }

        $limit = (int) ($validated['limit'] ?? 25);
        $cursorScope = 'captures:'.$team->id.':'.$endpoint->id;

        try {
            $after = StableCursor::decode($validated['cursor'] ?? null, $cursorScope);
        } catch (\UnexpectedValueException) {
            return Response::error('invalid_cursor');
        }

        $captures = $endpoint->capturedRequests()
            ->where('id', '>', $after)
            ->orderBy('id')
            ->limit($limit + 1)
            ->get();
        $hasMore = $captures->count() > $limit;
        $page = $captures->take($limit);
        $items = $page->map(fn (CapturedRequest $capture): array => $this->redactedCapture($endpoint, $capture))
            ->values()
            ->all();
        $last = $page->last();

        return Response::structured([
            'items' => $items,
            'next_cursor' => $hasMore && $last instanceof CapturedRequest
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
            'cursor' => $schema->string()->max(512)->description('Opaque cursor returned by the previous page.'),
            'limit' => $schema->integer()->min(1)->max(100)->default(25),
        ];
    }

    /** @return array<string, mixed> */
    private function redactedCapture(Endpoint $endpoint, CapturedRequest $capture): array
    {
        $redactedNames = array_fill_keys(array_map(
            strtolower(...),
            [...$capture->redacted_header_names, ...$endpoint->redacted_header_names],
        ), true);
        $headers = [];

        foreach ($capture->headers as $name => $values) {
            if (! is_string($name) || ! is_array($values)) {
                continue;
            }

            $headers[$name] = isset($redactedNames[strtolower($name)])
                ? array_fill(0, count($values), CapturedRequest::REDACTION_MARKER)
                : array_map(static fn (mixed $value): string => is_string($value) ? $value : '', $values);
        }

        $bodyRedacted = $capture->body_redacted || $endpoint->body_redaction;
        $body = $bodyRedacted ? CapturedRequest::REDACTION_MARKER : $capture->body;
        $bodyEncoding = 'utf8';

        if (! $bodyRedacted && ! mb_check_encoding($body, 'UTF-8')) {
            $body = base64_encode($body);
            $bodyEncoding = 'base64';
        }

        return [
            'id' => $capture->id,
            'method' => $capture->method,
            'headers' => $headers,
            'body' => $body,
            'body_encoding' => $bodyEncoding,
            'body_redacted' => $bodyRedacted,
            'received_at' => $capture->received_at->toJSON(),
        ];
    }
}
