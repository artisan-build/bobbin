<?php

declare(strict_types=1);

namespace App\Mcp;

use App\Models\Team;
use ArtisanBuild\BuiltForCloud\Console\ActingPrincipal;
use ArtisanBuild\BuiltForCloud\Console\ActingPrincipalResolver;
use ArtisanBuild\BuiltForCloud\Console\DelegatedActor;
use ArtisanBuild\BuiltForCloud\Credential;
use ArtisanBuild\BuiltForCloud\Exceptions\ManagedAuthRefused;
use ArtisanBuild\BuiltForCloud\ManagedAccountAccess;
use ArtisanBuild\BuiltForCloud\ManagedAuthConnection;
use ArtisanBuild\BuiltForCloud\OffboardedSubject;
use ArtisanBuild\BuiltForCloud\RolePolicy;
use ArtisanBuild\BuiltForCloud\User;
use Illuminate\Http\Request;

final readonly class BobbinMcpScope
{
    public function __construct(
        private Request $request,
        private ActingPrincipalResolver $principals,
        private ManagedAccountAccess $managedAccess,
    ) {}

    public function team(): Team
    {
        $principal = $this->principals->require($this->request);
        $user = $principal->delegated
            ? $this->delegatedUser($principal)
            : $this->principals->localUser($this->request);

        if (! $user instanceof User) {
            throw new UnsupportedMcpPrincipal;
        }

        $team = Team::query()
            ->join('team_user', 'team_user.team_id', '=', 'teams.id')
            ->where('team_user.user_id', $user->getKey())
            ->select('teams.*')
            ->first();

        return $team instanceof Team ? $team : throw new UnsupportedMcpPrincipal;
    }

    public function actorIdentifier(): string
    {
        $principal = $this->principals->require($this->request);
        $identifier = $principal->identifier();

        if (! is_int($identifier) && ! is_string($identifier)) {
            throw new UnsupportedMcpPrincipal;
        }

        return match (true) {
            $principal->delegated => (string) $identifier,
            $principal->principal instanceof Credential => 'credential:'.$identifier,
            $principal->principal instanceof User => 'user:'.$identifier,
            default => throw new UnsupportedMcpPrincipal,
        };
    }

    private function delegatedUser(ActingPrincipal $principal): ?User
    {
        $actor = $principal->delegatedActor;

        if (! $actor instanceof DelegatedActor) {
            return null;
        }

        try {
            $connection = ManagedAuthConnection::current();
        } catch (ManagedAuthRefused) {
            return null;
        }

        if (! hash_equals($connection->issuer, $actor->issuer)) {
            return null;
        }

        $user = User::query()
            ->where('scalpels_issuer', $connection->issuer)
            ->where('scalpels_connection_id', $connection->connectionId)
            ->where('scalpels_id', $actor->subject)
            ->first();

        if (! $user instanceof User
            || $user->status !== 'active'
            || ! RolePolicy::canUseProduct($user->role)
            || OffboardedSubject::userOrSubjectIsOffboarded($this->request, $user)
            || ! $this->managedAccess->allows($user)) {
            return null;
        }

        return $user;
    }
}
