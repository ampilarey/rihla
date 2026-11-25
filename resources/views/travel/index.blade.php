@extends('layouts.app')

@section('title', __('messages.Plan my trip'))

{{-- "Plan my trip" — docs/TRAVEL_AGENCY_PLAN.md (A1). Enquire and quote; works with no JavaScript. --}}
@section('content')
<div class="container mx-auto max-w-3xl px-4 section-y-tight">
    <header class="mb-6">
        <h1 dir="auto" class="section-title text-start">{{ __('messages.Plan my trip') }}</h1>
        <p dir="auto" class="text-brand-body">{{ __('messages.Flights, hotels and visas abroad. Tell us where, when and who; we send you options with prices, and you choose one.') }}</p>
    </header>

    @if(($serviceState ?? null) === \App\Support\Services::COMING_SOON)
        <div role="status" dir="auto" class="card mb-6 border-s-4 border-s-gold">
            {{ __('messages.We are opening our travel desk soon. Send your request now and we will reply with options.') }}
        </div>
    @endif

    @if($visas->isNotEmpty())
        <details class="card mb-6">
            <summary dir="auto" class="cursor-pointer font-bold text-ink">{{ __('messages.Visas we handle') }}</summary>
            <ul class="mt-3 space-y-4">
                @foreach($visas as $visa)
                    <li dir="auto">
                        <p class="font-medium text-ink">{{ $visa->country }} — {{ $visa->visa_type }}</p>
                        <p class="text-sm text-ink-muted">
                            @if($visa->processing_days){{ trans_choice('messages.About :count working day|About :count working days', $visa->processing_days, ['count' => $visa->processing_days]) }}@endif
                            @if($visa->fee()) · {{ $visa->fee()->format() }}@endif
                        </p>
                        <p class="text-sm text-brand-body">{{ __('messages.You will need:') }} {{ implode(', ', $visa->documents) }}</p>
                    </li>
                @endforeach
            </ul>
            <p dir="auto" class="mt-3 text-sm text-ink-muted">{{ __('messages.Another country? Ask; we handle others on request.') }}</p>
        </details>
    @endif

    <form method="POST" action="{{ route('travel.store') }}" class="card space-y-4" novalidate>
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

        @php($wants = (array) old('wants', ['flight']))
        <fieldset>
            <legend class="mb-1 block text-sm font-medium text-ink">{{ __('messages.What do you need?') }}</legend>
            <div class="flex flex-wrap gap-4">
                <label class="inline-flex items-center gap-2 text-sm text-ink"><input type="checkbox" name="wants[]" value="flight" @checked(in_array('flight', $wants, true))> {{ __('messages.Flight') }}</label>
                <label class="inline-flex items-center gap-2 text-sm text-ink"><input type="checkbox" name="wants[]" value="hotel" @checked(in_array('hotel', $wants, true))> {{ __('messages.Hotel') }}</label>
                <label class="inline-flex items-center gap-2 text-sm text-ink"><input type="checkbox" name="wants[]" value="visa" @checked(in_array('visa', $wants, true))> {{ __('messages.Visa') }}</label>
            </div>
        </fieldset>

        <div class="grid gap-4 sm:grid-cols-2">
            <div>
                <label for="trip-from" class="mb-1 block text-sm font-medium text-ink">{{ __('messages.Flying from') }}</label>
                <input id="trip-from" name="origin" type="text" maxlength="80" dir="auto" value="{{ old('origin', 'Malé') }}"
                       class="w-full rounded-lg border border-gray-500 px-3 py-2 text-ink">
            </div>
            <div>
                <label for="trip-to" class="mb-1 block text-sm font-medium text-ink">{{ __('messages.Where to') }}</label>
                <input id="trip-to" name="destination" type="text" required maxlength="120" dir="auto" value="{{ old('destination') }}"
                       class="w-full rounded-lg border border-gray-500 px-3 py-2 text-ink">
            </div>
            <div>
                <label for="trip-out" class="mb-1 block text-sm font-medium text-ink">{{ __('messages.Leaving on') }}</label>
                <input id="trip-out" name="depart_on" type="date" required min="{{ now()->toDateString() }}" value="{{ old('depart_on') }}"
                       class="w-full rounded-lg border border-gray-500 px-3 py-2 text-ink">
            </div>
            <div>
                <label for="trip-back" class="mb-1 block text-sm font-medium text-ink">{{ __('messages.Coming back on (optional)') }}</label>
                <input id="trip-back" name="return_on" type="date" min="{{ now()->toDateString() }}" value="{{ old('return_on') }}"
                       class="w-full rounded-lg border border-gray-500 px-3 py-2 text-ink">
            </div>
            <div>
                <label for="trip-adults" class="mb-1 block text-sm font-medium text-ink">{{ __('messages.Adults') }}</label>
                <input id="trip-adults" name="adults" type="number" required min="1" max="50" inputmode="numeric" value="{{ old('adults', 1) }}"
                       class="w-full rounded-lg border border-gray-500 px-3 py-2 text-ink">
            </div>
            <div>
                <label for="trip-children" class="mb-1 block text-sm font-medium text-ink">{{ __('messages.Children') }}</label>
                <input id="trip-children" name="children" type="number" min="0" max="50" inputmode="numeric" value="{{ old('children', 0) }}"
                       class="w-full rounded-lg border border-gray-500 px-3 py-2 text-ink">
            </div>
        </div>

        <div class="grid gap-4 sm:grid-cols-2">
            <div>
                <label for="trip-name" class="mb-1 block text-sm font-medium text-ink">{{ __('messages.Your name') }}</label>
                <input id="trip-name" name="contact_name" type="text" required maxlength="120" autocomplete="name" dir="auto"
                       value="{{ old('contact_name') }}" class="w-full rounded-lg border border-gray-500 px-3 py-2 text-ink">
            </div>
            <div>
                <label for="trip-phone" class="mb-1 block text-sm font-medium text-ink">{{ __('messages.Phone or WhatsApp') }}</label>
                <input id="trip-phone" name="contact_phone" type="tel" required maxlength="30" autocomplete="tel" dir="ltr"
                       value="{{ old('contact_phone') }}" class="w-full rounded-lg border border-gray-500 px-3 py-2 text-ink">
            </div>
            <div class="sm:col-span-2">
                <label for="trip-email" class="mb-1 block text-sm font-medium text-ink">{{ __('messages.Email (optional)') }}</label>
                <input id="trip-email" name="contact_email" type="email" maxlength="190" autocomplete="email" dir="ltr"
                       value="{{ old('contact_email') }}" class="w-full rounded-lg border border-gray-500 px-3 py-2 text-ink">
            </div>
        </div>

        <div>
            <label for="trip-notes" class="mb-1 block text-sm font-medium text-ink">{{ __('messages.Anything else we should know') }}</label>
            <textarea id="trip-notes" name="notes" rows="3" maxlength="2000" dir="auto"
                      class="w-full rounded-lg border border-gray-500 px-3 py-2 text-ink">{{ old('notes') }}</textarea>
        </div>

        {{-- A field no human sees. --}}
        <div class="hidden" aria-hidden="true">
            <label for="trip-website">Website</label>
            <input id="trip-website" name="website" type="text" tabindex="-1" autocomplete="off">
        </div>

        <button type="submit" class="btn-primary w-full">{{ __('messages.Send my request') }}</button>
    </form>
</div>
@endsection
