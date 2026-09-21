@extends('layouts.app')

@section('content')
    <section data-testid="endpoints-index">
        <div class="max-w-2xl">
            <p class="text-xs font-semibold uppercase tracking-[0.24em] text-amber-300">Webhook inbox</p>
            <h1 class="mt-4 text-4xl font-semibold tracking-tight text-white sm:text-6xl">Catch the request. Keep the thread.</h1>
            <p class="mt-5 text-lg leading-8 text-stone-400">Each named endpoint reserves one durable address for its webhook thread.</p>
        </div>

        @if ($endpoints->isEmpty())
            <div class="mt-12 rounded-3xl border border-dashed border-stone-700 bg-stone-900/60 p-8 sm:p-12">
                <p class="text-xl font-medium text-white">No endpoints yet.</p>
                <p class="mt-2 text-stone-400">Create one to reserve its capture URL.</p>
            </div>
        @else
            <div class="mt-12 grid gap-4 md:grid-cols-2">
                @foreach ($endpoints as $endpoint)
                    <a href="{{ route('endpoints.show', $endpoint) }}" class="group rounded-3xl border border-white/10 bg-stone-900/80 p-6 transition hover:-translate-y-0.5 hover:border-amber-300/50">
                        <div class="flex items-start justify-between gap-6">
                            <div>
                                <h2 class="text-xl font-semibold text-white">{{ $endpoint->name }}</h2>
                                <p class="mt-3 break-all font-mono text-sm leading-6 text-stone-400">{{ $endpoint->captureUrl() }}</p>
                            </div>
                            <span class="text-amber-300 transition group-hover:translate-x-1" aria-hidden="true">&rarr;</span>
                        </div>
                    </a>
                @endforeach
            </div>
        @endif
    </section>
@endsection
