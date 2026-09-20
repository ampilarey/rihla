@extends('layouts.app')

@section('title', __('messages.Review your booking'))

@section('content')
    <div class="container mx-auto max-w-3xl px-4 section-y-tight">
        <h1 dir="auto" class="mb-6 text-3xl font-bold text-ink">{{ __('messages.Review your booking') }}</h1>

        <x-hold-timer :hold="$hold" class="mb-6" />

        <section class="card mb-6 space-y-4">
            <div>
                <h2 dir="auto" class="text-lg font-semibold text-ink">{{ $package->title }}</h2>
                <p class="text-ink-muted" dir="ltr">
                    <x-local-date :date="$departure->date_start" />
                    &ndash;
                    <x-local-date :date="$departure->date_end" />
                </p>
                @if($departure->airline)
                    <p dir="auto" class="text-sm text-ink-muted">{{ $departure->airline }}</p>
                @endif
            </div>

            @if($departure->hotels->isNotEmpty())
                <ul class="space-y-1 text-sm">
                    @foreach($departure->hotels as $hotel)
                        <li dir="auto" class="text-ink">
                            {{ $hotel->city }}: {{ $hotel->name }}
                            <x-hotel-distance :hotel="$hotel" />
                        </li>
                    @endforeach
                </ul>
            @endif
        </section>

        <section class="card mb-6">
            <h2 dir="auto" class="mb-4 text-lg font-semibold text-ink">{{ __('messages.Travellers') }}</h2>

            <ul class="divide-y divide-cream-deep">
                @foreach($booking->travellers as $bookingTraveller)
                    <li class="flex items-baseline justify-between gap-4 py-3">
                        <span class="min-w-0">
                            <span dir="auto" class="block font-medium text-ink">
                                {{ $bookingTraveller->traveller->full_name }}
                            </span>
                            <span dir="auto" class="block text-sm text-ink-muted">
                                {{ __('messages.'.ucfirst($bookingTraveller->occupancy).' room') }}
                                @if($bookingTraveller->pax_type !== 'adult')
                                    &middot; {{ __('messages.'.ucfirst($bookingTraveller->pax_type).' price') }}
                                @endif
                            </span>
                        </span>
                        <span class="shrink-0 font-semibold text-ink" dir="ltr">
                            {{ $bookingTraveller->money()->format() }}
                        </span>
                    </li>
                @endforeach
            </ul>

            <div class="mt-4 flex items-baseline justify-between border-t border-cream-deep pt-4">
                <span dir="auto" class="text-lg font-semibold text-ink">{{ __('messages.Total') }}</span>
                <span class="text-lg font-bold text-ink" dir="ltr">{{ $booking->total()->format() }}</span>
            </div>

            {{--
                No deposit, no instalment schedule and no payment due date.
                Rihla's terms — whether the deposit is a percentage or a flat
                sum, how many instalments and when they fall due — are
                business policy nobody has stated, and printing a plausible
                one next to a real price is how this site once advertised
                social accounts that did not exist.
            --}}
            <p dir="auto" class="mt-2 text-sm text-ink-muted">
                {{ __('messages.Payment is arranged with our team after you submit this booking.') }}
            </p>
        </section>

        <form method="POST" action="{{ route('booking.confirm') }}" class="card space-y-4">
            @csrf

            <label class="flex cursor-pointer items-start gap-3">
                <input type="checkbox" name="confirmed" value="1"
                       class="mt-1 rounded border-cream-deep text-wine-500 focus:ring-wine-500">
                <span dir="auto" class="text-sm text-ink">
                    {{ __('messages.I confirm these details are correct and understand that Rihla will contact me to arrange payment.') }}
                </span>
            </label>

            <x-input-error :messages="$errors->get('confirmed')" />

            <div class="flex flex-col gap-3 sm:flex-row-reverse">
                <button type="submit" class="btn-primary flex-1">{{ __('messages.Confirm booking') }}</button>
                <a href="{{ route('booking.travellers') }}" class="btn-secondary flex-1">
                    {{ __('messages.Change traveller details') }}
                </a>
            </div>
        </form>
    </div>
@endsection
