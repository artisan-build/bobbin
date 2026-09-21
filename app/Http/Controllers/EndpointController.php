<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Endpoint;
use ArtisanBuild\BuiltForCloud\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

final class EndpointController extends Controller
{
    public function index(Request $request): View
    {
        return view('endpoints.index', [
            'endpoints' => Endpoint::query()
                ->where('team_id', $this->teamId($request))
                ->latest()
                ->get(),
        ]);
    }

    public function create(): View
    {
        return view('endpoints.create');
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
        ]);

        $endpoint = new Endpoint(['name' => $validated['name']]);
        $endpoint->team_id = $this->teamId($request);
        $endpoint->save();

        return redirect()->route('endpoints.show', $endpoint);
    }

    public function show(Request $request, Endpoint $endpoint): View
    {
        abort_unless($endpoint->team_id === $this->teamId($request), 404);

        return view('endpoints.show', ['endpoint' => $endpoint]);
    }

    private function teamId(Request $request): int
    {
        $user = $request->user();
        if (! $user instanceof User) {
            abort(403);
        }

        $teamId = DB::table('team_user')
            ->where('user_id', $user->getAuthIdentifier())
            ->value('team_id');

        if ($teamId === null) {
            $teamId = DB::table('teams')->oldest('id')->value('id');
            if (! is_int($teamId)) {
                abort(403);
            }

            DB::table('team_user')->insertOrIgnore([
                'team_id' => $teamId,
                'user_id' => $user->getAuthIdentifier(),
            ]);

            $teamId = DB::table('team_user')
                ->where('user_id', $user->getAuthIdentifier())
                ->value('team_id');
        }

        if (! is_int($teamId)) {
            abort(403);
        }

        return $teamId;
    }
}
