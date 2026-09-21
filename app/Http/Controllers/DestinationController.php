<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\DestinationUrlPolicy;
use Closure;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

final class DestinationController extends Controller
{
    public function update(Request $request, string $endpoint, DestinationUrlPolicy $urlPolicy): RedirectResponse
    {
        $team = $this->team($request);
        $endpoint = $team->endpoints()->findOrFail($endpoint);
        $request->merge(['destination_url' => trim((string) $request->input('destination_url'))]);
        $validated = $request->validate([
            'destination_url' => [
                'required',
                'string',
                'max:2048',
                function (string $attribute, mixed $value, Closure $fail) use ($urlPolicy): void {
                    if (! is_string($value)) {
                        return;
                    }

                    try {
                        $urlPolicy->assertSafe($value);
                    } catch (InvalidArgumentException $exception) {
                        $fail($exception->getMessage());
                    }
                },
            ],
        ]);
        $url = (string) $validated['destination_url'];

        DB::transaction(function () use ($team, $endpoint, $url): void {
            $lockedEndpoint = $team->endpoints()
                ->whereKey($endpoint->getKey())
                ->lockForUpdate()
                ->firstOrFail();
            $activeDestinations = $lockedEndpoint->destinations()
                ->where('is_active', true)
                ->lockForUpdate()
                ->limit(2)
                ->get();

            if ($activeDestinations->count() > 1) {
                throw ValidationException::withMessages([
                    'destination_url' => 'Destination settings cannot be changed while multiple destinations are active.',
                ]);
            }

            $activeDestination = $activeDestinations->first();

            if ($activeDestination?->url === $url) {
                return;
            }

            $activeDestination?->update(['is_active' => false]);
            $lockedEndpoint->destinations()->create([
                'url' => $url,
                'is_active' => true,
            ]);
        });

        return to_route('endpoint-settings.edit', $endpoint)
            ->with('status', 'Destination settings updated.');
    }
}
