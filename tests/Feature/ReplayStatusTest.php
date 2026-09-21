<?php

declare(strict_types=1);

use App\Contracts\ResolvesHostAddresses;
use App\Jobs\DeliverCapturedRequest;
use App\Models\CapturedRequest;
use App\Models\Delivery;
use App\Models\Destination;
use App\Models\Endpoint;
use App\Services\DestinationUrlPolicy;
use ArtisanBuild\BuiltForCloud\StandaloneAccess;
use ArtisanBuild\BuiltForCloud\User;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Database\QueryException;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Http\Request;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Support\ReplayHostResolver;
use Tests\TestCase;

beforeEach(function (): void {
    config()->set('app.key', 'base64:'.base64_encode(random_bytes(32)));
    config()->set('bobbin.allow_private_destinations', false);
    app()->instance(ResolvesHostAddresses::class, new ReplayHostResolver([
        'receiver.example.test' => ['93.184.216.34'],
    ]));
});

function replayTeamId(): int
{
    return (int) DB::table('teams')->value('id');
}

function replayUser(?int $teamId): User
{
    $user = User::query()->create([
        'name' => 'Replay operator',
        'email' => Str::uuid().'@example.test',
        'password' => bcrypt('test-password'),
    ]);

    if ($teamId !== null) {
        DB::table('team_user')->insert([
            'team_id' => $teamId,
            'user_id' => $user->getKey(),
        ]);
    }

    return $user->refresh();
}

function actingAsReplayUser(TestCase $test, User $user): void
{
    $test->actingAs($user)->withSession([
        StandaloneAccess::SESSION_VERSION_KEY => $user->auth_session_version,
    ]);
}

function replayEndpoint(int $teamId, string $name = 'Replay endpoint'): Endpoint
{
    $endpoint = new Endpoint(['name' => $name.' '.Str::random(8)]);
    $endpoint->team_id = $teamId;
    $endpoint->save();

    return $endpoint;
}

function replayCapture(Endpoint $endpoint, string $body = 'replay-body'): CapturedRequest
{
    return $endpoint->capturedRequests()->create([
        'method' => 'POST',
        'raw_query' => 'event=replay',
        'headers' => ['content-type' => ['application/json']],
        'body' => $body,
        'received_at' => now(),
    ]);
}

function sendReplayCapture(
    Endpoint $endpoint,
    string $method,
    string $body,
    string $rawQuery,
    array $headers,
): TestResponse {
    $request = Request::create('/hooks/'.$endpoint->public_id.'?'.$rawQuery, $method, content: $body);

    foreach ($headers as $name => $values) {
        $request->headers->set($name, $values);
    }

    return TestResponse::fromBaseResponse(resolve(Kernel::class)->handle($request));
}

/** @param array<string, list<string>> $answers */
function replayPolicyResolver(array $answers = []): ReplayHostResolver
{
    return new ReplayHostResolver(array_replace([
        'receiver.example.test' => ['93.184.216.34'],
        'localhost' => ['93.184.216.34'],
        'api.localhost' => ['93.184.216.34'],
    ], $answers));
}

it('rejects unsafe destination URL classes deterministically', function (string $url, array $answers): void {
    $policy = new DestinationUrlPolicy(replayPolicyResolver($answers));

    expect(fn () => $policy->assertSafe($url))->toThrow(InvalidArgumentException::class);
})->with([
    'localhost' => ['http://localhost/hook', []],
    'localhost suffix' => ['http://api.localhost/hook', []],
    'private IPv4' => ['http://10.0.0.8/hook', []],
    'loopback IPv4' => ['http://127.0.0.1/hook', []],
    'link-local IPv4' => ['http://169.254.10.2/hook', []],
    'documentation IPv4' => ['http://192.0.2.10/hook', []],
    'documentation IPv4 test net two' => ['http://198.51.100.1/hook', []],
    'documentation IPv4 test net three' => ['http://203.0.113.1/hook', []],
    'multicast IPv4' => ['http://224.0.0.10/hook', []],
    'documentation multicast IPv4' => ['http://233.252.0.1/hook', []],
    'unspecified IPv4' => ['http://0.0.0.0/hook', []],
    'loopback IPv6' => ['http://[::1]/hook', []],
    'private IPv6' => ['http://[fd00::1]/hook', []],
    'link-local IPv6' => ['http://[fe80::1]/hook', []],
    'documentation IPv6' => ['http://[2001:db8::1]/hook', []],
    'multicast IPv6' => ['http://[ff02::1]/hook', []],
    'outside global unicast IPv6 lower range' => ['http://[4000::1]/hook', []],
    'outside global unicast IPv6 upper range' => ['http://[8000::1]/hook', []],
    'unspecified IPv6' => ['http://[::]/hook', []],
    'mixed public and private answers' => [
        'https://mixed.example.test/hook',
        ['mixed.example.test' => ['93.184.216.34', '10.0.0.8']],
    ],
    'embedded username' => ['https://user@receiver.example.test/hook', []],
    'embedded credentials' => ['https://user:secret@receiver.example.test/hook', []],
    'opening brace' => ['https://receiver.example.test/{hook}', []],
    'closing brace' => ['https://receiver.example.test/hook?value=}', []],
    'unsupported scheme' => ['ftp://receiver.example.test/hook', []],
    'unresolved hostname' => ['https://unresolved.example.test/hook', []],
    'fragment' => ['https://receiver.example.test/hook#fragment', []],
    'malformed port' => ['https://receiver.example.test:99999/hook', []],
]);

it('accepts a clean destination with the unsafe URL test resolver', function (): void {
    $policy = new DestinationUrlPolicy(replayPolicyResolver());

    $policy->assertSafe('https://receiver.example.test/hook');

    expect(true)->toBeTrue();
});

it('accepts a hostname only when every resolved address is public', function (): void {
    $policy = new DestinationUrlPolicy(new ReplayHostResolver([
        'public.example.test' => ['93.184.216.34', '2606:4700:4700::1111'],
    ]));

    $policy->assertSafe('https://public.example.test/hook?token=value');

    expect(true)->toBeTrue();
});

it('allows private destinations only behind the local-only verification flag', function (): void {
    config()->set('bobbin.allow_private_destinations', true);
    $policy = new DestinationUrlPolicy(new ReplayHostResolver);

    app()->detectEnvironment(fn (): string => 'production');
    expect(fn () => $policy->assertSafe('http://127.0.0.1/hook'))
        ->toThrow(InvalidArgumentException::class);

    app()->detectEnvironment(fn (): string => 'local');
    $policy->assertSafe('http://127.0.0.1/hook');
    expect(true)->toBeTrue();
});

it('creates idempotently then replaces a destination without rewriting or deleting history', function (): void {
    $teamId = replayTeamId();
    $endpoint = replayEndpoint($teamId, 'Destination lifecycle');
    actingAsReplayUser($this, replayUser($teamId));
    $firstUrl = 'https://receiver.example.test/first/'.Str::random(8);
    $secondUrl = 'https://receiver.example.test/second/'.Str::random(8);

    $settings = $this->get(route('endpoint-settings.edit', $endpoint))->assertOk();
    expect(substr_count($settings->getContent(), 'data-testid="destination-settings"'))->toBe(1);
    $settings->assertSeeText('Endpoint #'.$endpoint->id.' does not have a replay destination yet.');

    $this->put(route('destinations.update', $endpoint), [
        'destination_url' => $firstUrl,
    ])->assertRedirect(route('endpoint-settings.edit', $endpoint));

    $first = Destination::query()->sole();
    expect($first->url)->toBe($firstUrl)->and($first->is_active)->toBeTrue();

    $this->put(route('destinations.update', $endpoint), [
        'destination_url' => $firstUrl,
    ])->assertRedirect(route('endpoint-settings.edit', $endpoint));
    expect(Destination::query()->count())->toBe(1)
        ->and(Destination::query()->sole()->is($first))->toBeTrue();

    $capture = replayCapture($endpoint);
    $delivery = $first->deliveries()->create(['captured_request_id' => $capture->id]);

    $this->put(route('destinations.update', $endpoint), [
        'destination_url' => $secondUrl,
    ])->assertRedirect(route('endpoint-settings.edit', $endpoint));

    $first->refresh();
    $second = Destination::query()->where('is_active', true)->sole();
    expect(Destination::query()->count())->toBe(2)
        ->and($first->is_active)->toBeFalse()
        ->and($second->url)->toBe($secondUrl)
        ->and($delivery->refresh()->destination->is($first))->toBeTrue()
        ->and($delivery->destination->url)->toBe($firstUrl);

    expect(fn () => $first->delete())->toThrow(QueryException::class);
    expect($delivery->fresh())->not->toBeNull();
});

it('fails destination changes closed when multiple destinations are active', function (): void {
    $teamId = replayTeamId();
    $endpoint = replayEndpoint($teamId, 'Conflicted destinations');
    actingAsReplayUser($this, replayUser($teamId));
    $endpoint->destinations()->create(['url' => 'https://receiver.example.test/one', 'is_active' => true]);
    $endpoint->destinations()->create(['url' => 'https://receiver.example.test/two', 'is_active' => true]);

    $settings = $this->get(route('endpoint-settings.edit', $endpoint))->assertOk();
    $settings->assertSeeText('Endpoint #'.$endpoint->id.' has multiple active destinations.');

    $this->from(route('endpoint-settings.edit', $endpoint))
        ->put(route('destinations.update', $endpoint), [
            'destination_url' => 'https://receiver.example.test/replacement',
        ])
        ->assertRedirect(route('endpoint-settings.edit', $endpoint))
        ->assertSessionHasErrors('destination_url');

    expect($endpoint->destinations()->where('is_active', true)->count())->toBe(2)
        ->and($endpoint->destinations()->count())->toBe(2);
});

it('rejects an unsafe destination through the protected configuration route', function (): void {
    $teamId = replayTeamId();
    $endpoint = replayEndpoint($teamId, 'Unsafe destination');
    actingAsReplayUser($this, replayUser($teamId));
    $submittedUrl = 'https://receiver.example.test/{'.Str::random(24).'}';

    $this->from(route('endpoint-settings.edit', $endpoint))
        ->put(route('destinations.update', $endpoint), [
            'destination_url' => $submittedUrl,
        ])
        ->assertRedirect(route('endpoint-settings.edit', $endpoint))
        ->assertSessionHasErrors('destination_url');

    expect($endpoint->destinations()->count())->toBe(0);
    $this->get(route('endpoint-settings.edit', $endpoint))
        ->assertOk()
        ->assertDontSee($submittedUrl);
});

it('never flashes or renders rejected destination credentials', function (): void {
    Queue::fake();
    $teamId = replayTeamId();
    $endpoint = replayEndpoint($teamId, 'Credential rejection');
    actingAsReplayUser($this, replayUser($teamId));
    $credential = Str::random(48);

    $this->from(route('endpoint-settings.edit', $endpoint))
        ->put(route('destinations.update', $endpoint), [
            'destination_url' => 'https://operator:'.$credential.'@receiver.example.test/hook',
        ])
        ->assertRedirect(route('endpoint-settings.edit', $endpoint))
        ->assertSessionHasErrors('destination_url')
        ->assertSessionMissing('_old_input.destination_url');

    expect(Destination::query()->count())->toBe(0)
        ->and(Delivery::query()->count())->toBe(0);
    Queue::assertNothingPushed();

    $this->get(route('endpoint-settings.edit', $endpoint))
        ->assertOk()
        ->assertDontSee($credential);
});

it('fails the protected replay action closed for zero or multiple active destinations', function (int $active): void {
    Queue::fake();
    $teamId = replayTeamId();
    $endpoint = replayEndpoint($teamId, 'Replay integrity');
    $capture = replayCapture($endpoint);
    actingAsReplayUser($this, replayUser($teamId));

    for ($index = 0; $index < $active; $index++) {
        $endpoint->destinations()->create([
            'url' => 'https://receiver.example.test/'.$index,
            'is_active' => true,
        ]);
    }

    $this->from(route('captures.show', [$endpoint, $capture]))
        ->post(route('captures.replay', [$endpoint, $capture]))
        ->assertRedirect(route('captures.show', [$endpoint, $capture]))
        ->assertSessionHasErrors('replay');

    expect(Delivery::query()->count())->toBe(0);
    Queue::assertNothingPushed();
})->with([
    'zero active destinations' => 0,
    'multiple active destinations' => 2,
]);

it('captures through the public route and replays through protected HTTP on the database queue', function (): void {
    config()->set('queue.default', 'database');
    $teamId = replayTeamId();
    $endpoint = replayEndpoint($teamId, 'End to end seam');
    actingAsReplayUser($this, replayUser($teamId));
    $destinationUrl = 'https://receiver.example.test/live?configured=yes';

    $this->put(route('destinations.update', $endpoint), [
        'destination_url' => $destinationUrl,
    ])->assertRedirect();

    $rawQuery = 'tag=one&tag=two&blank=&encoded=%2F%2b%20';
    $body = '{"seam":"'.Str::random(12).'"}';
    sendReplayCapture($endpoint, 'PATCH', $body, $rawQuery, [
        'Content-Type' => ['application/json'],
        'X-Replay-Multi' => ['first-value', 'second-value'],
    ])->assertNoContent();
    $capture = CapturedRequest::query()->sole();
    Http::fake(fn (): mixed => Http::response('', 202));

    $this->post(route('captures.replay', [$endpoint, $capture]))
        ->assertRedirect(route('captures.show', [$endpoint, $capture]));

    $delivery = Delivery::query()->sole();
    expect(DB::table('jobs')->count())->toBe(1)
        ->and($delivery->status)->toBe(Delivery::STATUS_QUEUED);

    $queued = $this->get(route('captures.show', [$endpoint, $capture]))->assertOk();
    expect(substr_count($queued->getContent(), 'data-testid="replay-action"'))->toBe(1)
        ->and(substr_count($queued->getContent(), 'data-testid="delivery-history"'))->toBe(1);
    $queued->assertSeeText('Delivery #'.$delivery->id)
        ->assertSeeText($destinationUrl)
        ->assertSeeText(Delivery::STATUS_QUEUED);

    expect(Artisan::call('queue:work', [
        'connection' => 'database',
        '--once' => true,
        '--sleep' => 0,
        '--tries' => 4,
    ]))->toBe(0);

    Http::assertSent(function (ClientRequest $request) use ($body, $rawQuery): bool {
        expect($request->method())->toBe('PATCH')
            ->and($request->url())->toBe('https://receiver.example.test/live?configured=yes&'.$rawQuery)
            ->and($request->body())->toBe($body)
            ->and($request->header('X-Replay-Multi'))->toBe(['first-value', 'second-value']);

        return true;
    });

    $delivery->refresh();
    expect($delivery->status)->toBe(Delivery::STATUS_SUCCEEDED)
        ->and($delivery->attempt_count)->toBe(1)
        ->and($delivery->response_status)->toBe(202)
        ->and($delivery->completed_at)->not->toBeNull();

    $terminal = $this->get(route('captures.show', [$endpoint, $capture]))->assertOk();
    $terminal->assertSeeText('Delivery #'.$delivery->id)
        ->assertSeeText(Delivery::STATUS_SUCCEEDED)
        ->assertSeeText((string) $delivery->response_status)
        ->assertSeeText($delivery->completed_at->format('M j, Y H:i:s'));
});

it('shows retry facts, later success, and terminal failure distinctly', function (): void {
    $teamId = replayTeamId();
    $endpoint = replayEndpoint($teamId, 'Visible statuses');
    $user = replayUser($teamId);
    actingAsReplayUser($this, $user);
    $capture = replayCapture($endpoint);

    $fallback = $this->get(route('captures.show', [$endpoint, $capture]))->assertOk();
    $fallback->assertSeeText('Endpoint #'.$endpoint->id.' needs exactly one active destination before capture #'.$capture->id.' can be replayed.')
        ->assertSeeText('Capture #'.$capture->id.' has no delivery attempts.');

    $destination = $endpoint->destinations()->create([
        'url' => 'https://receiver.example.test/statuses',
        'is_active' => true,
    ]);
    $retrying = $destination->deliveries()->create([
        'captured_request_id' => $capture->id,
        'status' => Delivery::STATUS_QUEUED,
        'attempt_count' => 1,
        'response_status' => 503,
        'last_error' => 'Destination returned HTTP 503.',
        'started_at' => now(),
    ]);
    $terminal = $destination->deliveries()->create([
        'captured_request_id' => $capture->id,
        'status' => Delivery::STATUS_FAILED,
        'attempt_count' => 1,
        'response_status' => 422,
        'last_error' => 'Destination returned HTTP 422.',
        'started_at' => now(),
        'completed_at' => now(),
    ]);
    $running = $destination->deliveries()->create([
        'captured_request_id' => $capture->id,
        'status' => Delivery::STATUS_RUNNING,
        'attempt_count' => 1,
        'started_at' => now(),
    ]);

    $visibleRetry = $this->get(route('captures.show', [$endpoint, $capture]))->assertOk();
    $visibleRetry->assertSeeText('Delivery #'.$retrying->id)
        ->assertSeeText((string) $retrying->response_status)
        ->assertSeeText($retrying->last_error)
        ->assertSeeText('Delivery #'.$terminal->id)
        ->assertSeeText((string) $terminal->response_status)
        ->assertSeeText($terminal->last_error)
        ->assertSeeText('Delivery #'.$running->id)
        ->assertSeeText(Delivery::STATUS_RUNNING);

    $retrying->update([
        'status' => Delivery::STATUS_SUCCEEDED,
        'attempt_count' => 2,
        'response_status' => 204,
        'last_error' => null,
        'completed_at' => now(),
    ]);

    $laterSuccess = $this->get(route('captures.show', [$endpoint, $capture]))->assertOk();
    $laterSuccess->assertSeeText('Delivery #'.$retrying->id)
        ->assertSeeText(Delivery::STATUS_SUCCEEDED)
        ->assertSeeText((string) $retrying->response_status);
});

it('revalidates a configured destination before send without leaking its URL into durable errors', function (): void {
    Queue::fake();
    $teamId = replayTeamId();
    $endpoint = replayEndpoint($teamId, 'Revalidation');
    actingAsReplayUser($this, replayUser($teamId));
    $secretPath = 'sensitive-'.Str::random(12);
    $url = 'https://receiver.example.test/'.$secretPath;

    $this->put(route('destinations.update', $endpoint), ['destination_url' => $url])->assertRedirect();
    $capture = replayCapture($endpoint);
    $this->post(route('captures.replay', [$endpoint, $capture]))->assertRedirect();
    $delivery = Delivery::query()->sole();

    /** @var ReplayHostResolver $resolver */
    $resolver = resolve(ResolvesHostAddresses::class);
    $resolver->answers['receiver.example.test'] = ['127.0.0.1'];
    Http::fake();

    (new DeliverCapturedRequest($delivery->id))->handle();

    Http::assertNothingSent();
    $delivery->refresh();
    expect($delivery->status)->toBe(Delivery::STATUS_FAILED)
        ->and($delivery->attempt_count)->toBe(1)
        ->and($delivery->last_error)->toBe('Destination is no longer safe to contact.')
        ->and($delivery->last_error)->not->toContain($secretPath, $url);
});

it('keys overlap prevention to each delivery id', function (): void {
    $first = new DeliverCapturedRequest(41);
    $second = new DeliverCapturedRequest(42);
    $firstMiddleware = $first->middleware();
    $secondMiddleware = $second->middleware();

    expect($firstMiddleware)->toHaveCount(1)
        ->and($firstMiddleware[0])->toBeInstanceOf(WithoutOverlapping::class)
        ->and($firstMiddleware[0]->key)->toBe('delivery:41')
        ->and($firstMiddleware[0]->getLockKey($first))->toEndWith('delivery:41')
        ->and($secondMiddleware[0]->getLockKey($second))->toEndWith('delivery:42')
        ->and($firstMiddleware[0]->getLockKey($first))->not->toBe($secondMiddleware[0]->getLockKey($second))
        ->and($firstMiddleware[0]->releaseAfter)->toBeNull();
});

it('protects destination values replay actions and delivery facts for every denied caller', function (): void {
    Queue::fake();
    $firstTeamId = replayTeamId();
    $secondTeamId = DB::table('teams')->insertGetId([
        'name' => 'Other replay team '.Str::random(8),
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $endpoint = replayEndpoint($firstTeamId, 'Protected replay');
    $capture = replayCapture($endpoint, 'protected-body-'.Str::random(8));
    $destinationSecret = 'destination-secret-'.Str::random(8);
    $deliverySecret = 'delivery-secret-'.Str::random(8);
    $destination = $endpoint->destinations()->create([
        'url' => 'https://receiver.example.test/'.$destinationSecret,
        'is_active' => true,
    ]);
    $destination->deliveries()->create([
        'captured_request_id' => $capture->id,
        'status' => Delivery::STATUS_FAILED,
        'last_error' => $deliverySecret,
    ]);

    $this->get(route('endpoint-settings.edit', $endpoint))
        ->assertRedirect(route('bfc.login'))
        ->assertDontSee($destinationSecret);
    $this->put(route('destinations.update', $endpoint), [
        'destination_url' => 'https://receiver.example.test/attacker',
    ])->assertRedirect(route('bfc.login'));
    $this->post(route('captures.replay', [$endpoint, $capture]))
        ->assertRedirect(route('bfc.login'));
    $this->get(route('captures.show', [$endpoint, $capture]))
        ->assertRedirect(route('bfc.login'))
        ->assertDontSee($deliverySecret);

    actingAsReplayUser($this, replayUser($secondTeamId));
    $this->get(route('endpoint-settings.edit', $endpoint))->assertNotFound()->assertDontSee($destinationSecret);
    $this->put(route('destinations.update', $endpoint), [
        'destination_url' => 'https://receiver.example.test/attacker',
    ])->assertNotFound();
    $this->post(route('captures.replay', [$endpoint, $capture]))->assertNotFound();
    $this->get(route('captures.show', [$endpoint, $capture]))->assertNotFound()->assertDontSee($deliverySecret);

    $noMembership = replayUser(null);
    actingAsReplayUser($this, $noMembership);
    $this->get(route('endpoint-settings.edit', $endpoint))->assertForbidden()->assertDontSee($destinationSecret);
    $this->put(route('destinations.update', $endpoint), [
        'destination_url' => 'https://receiver.example.test/attacker',
    ])->assertForbidden();
    $this->post(route('captures.replay', [$endpoint, $capture]))->assertForbidden();
    $this->get(route('captures.show', [$endpoint, $capture]))->assertForbidden()->assertDontSee($deliverySecret);

    expect(DB::table('team_user')->where('user_id', $noMembership->id)->count())->toBe(0)
        ->and($endpoint->destinations()->count())->toBe(1)
        ->and(Delivery::query()->count())->toBe(1);
    Queue::assertNothingPushed();
});

it('documents the shipped replay capability and local-only harness exception', function (): void {
    $readme = file_get_contents(base_path('README.md'));

    expect($readme)->toContain(
        'Replay a selected capture to one configured destination',
        'Delivery is at-least-once',
        'BOBBIN_ALLOW_PRIVATE_DESTINATIONS',
        'tests/Live/run-replay-status.php',
    )->not->toContain('replay delivery are not implemented');
});
