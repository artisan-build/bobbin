<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\DispatchCapturedRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use UnexpectedValueException;

final class ReplayController extends Controller
{
    public function __invoke(
        Request $request,
        string $endpoint,
        string $capture,
        DispatchCapturedRequest $dispatcher,
    ): RedirectResponse {
        $endpoint = $this->team($request)->endpoints()->findOrFail($endpoint);
        $capture = $endpoint->capturedRequests()->findOrFail($capture);

        try {
            $dispatcher->dispatch($capture);
        } catch (UnexpectedValueException) {
            return back()->withErrors([
                'replay' => 'Replay requires exactly one active destination.',
            ]);
        }

        return to_route('captures.show', [$endpoint, $capture])
            ->with('status', 'Replay queued.');
    }
}
