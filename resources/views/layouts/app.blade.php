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
            $newEndpointCurrent = request()->routeIs('endpoints.create');
        @endphp

        <nav aria-label="Bobbin" data-testid="app-sidebar-navigation" class="grid gap-1">
            <a
                href="{{ route('endpoints.index') }}"
                data-testid="app-nav-endpoints"
                class="bfc-shell-link {{ $endpointsCurrent ? 'bg-amber-300/10 text-stone-950' : '' }}"
                @if ($endpointsCurrent) aria-current="page" @endif
            >
                All endpoints
            </a>
            <a
                href="{{ route('endpoints.create') }}"
                data-testid="app-nav-new-endpoint"
                class="bfc-shell-link {{ $newEndpointCurrent ? 'bg-amber-300/10 text-stone-950' : '' }}"
                @if ($newEndpointCurrent) aria-current="page" @endif
            >
                New endpoint
            </a>
        </nav>
    </x-slot:sidebar>

    <div data-testid="app-main-content" class="min-h-screen bg-stone-950 text-stone-100">
        <div class="min-h-screen bg-[radial-gradient(circle_at_top_right,_rgba(245,158,11,0.13),_transparent_36rem)]">
            <div class="mx-auto max-w-6xl px-5 py-12 sm:px-8 sm:py-16">
                @yield('content')
            </div>
        </div>
    </div>
</x-bfc-layout>
