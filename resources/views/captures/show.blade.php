@extends('layouts.app')

@section('content')
    <section data-testid="capture-detail" class="mx-auto max-w-4xl">
        <a href="{{ route('captures.index', $endpoint) }}" class="text-sm font-medium text-stone-400 hover:text-amber-300">&larr; Captures for {{ $endpoint->name }}</a>

        <div class="mt-8 flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
            <div>
                <p class="text-xs font-semibold uppercase tracking-[0.24em] text-amber-300">Captured request</p>
                <h1 class="mt-3 text-3xl font-semibold tracking-tight text-white sm:text-4xl">{{ $capture->method }} request #{{ $capture->id }}</h1>
            </div>
            <time class="text-sm text-stone-400" datetime="{{ $capture->received_at->toIso8601String() }}">Received {{ $capture->received_at->format('M j, Y H:i:s') }}</time>
        </div>

        <div class="mt-10 grid gap-6">
            <section data-testid="replay-action" class="rounded-3xl border border-amber-300/30 bg-amber-300/5 p-7">
                <h2 class="text-lg font-semibold text-white">Replay this capture</h2>
                <p class="mt-3 text-sm leading-6 text-stone-400">
                    Delivery is at-least-once: a receiver can act before a worker records success. Redirects are never followed, and every 3xx response is terminal.
                </p>

                @if (session('status'))
                    <p class="mt-4 rounded-2xl border border-amber-300/50 bg-amber-300/10 p-4 text-sm text-amber-100">{{ session('status') }}</p>
                @endif

                @error('replay')
                    <p class="mt-4 rounded-2xl border border-red-300/50 bg-red-300/10 p-4 text-sm text-red-100">{{ $message }}</p>
                @enderror

                @if ($activeDestinationCount === 1)
                    <form action="{{ route('captures.replay', [$endpoint, $capture]) }}" method="POST" class="mt-5">
                        @csrf
                        <button type="submit" class="rounded-full bg-amber-300 px-5 py-2 text-sm font-semibold text-stone-950 hover:bg-amber-200">
                            Queue replay
                        </button>
                    </form>
                @else
                    <p class="mt-5 text-sm text-red-200">
                        Endpoint #{{ $endpoint->id }} needs exactly one active destination before capture #{{ $capture->id }} can be replayed.
                    </p>
                @endif
            </section>

            <div class="rounded-3xl border border-white/10 bg-stone-900/80 p-7">
                <h2 class="text-lg font-semibold text-white">Query string</h2>
                @if ($capture->raw_query === '')
                    <p class="mt-4 text-stone-400">No query string received.</p>
                @else
                    <pre class="mt-4 overflow-x-auto whitespace-pre-wrap break-all rounded-2xl bg-stone-950 p-4 font-mono text-sm leading-6 text-amber-100">{{ $capture->raw_query }}</pre>
                @endif
            </div>

            <div class="rounded-3xl border border-white/10 bg-stone-900/80 p-7">
                <h2 class="text-lg font-semibold text-white">Headers</h2>
                @if ($capture->redacted_header_names !== [])
                    <p data-testid="capture-header-redaction" class="mt-3 text-sm text-red-300">
                        Redacted before storage: {{ implode(', ', $capture->redacted_header_names) }}
                    </p>
                @endif
                <div class="mt-4 overflow-hidden rounded-2xl border border-stone-700">
                    @foreach ($capture->headers as $name => $values)
                        @foreach ($values as $value)
                            <div class="grid gap-1 border-b border-stone-800 p-4 last:border-b-0 sm:grid-cols-[14rem_1fr] sm:gap-4">
                                <div>
                                    <code class="text-sm text-amber-200">{{ $name }}</code>
                                    @if (in_array(strtolower($name), $capture->redacted_header_names, true))
                                        <span class="mt-1 block text-xs font-semibold uppercase tracking-[0.16em] text-red-300">Redacted before storage</span>
                                    @endif
                                </div>
                                <code class="break-all text-sm text-stone-300">{{ $value }}</code>
                            </div>
                        @endforeach
                    @endforeach
                </div>
            </div>

            <div class="rounded-3xl border border-white/10 bg-stone-900/80 p-7">
                <div class="flex items-center justify-between gap-4">
                    <h2 class="text-lg font-semibold text-white">Body</h2>
                    @if ($capture->body_redacted)
                        <span data-testid="capture-body-redaction" class="text-xs font-semibold uppercase tracking-[0.16em] text-red-300">Redacted before storage</span>
                    @elseif ($capture->body !== '')
                        <span class="text-xs font-semibold uppercase tracking-[0.16em] text-stone-500">
                            {{ $bodyIsText ? 'UTF-8 text' : 'Base64 encoded' }}
                        </span>
                    @endif
                </div>
                @if ($capture->body_redacted)
                    <p class="mt-4 text-stone-400">Body bytes were removed before this capture was persisted.</p>
                @elseif ($capture->body === '')
                    <p class="mt-4 text-stone-400">No body bytes received.</p>
                @elseif ($bodyIsText)
                    <pre class="mt-4 overflow-x-auto whitespace-pre-wrap break-all rounded-2xl bg-stone-950 p-4 font-mono text-sm leading-6 text-stone-200">{{ $capture->body }}</pre>
                @else
                    <p class="mt-3 text-sm text-stone-400">Decode this Base64 value to recover the exact stored bytes.</p>
                    <pre class="mt-4 overflow-x-auto whitespace-pre-wrap break-all rounded-2xl bg-stone-950 p-4 font-mono text-sm leading-6 text-stone-200">{{ base64_encode($capture->body) }}</pre>
                @endif
            </div>

            <section data-testid="delivery-history" class="rounded-3xl border border-white/10 bg-stone-900/80 p-7">
                <h2 class="text-lg font-semibold text-white">Delivery history</h2>

                @if ($deliveries->isEmpty())
                    <p class="mt-4 text-stone-400">Capture #{{ $capture->id }} has no delivery attempts.</p>
                @else
                    <div class="mt-5 grid gap-4">
                        @foreach ($deliveries as $delivery)
                            <article class="rounded-2xl border border-stone-700 bg-stone-950 p-5">
                                <div class="flex flex-wrap items-center justify-between gap-3">
                                    <p class="font-semibold text-white">Delivery #{{ $delivery->id }}</p>
                                    <span class="rounded-full border border-stone-700 px-3 py-1 text-xs font-semibold uppercase tracking-[0.16em] text-amber-200">{{ $delivery->status }}</span>
                                </div>
                                <p class="mt-3 break-all font-mono text-sm text-stone-300">{{ $delivery->destination->url }}</p>
                                <dl class="mt-5 grid gap-4 text-sm sm:grid-cols-2">
                                    <div>
                                        <dt class="text-stone-500">Attempts</dt>
                                        <dd class="mt-1 text-stone-200">{{ $delivery->attempt_count }}</dd>
                                    </div>
                                    <div>
                                        <dt class="text-stone-500">Response status</dt>
                                        <dd class="mt-1 text-stone-200">{{ $delivery->response_status ?? 'No response received' }}</dd>
                                    </div>
                                    <div class="sm:col-span-2">
                                        <dt class="text-stone-500">Latest safe error</dt>
                                        <dd class="mt-1 text-stone-200">{{ $delivery->last_error ?? 'No delivery error recorded' }}</dd>
                                    </div>
                                    <div>
                                        <dt class="text-stone-500">Queued</dt>
                                        <dd class="mt-1 text-stone-200"><time datetime="{{ $delivery->created_at->toIso8601String() }}">{{ $delivery->created_at->format('M j, Y H:i:s') }}</time></dd>
                                    </div>
                                    <div>
                                        <dt class="text-stone-500">Started</dt>
                                        <dd class="mt-1 text-stone-200">{{ $delivery->started_at?->format('M j, Y H:i:s') ?? 'Not started' }}</dd>
                                    </div>
                                    <div>
                                        <dt class="text-stone-500">Completed</dt>
                                        <dd class="mt-1 text-stone-200">{{ $delivery->completed_at?->format('M j, Y H:i:s') ?? 'Not completed' }}</dd>
                                    </div>
                                </dl>
                            </article>
                        @endforeach
                    </div>
                @endif
            </section>
        </div>
    </section>
@endsection
