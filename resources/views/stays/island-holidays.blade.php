@extends('layouts.app')

@section('title', $label)

{{--
    Island holidays — §15.5 (Phase 10).

    The Umrah package engine wearing a different shirt: these are rows in
    `packages`, listed here because they are a guesthouse product and
    belong under Stays. That is the owner's correction in §15.1, and it is
    the reason this page exists rather than a third tab called "Holidays".

    Prices are in MVR because `price_tiers.currency` defaults to it — the
    currency belongs to the product, and a local family thinks in rufiyaa.
--}}
@section('content')
<div class="container mx-auto px-4 section-y-tight">
    <header class="mx-auto mb-8 max-w-2xl text-center">
        <h1 dir="auto" class="section-title">{{ $label }}</h1>
        <p dir="auto" class="text-brand-body">{{ $blurb }}</p>

        @unless($bookable)
            <p dir="auto" class="mt-4 inline-flex rounded-full bg-cream-deep px-4 py-2 text-sm text-ink">
                {{ __('messages.Booking opens soon. Ask us and we will hold something for you.') }}
            </p>
        @endunless
    </header>

    <div class="grid gap-6 md:grid-cols-2 lg:grid-cols-3">
        @foreach($holidays as $holiday)
            <article class="card flex flex-col">
                @if($holiday->cover_image)
                    <img src="{{ \Illuminate\Support\Facades\Storage::url($holiday->cover_image) }}"
                         alt="{{ $holiday->title }}"
                         width="640" height="420" loading="lazy" decoding="async"
                         class="mb-4 h-44 w-full rounded-xl object-cover">
                @endif

                <h2 dir="auto" class="text-lg font-bold text-ink">
                    <a href="{{ route('packages.show', $holiday->slug) }}" class="hover:text-wine-700">
                        {{ $holiday->title }}
                    </a>
                </h2>

                @if($holiday->property?->island)
                    <p dir="auto" class="text-sm text-ink-muted">{{ $holiday->property->island }}</p>
                @endif

                <p dir="auto" class="mt-2 grow text-sm text-ink-muted">{{ $holiday->summary }}</p>

                <p dir="auto" class="mt-3 text-sm text-ink">
                    @if($holiday->isFlexible())
                        {{ __('messages.Your own dates, from :nights night(s).', ['nights' => $holiday->minimumNights()]) }}
                    @elseif($holiday->publishedDepartures->isNotEmpty())
                        {{ __('messages.:count date(s) announced', ['count' => $holiday->publishedDepartures->count()]) }}
                    @else
                        {{ __('messages.No dates announced yet — ask us.') }}
                    @endif
                </p>

                <a href="{{ route('packages.show', $holiday->slug) }}" class="btn-secondary mt-4 text-center">
                    {{ __('messages.See this holiday') }}
                </a>
            </article>
        @endforeach
    </div>

    {{-- No passports, no visas, no permits. Said out loud because it is the
         single biggest difference from everything else Rihla sells, and a
         family who has only ever seen the Umrah pages will assume
         otherwise. --}}
    <p dir="auto" class="mx-auto mt-10 max-w-2xl rounded-xl bg-cream-deep px-4 py-3 text-center text-sm text-ink">
        {{ __('messages.No passport, visa or permit — these are local islands, reached by ferry or speedboat.') }}
    </p>
</div>
@endsection
