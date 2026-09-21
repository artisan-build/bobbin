<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\CapturedRequest;
use App\Models\Endpoint;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

final class CaptureController extends Controller
{
    public function __invoke(Request $request, string $publicId): Response
    {
        $endpoint = Endpoint::query()->where('public_id', $publicId)->firstOrFail();
        $rawQuery = $request->server->get('QUERY_STRING', '');
        $headers = $request->headers->all();
        $selectedHeaders = array_fill_keys($endpoint->redacted_header_names, true);
        $redactedHeaderNames = [];

        foreach ($headers as $name => $values) {
            $normalizedName = strtolower($name);

            if (! isset($selectedHeaders[$normalizedName])) {
                continue;
            }

            $headers[$name] = array_fill(0, count($values), CapturedRequest::REDACTION_MARKER);
            $redactedHeaderNames[] = $normalizedName;
        }

        $endpoint->capturedRequests()->create([
            'method' => $request->getMethod(),
            'raw_query' => is_string($rawQuery) ? $rawQuery : '',
            'headers' => $headers,
            'body' => $endpoint->body_redaction
                ? CapturedRequest::REDACTION_MARKER
                : $request->getContent(),
            'redacted_header_names' => $redactedHeaderNames,
            'body_redacted' => $endpoint->body_redaction,
            'received_at' => now(),
        ]);

        return response()->noContent();
    }
}
