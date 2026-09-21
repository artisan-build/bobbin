@extends('layouts.app')

@section('content')
    <section data-testid="endpoints-create" class="mx-auto max-w-2xl">
        <a href="{{ route('endpoints.index') }}" class="text-sm font-medium text-stone-400 hover:text-amber-300">&larr; All endpoints</a>
        <div class="mt-8 rounded-3xl border border-white/10 bg-stone-900/80 p-7 sm:p-10">
            <p class="text-xs font-semibold uppercase tracking-[0.24em] text-amber-300">New endpoint</p>
            <h1 class="mt-3 text-3xl font-semibold tracking-tight text-white">Name this webhook thread</h1>

            <form action="{{ route('endpoints.store') }}" method="POST" class="mt-8">
                @csrf
                <label for="name" class="block text-sm font-medium text-stone-200">Endpoint name</label>
                <input
                    id="name"
                    name="name"
                    value="{{ old('name') }}"
                    required
                    maxlength="255"
                    autofocus
                    placeholder="Billing events"
                    class="mt-3 block w-full rounded-2xl border border-stone-700 bg-stone-950 px-4 py-3 text-white placeholder:text-stone-600 focus:border-amber-300 focus:outline-none focus:ring-2 focus:ring-amber-300/20"
                >
                @error('name')
                    <p class="mt-2 text-sm text-red-300">{{ $message }}</p>
                @enderror

                <button type="submit" class="mt-6 w-full rounded-2xl bg-amber-300 px-5 py-3 font-semibold text-stone-950 transition hover:bg-amber-200">
                    Create endpoint
                </button>
            </form>
        </div>
    </section>
@endsection
