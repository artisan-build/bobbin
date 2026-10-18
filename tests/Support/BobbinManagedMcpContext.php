<?php

declare(strict_types=1);

namespace Tests\Support;

use ArtisanBuild\BuiltForCloud\AuthorityMode;
use ArtisanBuild\BuiltForCloud\Console\Assertion;
use ArtisanBuild\BuiltForCloud\Console\AssertionPurpose;
use ArtisanBuild\BuiltForCloud\Console\ConsoleRole;
use ArtisanBuild\BuiltForCloud\Console\DelegatedActor;
use ArtisanBuild\BuiltForCloud\Console\RequestAssertion;
use ArtisanBuild\BuiltForCloud\InstallationAuthority;
use ArtisanBuild\BuiltForCloud\User;
use ArtisanBuild\BuiltForCloud\UserRole;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class BobbinManagedMcpContext
{
    public const string CONNECTION_ID = 'bobbin-managed-mcp-test';

    public static function configure(string $issuer): void
    {
        $generation = (int) DB::table('bfc_authority')
            ->where('key', InstallationAuthority::KEY)
            ->value('generation');

        DB::table('bfc_authority')
            ->where('key', InstallationAuthority::KEY)
            ->update([
                'mode' => AuthorityMode::Managed->value,
                'generation' => $generation + 1,
                'issuer' => $issuer,
                'connection_id' => self::CONNECTION_ID,
                'organization_id' => 'bobbin-managed-mcp-organization',
                'installation_id' => 'bobbin-managed-mcp-installation',
                'authority_base_url' => 'https://authority.bobbin.test',
                'managed_connection_status' => 'active',
                'updated_at' => now(),
            ]);

        config([
            'built-for-cloud.managed.client_secret' => 'bobbin-managed-mcp-secret',
            'built-for-cloud.managed.ca_bundle' => null,
        ]);
    }

    public static function restoreStandaloneAuthority(): void
    {
        $generation = (int) DB::table('bfc_authority')
            ->where('key', InstallationAuthority::KEY)
            ->value('generation');

        DB::table('bfc_authority')
            ->where('key', InstallationAuthority::KEY)
            ->update([
                'mode' => AuthorityMode::Standalone->value,
                'generation' => $generation + 1,
                'updated_at' => now(),
            ]);
    }

    /** @param array<string, mixed> $attributes */
    public static function user(string $issuer, string $subject, array $attributes = []): User
    {
        $user = new User;
        $user->forceFill([
            'name' => 'Managed MCP '.Str::random(8),
            'email' => Str::uuid().'@bobbin.test',
            'role' => UserRole::Member->value,
            'status' => 'active',
            'scalpels_issuer' => $issuer,
            'scalpels_connection_id' => self::CONNECTION_ID,
            'scalpels_id' => $subject,
            'managed_membership_status' => 'active',
            'membership_confirmed_at' => now(),
            ...$attributes,
        ])->save();

        return $user->refresh();
    }

    public static function publish(string $issuer, string $subject, ?int $actorId = null): DelegatedActor
    {
        $now = CarbonImmutable::now();
        $assertion = Assertion::fromVerifiedClaims(
            issuer: $issuer,
            subject: $subject,
            displayName: 'Bobbin delegated test user',
            role: ConsoleRole::Member,
            onBehalfOf: 'Bobbin test team',
            audience: 'bobbin-test-installation',
            issuedAt: $now->subSecond(),
            expiresAt: $now->addSeconds(90),
            keyId: 'bobbin-delegated-test-key',
            id: (string) Str::uuid(),
            purpose: AssertionPurpose::Mcp,
        );

        if ($actorId === null) {
            $actor = DelegatedActor::recordHandoff($assertion);
        } else {
            $actor = new DelegatedActor;
            $actor->forceFill([
                'id' => $actorId,
                'identity_hash' => DelegatedActor::identityHash($issuer, $subject),
                'issuer' => $issuer,
                'subject' => $subject,
                'last_handoff_display_name' => $assertion->displayName,
                'last_handoff_on_behalf_of' => $assertion->onBehalfOf,
                'last_handoff_role' => $assertion->role->value,
            ])->save();
        }

        $request = Request::create('/mcp/delegated', 'POST');
        RequestAssertion::publish($request, $actor, $assertion);
        app()->instance('request', $request);

        return $actor;
    }
}
