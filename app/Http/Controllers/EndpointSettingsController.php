<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Closure;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

final class EndpointSettingsController extends Controller
{
    public function edit(Request $request, string $endpoint): View
    {
        $endpoint = $this->team($request)->endpoints()->findOrFail($endpoint);
        $activeDestinations = $endpoint->destinations()
            ->where('is_active', true)
            ->limit(2)
            ->get();

        return view('endpoints.settings', [
            'endpoint' => $endpoint,
            'activeDestination' => $activeDestinations->count() === 1 ? $activeDestinations->first() : null,
            'destinationIntegrityError' => $activeDestinations->count() > 1,
        ]);
    }

    public function update(Request $request, string $endpoint): RedirectResponse
    {
        $endpoint = $this->team($request)->endpoints()->findOrFail($endpoint);
        $validated = $request->validate([
            'retention_days' => ['required', 'integer', 'min:1', 'max:365'],
            'redacted_header_names' => [
                'nullable',
                'string',
                function (string $attribute, mixed $value, Closure $fail): void {
                    if (! is_string($value)) {
                        return;
                    }

                    foreach ($this->normalizeHeaderNames($value) as $headerName) {
                        if ($headerName === 'connection') {
                            $fail('The Connection header cannot be redacted because it is required for safe replay.');

                            return;
                        }

                        if (preg_match('/^[!#$%&\'*+\-.^_`|~0-9A-Za-z]+$/D', $headerName) !== 1) {
                            $fail('Every header name must be a valid HTTP header name.');

                            return;
                        }
                    }
                },
            ],
            'body_redaction' => ['required', 'boolean'],
        ]);

        $endpoint->update([
            'retention_days' => (int) $validated['retention_days'],
            'redacted_header_names' => $this->normalizeHeaderNames(
                (string) ($validated['redacted_header_names'] ?? ''),
            ),
            'body_redaction' => $request->boolean('body_redaction'),
        ]);

        return to_route('endpoint-settings.edit', $endpoint)
            ->with('status', 'Endpoint settings updated.');
    }

    /** @return list<string> */
    private function normalizeHeaderNames(string $headerNames): array
    {
        $normalized = [];

        foreach (preg_split('/[\r\n,]+/', $headerNames) ?: [] as $headerName) {
            $headerName = str_replace('_', '-', strtolower(trim($headerName)));

            if ($headerName !== '') {
                $normalized[$headerName] = true;
            }
        }

        return array_keys($normalized);
    }
}
