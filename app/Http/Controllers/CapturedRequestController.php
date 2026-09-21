<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\View\View;

final class CapturedRequestController extends Controller
{
    public function index(Request $request, string $endpoint): View
    {
        $endpoint = $this->team($request)->endpoints()->findOrFail($endpoint);

        return view('captures.index', [
            'endpoint' => $endpoint,
            'captures' => $endpoint->capturedRequests()
                ->latest('received_at')
                ->get(),
        ]);
    }

    public function show(Request $request, string $endpoint, string $capture): View
    {
        $endpoint = $this->team($request)->endpoints()->findOrFail($endpoint);
        $capture = $endpoint->capturedRequests()->findOrFail($capture);
        $deliveries = $capture->deliveries()
            ->with('destination')
            ->latest()
            ->get();
        $contentType = strtolower((string) ($capture->headers['content-type'][0] ?? ''));
        $declaresText = $contentType === ''
            || str_starts_with($contentType, 'text/')
            || preg_match('/json|xml|javascript|x-www-form-urlencoded/', $contentType) === 1;
        $containsBinaryControlBytes = preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $capture->body) === 1;

        return view('captures.show', [
            'endpoint' => $endpoint,
            'capture' => $capture,
            'deliveries' => $deliveries,
            'activeDestinationCount' => $endpoint->destinations()->where('is_active', true)->limit(2)->count(),
            'bodyIsText' => $declaresText
                && mb_check_encoding($capture->body, 'UTF-8')
                && ! $containsBinaryControlBytes,
        ]);
    }
}
