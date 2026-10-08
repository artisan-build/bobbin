<?php

declare(strict_types=1);

use App\Models\CapturedRequest;
use App\Models\Endpoint;
use ArtisanBuild\BuiltForCloud\User;
use Illuminate\Support\Str;

beforeEach(function (): void {
    config()->set('app.key', 'base64:'.base64_encode(random_bytes(32)));
});

it('lets the first and second users of a fresh install reach every main screen', function (): void {
    expect(User::query()->count())->toBe(0);

    $password = 'fresh-install-password';
    $users = [
        ['name' => 'First Owner', 'email' => Str::uuid().'@example.test', 'force' => false],
        ['name' => 'Second Admin', 'email' => Str::uuid().'@example.test', 'force' => true],
    ];

    $endpoint = null;
    $capture = null;

    foreach ($users as $position => $attributes) {
        $this->artisan('create-admin', [
            '--execute' => true,
            '--local' => true,
            '--email' => $attributes['email'],
            '--name' => $attributes['name'],
            '--password' => $password,
            '--force' => $attributes['force'],
        ])->assertSuccessful();

        $this->post(route('bfc.login.store'), [
            'email' => $attributes['email'],
            'password' => $password,
        ])->assertRedirect(route('bfc.dashboard', absolute: false));

        $index = $this->get(route('endpoints.index'))->assertOk();
        expect(substr_count($index->getContent(), 'data-testid="endpoints-index"'))->toBe(1);

        $create = $this->get(route('endpoints.create'))->assertOk();
        expect(substr_count($create->getContent(), 'data-testid="endpoints-create"'))->toBe(1);

        if ($position === 0) {
            $endpointName = 'Fresh install endpoint '.Str::random(12);

            $this->post(route('endpoints.store'), ['name' => $endpointName])
                ->assertRedirect();

            $endpoint = Endpoint::query()->where('name', $endpointName)->sole();
            $this->post(route('hooks.capture', $endpoint->public_id), [], [], [], [], [
                'CONTENT_TYPE' => 'application/json',
            ], '{"fresh":true}')->assertNoContent();
            $capture = CapturedRequest::query()->where('endpoint_id', $endpoint->getKey())->sole();
        }

        expect($endpoint)->toBeInstanceOf(Endpoint::class)
            ->and($capture)->toBeInstanceOf(CapturedRequest::class);

        $endpointDetail = $this->get(route('endpoints.show', $endpoint))->assertOk();
        expect(substr_count($endpointDetail->getContent(), 'data-testid="endpoint-detail"'))->toBe(1);
        $endpointDetail->assertSeeText($endpoint->name);

        $settings = $this->get(route('endpoint-settings.edit', $endpoint))->assertOk();
        expect(substr_count($settings->getContent(), 'data-testid="endpoint-settings"'))->toBe(1)
            ->and(substr_count($settings->getContent(), 'data-testid="destination-settings"'))->toBe(1);

        $captures = $this->get(route('captures.index', $endpoint))->assertOk();
        expect(substr_count($captures->getContent(), 'data-testid="captures-index"'))->toBe(1);

        $captureDetail = $this->get(route('captures.show', [$endpoint, $capture]))->assertOk();
        expect(substr_count($captureDetail->getContent(), 'data-testid="capture-detail"'))->toBe(1);
        $captureDetail->assertSeeText('{"fresh":true}');

        $this->post(route('bfc.logout'))->assertRedirect(route('bfc.login'));
    }
});
