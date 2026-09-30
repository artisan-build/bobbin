<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Mcp\BobbinMcpScope;
use App\Mcp\BobbinTool;
use App\Models\Delivery;
use App\Services\DispatchCapturedRequest;
use App\Services\ReplayIdempotencyConflict;
use App\Services\ReplayOutcomeUnknown;
use ArtisanBuild\BuiltForCloud\Mcp\AdvertisesToolClassification;
use ArtisanBuild\BuiltForCloud\Mcp\AdvertisesToolEffect;
use ArtisanBuild\BuiltForCloud\Mcp\Classification;
use ArtisanBuild\BuiltForCloud\Mcp\Effect;
use ArtisanBuild\BuiltForCloud\Mcp\RespectsEffectCeiling;
use ArtisanBuild\BuiltForCloud\Mcp\ToolClassification;
use ArtisanBuild\BuiltForCloud\Mcp\ToolEffect;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;

#[Name('replay_capture')]
#[Description('Queue one exact scoped capture to one exact active destination. Requires a caller idempotency key. A queue failure after the durable delivery handle exists is reported as outcome_unknown and is never retried automatically.')]
#[IsIdempotent]
#[ToolClassification(Classification::Content)]
#[ToolEffect(Effect::Write)]
final class ReplayCaptureTool extends BobbinTool
{
    use AdvertisesToolClassification, AdvertisesToolEffect {
        AdvertisesToolClassification::toArray insteadof AdvertisesToolEffect;
    }
    use RespectsEffectCeiling;

    public function handle(
        Request $request,
        BobbinMcpScope $scope,
        DispatchCapturedRequest $dispatcher,
    ): Response|ResponseFactory {
        $validated = $this->validateArguments($request, [
            'endpoint_id' => ['required', 'integer', 'min:1'],
            'capture_id' => ['required', 'integer', 'min:1'],
            'destination_id' => ['required', 'integer', 'min:1'],
            'idempotency_key' => ['required', 'string', 'min:1', 'max:128', 'regex:/^[A-Za-z0-9._:-]+$/D'],
        ]);
        $team = $scope->team();
        $actorIdentifier = $scope->actorIdentifier();

        try {
            $result = $dispatcher->dispatchIdempotently(
                teamId: $team->id,
                endpointId: (int) $validated['endpoint_id'],
                captureId: (int) $validated['capture_id'],
                destinationId: (int) $validated['destination_id'],
                actorIdentifier: $actorIdentifier,
                idempotencyKey: (string) $validated['idempotency_key'],
            );
        } catch (ReplayIdempotencyConflict) {
            return Response::error('idempotency_conflict');
        } catch (ReplayOutcomeUnknown $unknown) {
            return Response::error(json_encode([
                'outcome' => 'outcome_unknown',
                'delivery_id' => $unknown->deliveryId,
            ], JSON_THROW_ON_ERROR));
        } catch (ModelNotFoundException) {
            return Response::error('not_found');
        }

        return Response::structured([
            'outcome' => $result->duplicate ? 'already_accepted' : 'accepted',
            'delivery' => $this->deliveryHandle($result->delivery),
        ]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'endpoint_id' => $schema->integer()->min(1)->required(),
            'capture_id' => $schema->integer()->min(1)->required(),
            'destination_id' => $schema->integer()->min(1)->required(),
            'idempotency_key' => $schema->string()
                ->min(1)
                ->max(128)
                ->pattern('^[A-Za-z0-9._:-]+$')
                ->required(),
        ];
    }

    /** @return array<string, mixed> */
    private function deliveryHandle(Delivery $delivery): array
    {
        return [
            'id' => $delivery->id,
            'capture_id' => $delivery->captured_request_id,
            'destination_id' => $delivery->destination_id,
            'status' => $delivery->status,
        ];
    }
}
