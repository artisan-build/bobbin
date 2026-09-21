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
        </div>
    </section>
@endsection
