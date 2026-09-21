@extends('layouts.app')

@section('content')
    <div class="mx-auto grid max-w-2xl gap-8">
        <a href="{{ route('endpoints.show', $endpoint) }}" class="text-sm font-medium text-stone-400 hover:text-amber-300">&larr; {{ $endpoint->name }}</a>

        @if (session('status'))
            <p class="rounded-2xl border border-amber-300/50 bg-amber-300/10 p-4 text-sm text-amber-100">{{ session('status') }}</p>
        @endif

        <section data-testid="destination-settings" class="rounded-3xl border border-white/10 bg-stone-900/80 p-7 sm:p-10">
            <p class="text-xs font-semibold uppercase tracking-[0.24em] text-amber-300">Replay destination</p>
            <h1 class="mt-3 text-3xl font-semibold tracking-tight text-white">One active destination</h1>
            <p class="mt-4 text-sm leading-6 text-stone-400">
                Replacing the destination preserves earlier delivery history. Credentials, local addresses, fragments, and redirects are not accepted.
            </p>

            @if ($destinationIntegrityError)
                <p class="mt-6 rounded-2xl border border-red-300/50 bg-red-300/10 p-4 text-sm text-red-100">
                    Endpoint #{{ $endpoint->id }} has multiple active destinations. Bobbin has disabled configuration and replay until the data is repaired.
                </p>
            @elseif ($activeDestination === null)
                <p class="mt-6 rounded-2xl border border-stone-700 bg-stone-950 p-4 text-sm text-stone-300">
                    Endpoint #{{ $endpoint->id }} does not have a replay destination yet.
                </p>
            @endif

            <form action="{{ route('destinations.update', $endpoint) }}" method="POST" class="mt-8 grid gap-6">
                @csrf
                @method('PUT')

                <div>
                    <label for="destination_url" class="block text-sm font-medium text-stone-200">Destination URL</label>
                    <input
                        id="destination_url"
                        name="destination_url"
                        type="url"
                        required
                        value="{{ old('destination_url', $activeDestination?->url) }}"
                        placeholder="https://example.com/webhooks"
                        @disabled($destinationIntegrityError)
                        class="mt-3 block w-full rounded-2xl border border-stone-700 bg-stone-950 px-4 py-3 text-white placeholder:text-stone-600 focus:border-amber-300 focus:outline-none focus:ring-2 focus:ring-amber-300/20"
                    >
                    @error('destination_url')
                        <p class="mt-2 text-sm text-red-300">{{ $message }}</p>
                    @enderror
                </div>

                <button type="submit" @disabled($destinationIntegrityError) class="w-full rounded-2xl bg-amber-300 px-5 py-3 font-semibold text-stone-950 transition hover:bg-amber-200 disabled:bg-stone-700 disabled:text-stone-400">
                    Save destination
                </button>
            </form>
        </section>

        <section data-testid="endpoint-settings" class="rounded-3xl border border-white/10 bg-stone-900/80 p-7 sm:p-10">
            <p class="text-xs font-semibold uppercase tracking-[0.24em] text-amber-300">Endpoint settings</p>
            <h2 class="mt-3 text-3xl font-semibold tracking-tight text-white">Retention and redaction</h2>
            <p class="mt-4 text-sm leading-6 text-stone-400">
                Changes apply only to future captures and never rewrite existing captures. Captures stored before redaction was enabled remain unchanged until retention pruning removes them.
            </p>

            <form action="{{ route('endpoint-settings.update', $endpoint) }}" method="POST" class="mt-8 grid gap-6">
                @csrf
                @method('PUT')

                <div>
                    <label for="retention_days" class="block text-sm font-medium text-stone-200">Retention days</label>
                    <p class="mt-1 text-sm text-stone-500">Captures are retained for 1 to 365 days.</p>
                    <input
                        id="retention_days"
                        name="retention_days"
                        type="number"
                        min="1"
                        max="365"
                        required
                        value="{{ old('retention_days', $endpoint->retention_days) }}"
                        class="mt-3 block w-full rounded-2xl border border-stone-700 bg-stone-950 px-4 py-3 text-white focus:border-amber-300 focus:outline-none focus:ring-2 focus:ring-amber-300/20"
                    >
                    @error('retention_days')
                        <p class="mt-2 text-sm text-red-300">{{ $message }}</p>
                    @enderror
                </div>

                <div>
                    <label for="redacted_header_names" class="block text-sm font-medium text-stone-200">Headers to redact</label>
                    <p class="mt-1 text-sm text-stone-500">Enter HTTP header names separated by commas or new lines. Matching is case-insensitive.</p>
                    <textarea
                        id="redacted_header_names"
                        name="redacted_header_names"
                        rows="5"
                        class="mt-3 block w-full rounded-2xl border border-stone-700 bg-stone-950 px-4 py-3 text-white placeholder:text-stone-600 focus:border-amber-300 focus:outline-none focus:ring-2 focus:ring-amber-300/20"
                        placeholder="authorization&#10;x-api-key"
                    >{{ old('redacted_header_names', implode("\n", $endpoint->redacted_header_names)) }}</textarea>
                    @error('redacted_header_names')
                        <p class="mt-2 text-sm text-red-300">{{ $message }}</p>
                    @enderror
                </div>

                <div class="rounded-2xl border border-stone-700 bg-stone-950 p-4">
                    <input type="hidden" name="body_redaction" value="0">
                    <label for="body_redaction" class="flex items-start gap-3 text-sm text-stone-200">
                        <input
                            id="body_redaction"
                            name="body_redaction"
                            type="checkbox"
                            value="1"
                            @checked(old('body_redaction', $endpoint->body_redaction))
                            class="mt-1"
                        >
                        <span>
                            <span class="block font-medium">Redact request bodies</span>
                            <span class="mt-1 block leading-6 text-stone-500">Replace body bytes before future captures are persisted.</span>
                        </span>
                    </label>
                    @error('body_redaction')
                        <p class="mt-2 text-sm text-red-300">{{ $message }}</p>
                    @enderror
                </div>

                <button type="submit" class="w-full rounded-2xl bg-amber-300 px-5 py-3 font-semibold text-stone-950 transition hover:bg-amber-200">
                    Save security settings
                </button>
            </form>
        </section>
    </div>
@endsection
