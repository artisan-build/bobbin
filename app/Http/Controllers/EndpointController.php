<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Team;
use ArtisanBuild\BuiltForCloud\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

final class EndpointController extends Controller
{
    public function index(Request $request): View
    {
        return view('endpoints.index', [
            'endpoints' => $this->team($request)
                ->endpoints()
                ->latest()
                ->get(),
        ]);
    }

    public function create(Request $request): View
    {
        $this->team($request);

        return view('endpoints.create');
    }

    public function store(Request $request): RedirectResponse
    {
        $team = $this->team($request);
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
        ]);

        $endpoint = $team->endpoints()->create(['name' => $validated['name']]);

        return to_route('endpoints.show', $endpoint);
    }

    public function show(Request $request, string $endpoint): View
    {
        $endpoint = $this->team($request)->endpoints()->findOrFail($endpoint);

        return view('endpoints.show', ['endpoint' => $endpoint]);
    }

    private function team(Request $request): Team
    {
        $user = $request->user();
        if (! $user instanceof User) {
            abort(403);
        }

        $team = Team::query()
            ->join('team_user', 'team_user.team_id', '=', 'teams.id')
            ->where('team_user.user_id', $user->getAuthIdentifier())
            ->select('teams.*')
            ->first();

        if (! $team instanceof Team) {
            abort(403);
        }

        return $team;
    }
}
