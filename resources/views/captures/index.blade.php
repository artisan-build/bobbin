@extends('layouts.app')

@section('content')
    <section data-testid="captures-index" class="mx-auto max-w-4xl">
        <a href="{{ route('endpoints.show', $endpoint) }}" class="text-sm font-medium text-clay-muted hover:text-clay-iron">&larr; {{ $endpoint->name }}</a>

        <div class="mt-8">
            <p class="text-xs font-semibold uppercase tracking-[0.24em] text-clay-brown">Captured requests</p>
            <h1 class="mt-3 text-3xl font-semibold tracking-tight text-clay-ink sm:text-4xl">{{ $endpoint->name }}</h1>
            <p class="mt-3 text-clay-muted">Every request below was persisted before Bobbin acknowledged it.</p>
        </div>

        @if ($captures->isEmpty())
            <div class="mt-10 rounded-3xl border border-dashed border-clay-edge bg-clay-surface p-8">
                <p class="text-xl font-medium text-clay-ink">No requests captured yet.</p>
                <p class="mt-2 text-clay-muted">Send a request to the endpoint capture URL to start this thread.</p>
            </div>
        @else
            <div class="mt-10 overflow-hidden rounded-3xl border border-clay-hairline bg-clay-surface">
                @foreach ($captures as $capture)
                    <a href="{{ route('captures.show', [$endpoint, $capture]) }}" class="flex items-center justify-between gap-6 border-b border-clay-hairline p-6 last:border-b-0 hover:bg-clay-slip">
                        <div class="min-w-0">
                            <div class="flex items-center gap-3">
                                <span class="rounded-full bg-clay-slip px-3 py-1 font-mono text-xs font-semibold text-clay-brown">{{ $capture->method }}</span>
                                <span class="text-sm text-clay-muted">#{{ $capture->id }}</span>
                            </div>
                            <p class="mt-3 truncate font-mono text-sm text-clay-soft">{{ $capture->raw_query === '' ? 'No query string' : $capture->raw_query }}</p>
                        </div>
                        <div class="shrink-0 text-right">
                            <time class="text-sm text-clay-muted" datetime="{{ $capture->received_at->toIso8601String() }}">{{ $capture->received_at->format('M j, Y H:i:s') }}</time>
                            <span class="ml-3 text-clay-brown" aria-hidden="true">&rarr;</span>
                        </div>
                    </a>
                @endforeach
            </div>
        @endif
    </section>
@endsection
