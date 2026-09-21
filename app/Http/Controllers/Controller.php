<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Team;
use ArtisanBuild\BuiltForCloud\User;
use Illuminate\Http\Request;

abstract class Controller
{
    protected function team(Request $request): Team
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
