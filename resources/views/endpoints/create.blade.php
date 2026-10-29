@extends('layouts.app')

@section('content')
    <section data-testid="endpoints-create" class="mx-auto max-w-2xl">
        <a href="{{ route('endpoints.index') }}" class="text-sm font-medium text-clay-muted hover:text-clay-iron">&larr; All endpoints</a>
        <div class="mt-8 rounded-3xl border border-clay-hairline bg-clay-surface p-7 sm:p-10">
            <p class="text-xs font-semibold uppercase tracking-[0.24em] text-clay-brown">New endpoint</p>
            <h1 class="mt-3 text-3xl font-semibold tracking-tight text-clay-ink">Name this webhook thread</h1>

            <form action="{{ route('endpoints.store') }}" method="POST" class="mt-8">
                @csrf
                <label for="name" class="block text-sm font-medium text-clay-soft">Endpoint name</label>
                <input
                    id="name"
                    name="name"
                    value="{{ old('name') }}"
                    required
                    maxlength="255"
                    autofocus
                    placeholder="Billing events"
                    class="mt-3 block w-full rounded-2xl border border-clay-edge bg-clay-paper px-4 py-3 text-clay-ink placeholder:text-clay-dot focus:border-clay-brown focus:outline-none focus:ring-2 focus:ring-clay-brown/20"
                >
                @error('name')
                    <p class="mt-2 text-sm text-red-700">{{ $message }}</p>
                @enderror

                <button type="submit" class="mt-6 w-full rounded-2xl bg-clay-brown px-5 py-3 font-semibold text-clay-surface transition hover:bg-clay-iron">
                    Create endpoint
                </button>
            </form>
        </div>
    </section>
@endsection
