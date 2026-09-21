<?php

declare(strict_types=1);

use App\Models\CapturedRequest;
use App\Models\Endpoint;
use Carbon\CarbonInterface;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

function pruningEndpoint(int $teamId, string $name, int $retentionDays): Endpoint
{
    $endpoint = new Endpoint([
        'name' => $name,
        'retention_days' => $retentionDays,
    ]);
    $endpoint->team_id = $teamId;
    $endpoint->save();

    return $endpoint;
}

function pruningCapture(Endpoint $endpoint, string $body, CarbonInterface $receivedAt): CapturedRequest
{
    return $endpoint->capturedRequests()->create([
        'method' => 'POST',
        'raw_query' => '',
        'headers' => ['x-prune-fixture' => [$body]],
        'body' => $body,
        'received_at' => $receivedAt,
    ]);
}

it('prunes strictly outside each endpoint retention window in one run', function (): void {
    $now = Date::parse('2026-09-21 12:34:56 UTC');
    Date::setTestNow($now);
    $teamId = (int) DB::table('teams')->value('id');
    $long = pruningEndpoint($teamId, 'Long retention '.Str::random(12), 30);
    $short = pruningEndpoint($teamId, 'Short retention '.Str::random(12), 7);

    $expiredLong = pruningCapture($long, 'expired-long-'.Str::random(12), $now->copy()->subDays(30)->subSecond());
    $boundaryLong = pruningCapture($long, 'boundary-long-'.Str::random(12), $now->copy()->subDays(30));
    $insideLong = pruningCapture($long, 'inside-long-'.Str::random(12), $now->copy()->subDays(29));
    $expiredShort = pruningCapture($short, 'expired-short-'.Str::random(12), $now->copy()->subDays(7)->subSecond());
    $boundaryShort = pruningCapture($short, 'boundary-short-'.Str::random(12), $now->copy()->subDays(7));
    $insideShort = pruningCapture($short, 'inside-short-'.Str::random(12), $now->copy()->subDays(6));

    expect(Artisan::call('captures:prune'))->toBe(0)
        ->and(CapturedRequest::query()->whereKey($expiredLong)->exists())->toBeFalse()
        ->and(CapturedRequest::query()->whereKey($expiredShort)->exists())->toBeFalse()
        ->and(CapturedRequest::query()->whereKey($boundaryLong)->exists())->toBeTrue()
        ->and(CapturedRequest::query()->whereKey($insideLong)->exists())->toBeTrue()
        ->and(CapturedRequest::query()->whereKey($boundaryShort)->exists())->toBeTrue()
        ->and(CapturedRequest::query()->whereKey($insideShort)->exists())->toBeTrue()
        ->and(CapturedRequest::query()->count())->toBe(4);
});

it('schedules the app-owned prune command daily', function (): void {
    $event = collect(resolve(Schedule::class)->events())
        ->first(fn ($event): bool => str_contains((string) $event->command, 'captures:prune'));

    expect($event)->not->toBeNull()
        ->and($event->expression)->toBe('0 0 * * *');
});
