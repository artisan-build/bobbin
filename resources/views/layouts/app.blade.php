<x-bfc-layout :title="filled($title ?? null) ? $title.' - '.config('app.name', 'Bobbin') : config('app.name', 'Bobbin')">
    @push('head')
        @include('partials.head')
    @endpush

    <x-slot:sidebar>
        @php
            $endpointsCurrent = request()->routeIs(
                'endpoints.index',
                'endpoints.show',
                'captures.*',
                'endpoint-settings.*',
                'destinations.*',
            );
        @endphp

        <nav aria-label="Bobbin" data-testid="app-sidebar-navigation" class="grid gap-1">
            <a
                href="{{ route('endpoints.index') }}"
                data-testid="app-nav-endpoints"
                class="bfc-shell-link {{ $endpointsCurrent ? 'bg-clay-slip text-clay-ink' : '' }}"
                @if ($endpointsCurrent) aria-current="page" @endif
            >
                All endpoints
            </a>
        </nav>
    </x-slot:sidebar>

    <div data-testid="app-main-content" class="text-clay-soft">
        <div class="mx-auto max-w-6xl px-5 py-12 sm:px-8 sm:py-16">
            @yield('content')
        </div>
    </div>
</x-bfc-layout>
