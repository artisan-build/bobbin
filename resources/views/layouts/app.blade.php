<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        @include('partials.head')
    </head>
    <body class="min-h-screen bg-stone-950 text-stone-100 antialiased">
        <div class="min-h-screen bg-[radial-gradient(circle_at_top_right,_rgba(245,158,11,0.13),_transparent_36rem)]">
            <header class="border-b border-white/10">
                <div class="mx-auto flex max-w-6xl items-center justify-between px-5 py-5 sm:px-8">
                    <a href="{{ route('endpoints.index') }}" class="flex items-center gap-3 text-lg font-semibold tracking-tight">
                        <span class="grid size-9 place-items-center rounded-full border border-amber-400/60 bg-amber-400/10 text-amber-300">B</span>
                        Bobbin
                    </a>
                    <a href="{{ route('endpoints.create') }}" class="rounded-full bg-amber-300 px-4 py-2 text-sm font-semibold text-stone-950 transition hover:bg-amber-200">
                        New endpoint
                    </a>
                </div>
            </header>

            <main class="mx-auto max-w-6xl px-5 py-12 sm:px-8 sm:py-16">
                @yield('content')
            </main>
        </div>
    </body>
</html>
