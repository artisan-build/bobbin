@extends('layouts.app')

@section('content')
    <section data-testid="endpoint-detail" class="mx-auto max-w-3xl">
        <a href="{{ route('endpoints.index') }}" class="text-sm font-medium text-clay-muted hover:text-clay-iron">&larr; All endpoints</a>

        <div class="mt-8 overflow-hidden rounded-3xl border border-clay-hairline bg-clay-surface">
            <div class="border-b border-clay-hairline p-7 sm:p-10">
                <p class="text-xs font-semibold uppercase tracking-[0.24em] text-clay-brown">Endpoint</p>
                <h1 class="mt-3 text-3xl font-semibold tracking-tight text-clay-ink sm:text-4xl">{{ $endpoint->name }}</h1>
            </div>
            <div class="p-7 sm:p-10">
                <p class="text-sm font-medium text-clay-soft">Capture URL</p>
                <div class="mt-3 rounded-2xl border border-clay-edge bg-clay-paper p-4">
                    <code class="break-all text-sm leading-6 text-clay-brown">{{ $endpoint->captureUrl() }}</code>
                </div>
                <div class="mt-6 flex flex-wrap items-center justify-between gap-4">
                    <p class="text-sm leading-6 text-clay-muted">Requests sent to this address are stored before they are acknowledged.</p>
                    <div class="flex gap-3">
                        <a href="{{ route('endpoint-settings.edit', $endpoint) }}" class="shrink-0 rounded-full border border-clay-edge px-4 py-2 text-sm font-semibold text-clay-soft hover:border-clay-dot hover:text-clay-ink">
                            Security settings
                        </a>
                        <a href="{{ route('captures.index', $endpoint) }}" class="shrink-0 rounded-full border border-clay-brown/50 px-4 py-2 text-sm font-semibold text-clay-brown hover:border-clay-iron hover:text-clay-iron">
                            View captures
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </section>
@endsection
