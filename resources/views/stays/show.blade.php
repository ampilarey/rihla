@extends('layouts.app')

@section('title', $property->name)

{{--
    One property — §15.4 (Phase 9.4).

    Rooms are priced and checked for the chosen dates through the same
    Availability class the booking path uses. With no dates chosen there is
    no availability question to answer and no total to quote, so each room
    shows its own nightly rate and says that is what it is — inventing a
    price for "some dates" is how somebody arrives at checkout expecting a
    number nobody offered.
--}}
@section('content')
<div class="container mx-auto px-4 section-y-tight">

    {{-- §15.4: fall back to English and *say so*. A page silently in the
         wrong language reads as a site that does not care. --}}
    @unless($property->isTranslatedInto(app()->getLocale()))
        <p dir="auto" class="mx-auto mb-6 max-w-3xl rounded-xl border-s-4 border-s-gold bg-cream-deep px-4 py-3 text-sm text-ink">
            {{ __('messages.We have not translated this guesthouse yet, so it is shown in English.') }}
        </p>
    @endunless

    <div class="mx-auto max-w-3xl">
        <header class="mb-6">
            <h1 dir="auto" class="section-title text-start">{{ $property->name }}</h1>
            @if($property->island)
                <p dir="auto" class="text-ink-muted">{{ $property->island }}</p>
            @endif
        </header>

        @if($property->cover_image)
            <img src="{{ \Illuminate\Support\Facades\Storage::url($property->cover_image) }}"
                 alt="{{ $property->name }}"
                 width="1200" height="630" decoding="async"
                 class="mb-6 w-full rounded-2xl object-cover">
        @endif

        @if(filled($property->summary))
            <p dir="auto" class="mb-6 text-lg text-brand-body">{{ $property->summary }}</p>
        @endif

        @if(filled($property->description))
            <div dir="auto" class="mb-8 whitespace-pre-line text-brand-body">{{ $property->description }}</div>
        @endif

        {{-- The rooms --}}
        <section class="mb-10" aria-labelledby="stays-rooms-heading">
            <h2 id="stays-rooms-heading" dir="auto" class="mb-4 text-2xl font-bold text-ink">
                {{ __('messages.Rooms') }}
            </h2>

            @if($filters->hasDates())
                <p dir="auto" class="mb-4 text-sm text-ink-muted">
                    {{ __('messages.Prices for :nights night(s), :from to :to.', [
                        'nights' => $filters->nights(),
                        'from' => $filters->checkIn->isoFormat('D MMM YYYY'),
                        'to' => $filters->checkOut->isoFormat('D MMM YYYY'),
                    ]) }}
                </p>
            @else
                <p dir="auto" class="mb-4 text-sm text-ink-muted">
                    {{ __('messages.Choose your dates to see what is free and what it comes to.') }}
                </p>
            @endif

            <div class="space-y-4">
                @foreach($rooms as $entry)
                    @php($room = $entry['room'])
                    <article class="card">
                        <div class="flex flex-wrap items-start justify-between gap-3">
                            <div>
                                <h3 dir="auto" class="text-lg font-bold text-ink">{{ $room->name }}</h3>
                                <p dir="auto" class="text-sm text-ink-muted">
                                    {{ __('messages.Sleeps :count', ['count' => $room->sleeps]) }}
                                    @if($room->beds) · {{ $room->beds }} @endif
                                </p>
                            </div>

                            <div class="text-end">
                                @if($entry['quote'] && $entry['available'])
                                    <p dir="auto" class="text-lg font-bold text-ink">{{ $entry['quote']->total()->format() }}</p>
                                    <p dir="auto" class="text-xs text-ink-muted">
                                        {{ __('messages.for :nights night(s)', ['nights' => $entry['quote']->nights()]) }}
                                    </p>
                                @elseif($entry['available'] === false)
                                    <p dir="auto" class="rounded-full bg-cream-deep px-3 py-1 text-sm text-ink">
                                        {{ __('messages.Not free for those dates') }}
                                    </p>
                                @else
                                    <p dir="auto" class="text-lg font-bold text-ink">{{ $room->baseRate()->format() }}</p>
                                    <p dir="auto" class="text-xs text-ink-muted">{{ __('messages.a night') }}</p>
                                @endif
                            </div>
                        </div>

                        @if(filled($room->description))
                            <p dir="auto" class="mt-3 text-sm text-ink-muted">{{ $room->description }}</p>
                        @endif
                    </article>
                @endforeach
            </div>
        </section>

        @if($property->amenity_list !== [])
            <section class="mb-10" aria-labelledby="stays-amenities-heading">
                <h2 id="stays-amenities-heading" dir="auto" class="mb-3 text-2xl font-bold text-ink">
                    {{ __('messages.What is here') }}
                </h2>
                <ul dir="auto" class="grid gap-2 text-brand-body sm:grid-cols-2">
                    @foreach($property->amenity_list as $amenity)
                        <li>{{ $amenity }}</li>
                    @endforeach
                </ul>
            </section>
        @endif

        @if(filled($property->house_rules))
            <section class="mb-10" aria-labelledby="stays-rules-heading">
                <h2 id="stays-rules-heading" dir="auto" class="mb-3 text-2xl font-bold text-ink">
                    {{ __('messages.House rules') }}
                </h2>
                <div dir="auto" class="whitespace-pre-line text-brand-body">{{ $property->house_rules }}</div>
            </section>
        @endif

        {{-- The policy, in the reader's language and in plain numbers. This
             is what a stay booked today would be held to — §15.2 decision 2
             — and it is read from the property because no stay exists yet. --}}
        <section class="mb-10" aria-labelledby="stays-policy-heading">
            <h2 id="stays-policy-heading" dir="auto" class="mb-3 text-2xl font-bold text-ink">
                {{ __('messages.Paying and cancelling') }}
            </h2>
            <ul dir="auto" class="space-y-2 text-brand-body">
                <li>{{ __('messages.:percent% deposit when the guesthouse confirms.', ['percent' => $property->deposit_pct]) }}</li>
                <li>{{ __('messages.The rest is due :days days before you arrive.', ['days' => $property->balance_days_before]) }}</li>
                <li>{{ __('messages.Cancel more than :days days before and the deposit comes back.', ['days' => $property->free_cancel_days]) }}</li>
                @if($property->partner?->green_tax_mode === \App\Models\Partner::GREEN_TAX_AT_PROPERTY)
                    <li>{{ __('messages.Green tax is paid at the guesthouse, not here.') }}</li>
                @endif
            </ul>
        </section>

        <div class="card text-center">
            <p dir="auto" class="mb-4 text-sm text-ink-muted">
                @if($bookable)
                    {{ __('messages.Ask for these dates and we will check with the guesthouse. You pay nothing until they confirm.') }}
                @else
                    {{ __('messages.Booking opens soon. Ask us and we will hold something for you.') }}
                @endif
            </p>
            <a href="{{ \App\Support\Contact::whatsappUrl() }}" target="_blank" rel="noopener" class="btn-primary">
                {{ __('messages.Message us') }}
            </a>
        </div>
    </div>
</div>
@endsection
