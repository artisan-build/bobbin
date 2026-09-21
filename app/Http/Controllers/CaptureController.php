<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Endpoint;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

final class CaptureController extends Controller
{
    public function __invoke(Request $request, string $publicId): Response
    {
        $endpoint = Endpoint::query()->where('public_id', $publicId)->firstOrFail();
        $rawQuery = $request->server->get('QUERY_STRING', '');

        $endpoint->capturedRequests()->create([
            'method' => $request->getMethod(),
            'raw_query' => is_string($rawQuery) ? $rawQuery : '',
            'headers' => $request->headers->all(),
            'body' => $request->getContent(),
            'received_at' => now(),
        ]);

        return response()->noContent();
    }
}
