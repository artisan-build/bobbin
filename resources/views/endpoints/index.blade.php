@extends('layouts.app')

@section('content')
    <section data-testid="endpoints-index">
        <form action="{{ route('endpoints.store') }}" method="POST" class="rounded-3xl border border-clay-hairline bg-clay-surface p-6" data-testid="endpoints-new-form">
            @csrf
            <label for="name" class="block text-sm font-medium text-clay-soft">Name a new endpoint</label>
            <div class="mt-3 flex flex-col gap-3 sm:flex-row">
                <input
                    id="name"
                    name="name"
                    value="{{ old('name') }}"
                    required
                    maxlength="255"
                    placeholder="Billing events"
                    class="block w-full rounded-2xl border border-clay-edge bg-clay-paper px-4 py-3 text-clay-ink placeholder:text-clay-dot focus:border-clay-brown focus:outline-none focus:ring-2 focus:ring-clay-brown/20"
                >
                <button type="submit" class="shrink-0 rounded-2xl bg-clay-brown px-5 py-3 font-semibold text-clay-surface transition hover:bg-clay-iron">
                    Create endpoint
                </button>
            </div>
            @error('name')
                <p class="mt-2 text-sm text-red-700">{{ $message }}</p>
            @enderror
        </form>

        @if ($endpoints->isEmpty())
            <div class="mt-8 rounded-3xl border border-dashed border-clay-edge bg-clay-surface p-8 sm:p-12">
                <p class="text-xl font-medium text-clay-ink">No endpoints yet.</p>
                <p class="mt-2 text-clay-muted">Name one above to reserve its capture URL.</p>
            </div>
        @else
            <div class="mt-8 grid gap-4 md:grid-cols-2">
                @foreach ($endpoints as $endpoint)
                    <a href="{{ route('endpoints.show', $endpoint) }}" class="group rounded-3xl border border-clay-hairline bg-clay-surface p-6 transition hover:-translate-y-0.5 hover:border-clay-brown/50">
                        <div class="flex items-start justify-between gap-6">
                            <div>
                                <h2 class="text-xl font-semibold text-clay-ink">{{ $endpoint->name }}</h2>
                                <p class="mt-3 break-all font-mono text-sm leading-6 text-clay-muted">{{ $endpoint->captureUrl() }}</p>
                            </div>
                            <span class="text-clay-brown transition group-hover:translate-x-1" aria-hidden="true">&rarr;</span>
                        </div>
                    </a>
                @endforeach
            </div>
        @endif
    </section>
@endsection
