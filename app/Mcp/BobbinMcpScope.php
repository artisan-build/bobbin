<?php

declare(strict_types=1);

namespace App\Mcp;

use App\Models\Team;
use ArtisanBuild\BuiltForCloud\Console\DelegatedActor;
use ArtisanBuild\BuiltForCloud\Credential;
use ArtisanBuild\BuiltForCloud\User;
use Illuminate\Contracts\Auth\Authenticatable;
use Symfony\Component\HttpKernel\Exception\HttpException;

final class BobbinMcpScope
{
    public function team(): Team
    {
        $principal = request()->user();

        if ($principal instanceof User) {
            return $this->teamForUser($principal->getAuthIdentifier());
        }

        if ($principal instanceof Credential && $principal->user_id !== null) {
            return $this->teamForUser($principal->user_id);
        }

        abort_unless($principal instanceof DelegatedActor || $principal instanceof Credential, 403);

        return Team::query()->orderBy('id')->firstOrFail();
    }

    public function actorIdentifier(): string
    {
        $principal = request()->user();
        abort_unless($principal instanceof Authenticatable, 403);

        $identifier = $principal->getAuthIdentifier();
        abort_unless(is_int($identifier) || is_string($identifier), 403);

        return match (true) {
            $principal instanceof DelegatedActor => (string) $identifier,
            $principal instanceof Credential => 'credential:'.$identifier,
            $principal instanceof User => 'user:'.$identifier,
            default => throw new HttpException(403),
        };
    }

    private function teamForUser(mixed $userId): Team
    {
        abort_unless(is_int($userId) || is_string($userId), 403);

        $team = Team::query()
            ->join('team_user', 'team_user.team_id', '=', 'teams.id')
            ->where('team_user.user_id', $userId)
            ->select('teams.*')
            ->orderBy('teams.id')
            ->first();

        abort_unless($team instanceof Team, 403);

        return $team;
    }
}
