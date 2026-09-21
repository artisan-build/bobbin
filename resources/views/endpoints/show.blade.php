@extends('layouts.app')

@section('content')
    <section data-testid="endpoint-detail" class="mx-auto max-w-3xl">
        <a href="{{ route('endpoints.index') }}" class="text-sm font-medium text-stone-400 hover:text-amber-300">&larr; All endpoints</a>

        <div class="mt-8 overflow-hidden rounded-3xl border border-white/10 bg-stone-900/80">
            <div class="border-b border-white/10 p-7 sm:p-10">
                <p class="text-xs font-semibold uppercase tracking-[0.24em] text-amber-300">Endpoint</p>
                <h1 class="mt-3 text-3xl font-semibold tracking-tight text-white sm:text-4xl">{{ $endpoint->name }}</h1>
            </div>
            <div class="p-7 sm:p-10">
                <p class="text-sm font-medium text-stone-300">Capture URL</p>
                <div class="mt-3 rounded-2xl border border-stone-700 bg-stone-950 p-4">
                    <code class="break-all text-sm leading-6 text-amber-200">{{ $endpoint->captureUrl() }}</code>
                </div>
                <p class="mt-4 text-sm leading-6 text-stone-500">The address is reserved. Public request capture arrives in the next product slice.</p>
            </div>
        </div>
    </section>
@endsection
