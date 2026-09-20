@extends('layouts.app')

@section('title', __('messages.Book this package'))

@section('content')
    <div class="container mx-auto max-w-3xl px-4 section-y-tight">
        <nav class="mb-6 text-sm">
            <a href="{{ route('packages.show', $package->slug) }}" class="text-wine-600 hover:text-wine-700">
                &larr; {{ $package->title }}
            </a>
        </nav>

        <h1 dir="auto" class="mb-2 text-3xl font-bold text-ink">{{ __('messages.Book this package') }}</h1>
        <p dir="auto" class="mb-8 text-brand-body">
            {{ __('messages.Choose a departure and a room, and we will hold your seats while you enter the travellers.') }}
        </p>

        @if($departures->isEmpty())
            <p class="card text-center text-ink-muted">
                {{ __('messages.No dates are announced for this package yet. Message us and we will tell you first.') }}
            </p>
        @else
            <form method="POST" action="{{ route('booking.hold', $package->slug) }}" class="card space-y-6">
                @csrf

                <fieldset>
                    <legend dir="auto" class="mb-3 text-lg font-semibold text-ink">{{ __('messages.Departure') }}</legend>

                    <div class="space-y-3">
                        @foreach($departures as $departure)
                            @php($bookable = $departure->is_bookable)

                            <label class="flex cursor-pointer items-start gap-3 rounded-xl border p-4 transition-colors
                                          {{ $bookable ? 'border-cream-deep hover:border-wine-500' : 'border-cream-deep opacity-60' }}">
                                <input type="radio" name="departure" value="{{ $departure->id }}"
                                       class="mt-1 border-cream-deep text-wine-500 focus:ring-wine-500"
                                       @disabled(! $bookable)
                                       @checked(old('departure', $selected?->id) == $departure->id)>

                                <span class="min-w-0 flex-1">
                                    <span class="block font-medium text-ink" dir="ltr">
                                        <x-local-date :date="$departure->date_start" />
                                        &ndash;
                                        <x-local-date :date="$departure->date_end" />
                                    </span>

                                    @if($departure->airline)
                                        <span dir="auto" class="block text-sm text-ink-muted">{{ $departure->airline }}</span>
                                    @endif

                                    <x-seats-bar :departure="$departure" class="mt-2" />

                                    @unless($bookable)
                                        <span dir="auto" class="mt-2 block text-sm font-medium text-wine-600">
                                            @if($departure->is_sold_out)
                                                {{ __('messages.Fully booked') }}
                                            @else
                                                {{ __('messages.Booking is not open for this departure yet. Message us and we will arrange it.') }}
                                            @endif
                                        </span>
                                    @endunless
                                </span>
                            </label>
                        @endforeach
                    </div>

                    <x-input-error :messages="$errors->get('departure')" class="mt-2" />
                </fieldset>

                <div class="grid gap-4 sm:grid-cols-2">
                    <div>
                        <label dir="auto" for="occupancy" class="mb-1 block text-sm font-medium text-ink">
                            {{ __('messages.Room type') }}
                        </label>
                        <select id="occupancy" name="occupancy"
                                class="w-full rounded-lg border-cream-deep text-ink focus:border-wine-500 focus:ring-wine-500">
                            {{-- Every occupancy any departure of this package
                                 prices. The chosen departure is re-checked on
                                 submit, so a room this particular date does not
                                 offer comes back as an error rather than a
                                 price of zero. --}}
                            @foreach($package->occupanciesOffered() as $occupancy)
                                <option value="{{ $occupancy }}" @selected(old('occupancy') === $occupancy)>
                                    {{ __('messages.'.ucfirst($occupancy).' room') }}
                                </option>
                            @endforeach
                        </select>
                        <x-input-error :messages="$errors->get('occupancy')" class="mt-2" />
                    </div>

                    <div>
                        <label dir="auto" for="seats" class="mb-1 block text-sm font-medium text-ink">
                            {{ __('messages.How many travellers?') }}
                        </label>
                        <input id="seats" name="seats" type="number" inputmode="numeric"
                               min="1" max="{{ config('booking.party.max') }}"
                               value="{{ old('seats', 1) }}"
                               class="w-full rounded-lg border-cream-deep text-ink focus:border-wine-500 focus:ring-wine-500">
                        <x-input-error :messages="$errors->get('seats')" class="mt-2" />
                        <p dir="auto" class="mt-1 text-xs text-ink-muted">
                            {{ __('messages.Booking for a larger group? Message us and we will arrange it.') }}
                        </p>
                    </div>
                </div>

                <button type="submit" class="btn-primary w-full">
                    {{ __('messages.Hold my seats') }}
                </button>

                <p dir="auto" class="text-center text-xs text-ink-muted">
                    {{ __('messages.Holding seats costs nothing and does not commit you to anything.') }}
                </p>
            </form>
        @endif
    </div>
@endsection
