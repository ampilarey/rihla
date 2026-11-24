@extends('layouts.app')

@section('title', __('messages.Send cargo or charter a boat'))

{{-- Cargo and charter requests — docs/TRANSPORT_PLAN.md (T4). Quoted each time; works with no JavaScript. --}}
@section('content')
<div class="container mx-auto max-w-3xl px-4 section-y-tight">
    <header class="mb-6">
        <p dir="auto" class="text-sm text-ink-muted">
            <a href="{{ route('transport.index') }}" class="text-wine-700 underline hover:no-underline">{{ __('messages.Boats between islands') }}</a>
        </p>
        <h1 dir="auto" class="section-title text-start">{{ __('messages.Send cargo or charter a boat') }}</h1>
        <p dir="auto" class="text-brand-body">{{ __('messages.Tell us what you need. We reply with a price; you pay only if you accept it.') }}</p>
    </header>

    <form method="POST" action="{{ route('transport.requests.store') }}" class="card space-y-4" novalidate>
        @csrf

        @if($errors->any())
            <div role="alert" class="rounded-xl border-s-4 border-s-error bg-cream-deep px-4 py-3 text-sm text-ink">
                <ul class="list-disc ps-5">
                    @foreach($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        @php($chosen = old('kind', $kind))
        <fieldset>
            <legend class="mb-1 block text-sm font-medium text-ink">{{ __('messages.What do you need?') }}</legend>
            <div class="flex flex-wrap gap-4">
                <label class="inline-flex items-center gap-2 text-sm text-ink">
                    <input type="radio" name="kind" value="cargo" @checked($chosen === 'cargo')> {{ __('messages.Send cargo') }}
                </label>
                <label class="inline-flex items-center gap-2 text-sm text-ink">
                    <input type="radio" name="kind" value="charter" @checked($chosen === 'charter')> {{ __('messages.Charter a boat') }}
                </label>
            </div>
        </fieldset>

        <div class="grid gap-4 sm:grid-cols-3">
            <div>
                <label for="req-from" class="mb-1 block text-sm font-medium text-ink">{{ __('messages.From') }}</label>
                <select id="req-from" name="origin_island_id" required class="w-full rounded-lg border border-gray-500 px-3 py-2 text-ink">
                    <option value="">—</option>
                    @foreach($islands as $id => $name)
                        <option value="{{ $id }}" @selected((int) old('origin_island_id') === $id)>{{ $name }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label for="req-to" class="mb-1 block text-sm font-medium text-ink">{{ __('messages.To') }}</label>
                <select id="req-to" name="destination_island_id" required class="w-full rounded-lg border border-gray-500 px-3 py-2 text-ink">
                    <option value="">—</option>
                    @foreach($islands as $id => $name)
                        <option value="{{ $id }}" @selected((int) old('destination_island_id') === $id)>{{ $name }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label for="req-date" class="mb-1 block text-sm font-medium text-ink">{{ __('messages.On') }}</label>
                <input id="req-date" name="wanted_on" type="date" required min="{{ now()->toDateString() }}" value="{{ old('wanted_on') }}"
                       class="w-full rounded-lg border border-gray-500 px-3 py-2 text-ink">
            </div>
        </div>

        <div>
            <label for="req-cargo" class="mb-1 block text-sm font-medium text-ink">{{ __('messages.For cargo: what is it, and how many pieces?') }}</label>
            <textarea id="req-cargo" name="cargo_description" rows="3" maxlength="2000" dir="auto"
                      class="w-full rounded-lg border border-gray-500 px-3 py-2 text-ink">{{ old('cargo_description') }}</textarea>
        </div>
        <div class="grid gap-4 sm:grid-cols-2">
            <div>
                <label for="req-weight" class="mb-1 block text-sm font-medium text-ink">{{ __('messages.For cargo: weight in kg, if you know it') }}</label>
                <input id="req-weight" name="cargo_weight_kg" type="number" min="1" inputmode="numeric" value="{{ old('cargo_weight_kg') }}"
                       class="w-full rounded-lg border border-gray-500 px-3 py-2 text-ink">
            </div>
            <div>
                <label for="req-people" class="mb-1 block text-sm font-medium text-ink">{{ __('messages.For a charter: how many people?') }}</label>
                <input id="req-people" name="passengers" type="number" min="1" inputmode="numeric" value="{{ old('passengers') }}"
                       class="w-full rounded-lg border border-gray-500 px-3 py-2 text-ink">
            </div>
        </div>

        <div class="grid gap-4 sm:grid-cols-2">
            <div>
                <label for="req-name" class="mb-1 block text-sm font-medium text-ink">{{ __('messages.Your name') }}</label>
                <input id="req-name" name="contact_name" type="text" required maxlength="120" autocomplete="name" dir="auto"
                       value="{{ old('contact_name') }}" class="w-full rounded-lg border border-gray-500 px-3 py-2 text-ink">
            </div>
            <div>
                <label for="req-phone" class="mb-1 block text-sm font-medium text-ink">{{ __('messages.Phone or WhatsApp') }}</label>
                <input id="req-phone" name="contact_phone" type="tel" required maxlength="30" autocomplete="tel" dir="ltr"
                       value="{{ old('contact_phone') }}" class="w-full rounded-lg border border-gray-500 px-3 py-2 text-ink">
            </div>
        </div>

        <div>
            <label for="req-notes" class="mb-1 block text-sm font-medium text-ink">{{ __('messages.Anything else we should know') }}</label>
            <textarea id="req-notes" name="notes" rows="2" maxlength="2000" dir="auto"
                      class="w-full rounded-lg border border-gray-500 px-3 py-2 text-ink">{{ old('notes') }}</textarea>
        </div>

        {{-- A field no human sees. --}}
        <div class="hidden" aria-hidden="true">
            <label for="req-website">Website</label>
            <input id="req-website" name="website" type="text" tabindex="-1" autocomplete="off">
        </div>

        <button type="submit" class="btn-primary w-full">{{ __('messages.Ask for a price') }}</button>
    </form>
</div>
@endsection
