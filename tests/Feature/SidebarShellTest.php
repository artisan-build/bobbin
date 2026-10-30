<?php

declare(strict_types=1);

use App\Models\Endpoint;
use ArtisanBuild\BuiltForCloud\StandaloneAccess;
use ArtisanBuild\BuiltForCloud\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

function sidebarShellUser(): User
{
    $user = User::query()->create([
        'name' => 'Sidebar operator',
        'email' => Str::uuid().'@example.test',
        'password' => Hash::make('test-password'),
    ]);
    DB::table('team_user')->insert([
        'team_id' => (int) DB::table('teams')->value('id'),
        'user_id' => $user->getKey(),
    ]);

    return $user->refresh();
}

function sidebarShellDocument(TestResponse $response): DOMXPath
{
    $document = new DOMDocument;
    $previous = libxml_use_internal_errors(true);
    $document->loadHTML($response->getContent());
    libxml_clear_errors();
    libxml_use_internal_errors($previous);

    return new DOMXPath($document);
}

function sidebarShellQuery(DOMXPath $xpath, string $expression): DOMNodeList
{
    $nodes = $xpath->query($expression);

    if ($nodes === false) {
        throw new RuntimeException('Invalid sidebar shell XPath expression.');
    }

    return $nodes;
}

function sidebarShellSignIn(TestCase $test, User $user): void
{
    $test->actingAs($user)->withSession([
        StandaloneAccess::SESSION_VERSION_KEY => $user->auth_session_version,
    ]);
}

it('renders the complete app navigation once in the package sidebar and keeps page content in main', function (): void {
    config()->set('app.key', 'base64:'.base64_encode(random_bytes(32)));
    $endpointName = 'Sidebar endpoint '.Str::random(12);
    $endpoint = new Endpoint(['name' => $endpointName]);
    $endpoint->team_id = (int) DB::table('teams')->value('id');
    $endpoint->save();
    sidebarShellSignIn($this, sidebarShellUser());

    $xpath = sidebarShellDocument($this->get(route('endpoints.index'))->assertOk());
    $navigation = sidebarShellQuery($xpath, '//*[@data-testid="app-sidebar-navigation"]');
    $links = sidebarShellQuery($xpath, '//*[@data-testid="app-sidebar-navigation"]/a');
    $destinations = [];

    foreach ($links as $link) {
        if ($link instanceof DOMElement) {
            $destinations[] = $link->getAttribute('href');
        }
    }

    expect($navigation->length)->toBe(1)
        ->and(sidebarShellQuery($xpath, '/html/head/title')->length)->toBe(1)
        ->and(sidebarShellQuery($xpath, '/html/head/link[@rel="stylesheet" and @href="'.asset('build/assets/app.css').'"]')->length)->toBe(1)
        ->and(sidebarShellQuery($xpath, '//*[@data-testid="bfc-sidebar-slot"]//*[@data-testid="app-sidebar-navigation"]')->length)->toBe(1)
        ->and(sidebarShellQuery($xpath, '//*[@data-testid="app-sidebar-navigation" and not(ancestor::*[@data-testid="bfc-sidebar-slot"])]')->length)->toBe(0)
        ->and($destinations)->toBe([
            route('endpoints.index'),
        ])
        ->and(sidebarShellQuery($xpath, '//*[@data-testid="app-nav-endpoints" and @aria-current="page"]')->length)->toBe(1)
        ->and(sidebarShellQuery($xpath, '//*[@data-testid="app-nav-new-endpoint"]')->length)->toBe(0)
        ->and(sidebarShellQuery($xpath, '//*[@data-testid="endpoints-index"]//form[@data-testid="endpoints-new-form"][@action="'.route('endpoints.store').'"]//input[@name="name"]')->length)->toBe(1)
        ->and(sidebarShellQuery($xpath, '//*[@data-testid="bfc-main"]//*[@data-testid="app-main-content"]//*[@data-testid="endpoints-index"]')->length)->toBe(1)
        ->and(sidebarShellQuery($xpath, '//*[@data-testid="bfc-main"]//*[contains(text(), "'.$endpointName.'")]')->length)->toBeGreaterThan(0);
});

it('still serves the standalone create page, which the sidebar no longer links', function (): void {
    config()->set('app.key', 'base64:'.base64_encode(random_bytes(32)));
    sidebarShellSignIn($this, sidebarShellUser());

    $xpath = sidebarShellDocument($this->get(route('endpoints.create'))->assertOk());

    expect(sidebarShellQuery($xpath, '//*[@data-testid="app-nav-new-endpoint"]')->length)->toBe(0)
        ->and(sidebarShellQuery($xpath, '//*[@data-testid="bfc-main"]//*[@data-testid="endpoints-create"]')->length)->toBe(1)
        ->and(sidebarShellQuery($xpath, '//*[@data-testid="endpoints-create"]//form//input[@name="_token"]')->length)->toBe(1);
});

it('keeps the endpoint destination selected throughout endpoint and capture screens', function (): void {
    config()->set('app.key', 'base64:'.base64_encode(random_bytes(32)));
    $endpoint = new Endpoint(['name' => 'Selected endpoint '.Str::random(12)]);
    $endpoint->team_id = (int) DB::table('teams')->value('id');
    $endpoint->save();
    $capture = $endpoint->capturedRequests()->create([
        'method' => 'POST',
        'raw_query' => 'source=sidebar-shell',
        'headers' => ['content-type' => ['application/json']],
        'body' => '{}',
        'received_at' => now(),
    ]);
    sidebarShellSignIn($this, sidebarShellUser());

    $routes = [
        route('endpoints.show', $endpoint),
        route('endpoint-settings.edit', $endpoint),
        route('captures.index', $endpoint),
        route('captures.show', [$endpoint, $capture]),
    ];

    foreach ($routes as $route) {
        $xpath = sidebarShellDocument($this->get($route)->assertOk());

        expect(sidebarShellQuery($xpath, '//*[@data-testid="app-nav-endpoints" and @aria-current="page"]')->length)->toBe(1)
            ->and(sidebarShellQuery($xpath, '//*[@data-testid="app-nav-new-endpoint"]')->length)->toBe(0);
    }
});
