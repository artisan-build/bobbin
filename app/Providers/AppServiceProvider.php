<?php

namespace App\Providers;

use App\Console\Commands\PruneCapturedRequests;
use App\Console\Commands\RedispatchMcpReplayRequests;
use App\Contracts\ResolvesHostAddresses;
use App\Mcp\BobbinReadMcpServer;
use App\Mcp\BobbinWriteMcpServer;
use App\Services\NativeHostAddressResolver;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;
use Laravel\Mcp\Facades\Mcp;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    #[\Override]
    public function register(): void
    {
        config()->set([
            'built-for-cloud.mcp.path' => '/mcp',
            'built-for-cloud.mcp.write_path' => '/mcp/write',
            'built-for-cloud.mcp.destructive_path' => null,
            'built-for-cloud.mcp.delegated' => true,
        ]);

        $this->app->bind(ResolvesHostAddresses::class, NativeHostAddressResolver::class);

        $this->commands([
            PruneCapturedRequests::class,
            RedispatchMcpReplayRequests::class,
        ]);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->app->booted(function (): void {
            Mcp::web('/mcp', BobbinReadMcpServer::class)
                ->middleware('bfc.mcp:product,read');
            Mcp::web('/mcp/write', BobbinWriteMcpServer::class)
                ->middleware('bfc.mcp:product,write');
        });

        $this->configureDefaults();
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(fn (): ?Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null,
        );
    }
}
