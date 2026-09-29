@extends('layouts.app')

@section('title', $property->name)

{{--
    The share kit's first half — §15.4 (Phase 9.5). A link dropped into
    WhatsApp either unfurls into a picture of the guesthouse with its name
    under it, or it unfurls into the Rihla logo and says nothing about the
    property. These four lines are that difference.

    The card URL carries a content hash, because every scraper caches by
    URL for weeks and none re-check on any schedule worth relying on. A
    stable URL whose bytes change is the same defect `AGENTS.md` records
    for the service worker: a path that outlives its contents serves last
    year's artwork for ever.
--}}
@section('og_title', $property->name)
@section('og_description', $property->summary ?: __('messages.A guesthouse in the Maldives, booked through Rihla.'))
@section('og_image', $shareCard)
@section('twitter_title', $property->name)
@section('twitter_description', $property->summary ?: __('messages.A guesthouse in the Maldives, booked through Rihla.'))
@section('twitter_image', $shareCard)

{{--
    The share kit's other half — §15.7. The OG tags above are what a human
    sees when the link is pasted; this is what a machine reads.

    LodgingBusiness rather than Hotel, and every field below is on the page
    the reader gets. No breadcrumb list, because this page shows no
    breadcrumb trail: structured data that describes navigation the page
    does not have is a claim about the page, not a description of it.
--}}
@push('schema')
    @php($propertySchema = \App\Support\Seo::property($property, url()->current()))
    @if($propertySchema)
        <script type="application/ld+json">{!! \App\Support\Seo::json($propertySchema) !!}</script>
    @endif
@endpush

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
            <p dir="auto" class="text-ink-muted">
                {{ collect([$property->island, $property->atoll])->filter()->implode(', ') }}
                @if($property->kind)
                    <span aria-hidden="true">·</span> {{ \App\Models\Property::kindLabel($property->kind) }}
                @endif
            </p>
        </header>

        @if($property->cover_image)
            <img src="{{ \Illuminate\Support\Facades\Storage::url($property->cover_image) }}"
                 alt="{{ $property->name }}"
                 width="1200" height="630" decoding="async"
                 class="mb-6 w-full rounded-2xl object-cover">
        @endif

        {{-- The gallery — §16.7. Each thumbnail is a button, so it is
             reached and opened from the keyboard; the lightbox is a native
             <dialog>, which traps focus, closes on Escape and hands focus
             back to the thumbnail that opened it without any code here.
             The arrow keys step through the photos. --}}
        @if($photos !== [])
            @php($photoCount = count($photos))
            {{-- One image in the lightbox, its address set when a photo is
                 opened. Rendering every photo inside the closed dialog
                 either downloads them all on arrival or, lazily, never —
                 a lazy image in a closed <dialog> is not fetched when the
                 dialog opens, and the lightbox showed nothing. --}}
            <section class="mb-8" aria-labelledby="stays-photos-heading"
                     x-data="{ open: 0, photos: @js($photos), show(i) { this.open = i; this.$refs.lightbox.showModal() }, next() { this.open = (this.open + 1) % this.photos.length }, prev() { this.open = (this.open + this.photos.length - 1) % this.photos.length } }">
                <h2 id="stays-photos-heading" class="sr-only">{{ __('messages.Photos') }}</h2>

                <ul class="grid grid-cols-2 gap-3 sm:grid-cols-3">
                    @foreach($property->photos as $photo)
                        <li>
                            <button type="button" x-on:click="show({{ $loop->index }})"
                                    class="block w-full overflow-hidden rounded-xl focus:outline-none focus-visible:ring-2 focus-visible:ring-wine-500"
                                    aria-label="{{ __('messages.Open photo :number of :count', ['number' => $loop->iteration, 'count' => $photoCount]) }}">
                                <img src="{{ $photos[$loop->index]['src'] }}"
                                     @if($srcset = \App\Support\ResponsiveImage::srcset($photo->path, $photo->disk)) srcset="{{ $srcset }}" sizes="(min-width: 640px) 33vw, 50vw" @endif
                                     alt="{{ $photos[$loop->index]['alt'] }}"
                                     width="400" height="300" loading="lazy" decoding="async"
                                     class="aspect-[4/3] h-full w-full object-cover">
                            </button>
                        </li>
                    @endforeach
                </ul>

                <dialog x-ref="lightbox"
                        x-on:keydown.arrow-right.prevent="next()"
                        x-on:keydown.arrow-left.prevent="prev()"
                        x-on:click.self="$refs.lightbox.close()"
                        aria-label="{{ __('messages.Photos') }}"
                        class="w-full max-w-4xl rounded-2xl bg-ink p-0 text-white backdrop:bg-ink/80">
                    <figure class="m-0">
                        <img x-bind:src="photos[open].src" x-bind:alt="photos[open].alt" src="{{ $photos[0]['src'] }}" alt=""
                             class="max-h-[75vh] w-full object-contain">
                        <figcaption dir="auto" class="px-4 pt-3 text-sm">
                            <span x-text="photos[open].caption"></span>
                            <span class="text-cream" x-text="'· ' + (open + 1) + ' / ' + photos.length"></span>
                        </figcaption>
                    </figure>

                    <div class="flex items-center justify-between gap-3 p-4">
                        <button type="button" x-on:click="prev()" class="btn-gold">{{ __('messages.Previous') }}</button>
                        <form method="dialog"><button type="submit" class="btn-gold">{{ __('messages.Close') }}</button></form>
                        <button type="button" x-on:click="next()" class="btn-gold">{{ __('messages.Next') }}</button>
                    </div>
                </dialog>
            </section>
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

            {{-- Dates, party and who is booking, on the page itself — a
                 guest who arrived from a shared link never saw the search. --}}
            <form method="GET" action="{{ route('stays.show', ['property' => $property->slug]) }}"
                  class="card mb-6 grid gap-4 p-5 sm:grid-cols-2 lg:grid-cols-4">
                <fieldset class="sm:col-span-2 lg:col-span-4">
                    <legend class="mb-1 block text-sm font-medium text-ink">{{ __('messages.Prices for') }}</legend>
                    <div class="flex flex-wrap gap-4">
                        <label class="inline-flex items-center gap-2 text-sm text-ink">
                            <input type="radio" name="audience" value="tourist" @checked($filters->audience === 'tourist')
                                   class="border border-gray-500 text-wine-600 focus:ring-wine-500">
                            {{ __('messages.A visitor to the Maldives') }}
                        </label>
                        <label class="inline-flex items-center gap-2 text-sm text-ink">
                            <input type="radio" name="audience" value="local" @checked($filters->audience === 'local')
                                   class="border border-gray-500 text-wine-600 focus:ring-wine-500">
                            {{ __('messages.A Maldivian citizen or resident') }}
                        </label>
                    </div>
                </fieldset>

                <div>
                    <label for="show-from" class="mb-1 block text-sm font-medium text-ink">{{ __('messages.Check in') }}</label>
                    <input id="show-from" name="from" type="date" dir="ltr" value="{{ $filters->checkIn?->toDateString() }}"
                           class="w-full rounded-lg border border-gray-500 px-3 py-2 text-ink focus:border-wine-500 focus:ring-wine-500">
                </div>
                <div>
                    <label for="show-to" class="mb-1 block text-sm font-medium text-ink">{{ __('messages.Check out') }}</label>
                    <input id="show-to" name="to" type="date" dir="ltr" value="{{ $filters->checkOut?->toDateString() }}"
                           class="w-full rounded-lg border border-gray-500 px-3 py-2 text-ink focus:border-wine-500 focus:ring-wine-500">
                </div>
                <div>
                    <label for="show-guests" class="mb-1 block text-sm font-medium text-ink">{{ __('messages.Guests') }}</label>
                    <input id="show-guests" name="guests" type="number" min="1" max="30" inputmode="numeric" dir="ltr" value="{{ $filters->guests }}"
                           class="w-full rounded-lg border border-gray-500 px-3 py-2 text-ink focus:border-wine-500 focus:ring-wine-500">
                </div>
                <div class="flex items-end">
                    <button type="submit" class="btn-primary w-full">{{ __('messages.Check prices') }}</button>
                </div>
            </form>

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
                                @if(! $entry['offered'])
                                    <p dir="auto" class="rounded-full bg-cream-deep px-3 py-1 text-sm text-ink">
                                        {{ __('messages.Not offered at local prices') }}
                                    </p>
                                @elseif($entry['quote'] && $entry['available'])
                                    <p dir="auto" class="text-lg font-bold text-ink">{{ $entry['quote']->total()->format() }}</p>
                                    <p dir="auto" class="text-xs text-ink-muted">
                                        {{ __('messages.for :nights night(s)', ['nights' => $entry['quote']->nights()]) }}
                                    </p>
                                @elseif($entry['available'] === false)
                                    <p dir="auto" class="rounded-full bg-cream-deep px-3 py-1 text-sm text-ink">
                                        {{ __('messages.Not free for those dates') }}
                                    </p>
                                @elseif($entry['nightly'])
                                    <p dir="auto" class="text-lg font-bold text-ink">{{ $entry['nightly']->format() }}</p>
                                    <p dir="auto" class="text-xs text-ink-muted">{{ __('messages.a night') }}</p>
                                @else
                                    {{-- Priced by season only: a figure here would be
                                         a price most dates do not have. --}}
                                    <p dir="auto" class="text-sm text-ink-muted">{{ __('messages.Choose your dates for a price') }}</p>
                                @endif
                            </div>
                        </div>

                        @if(filled($room->description))
                            <p dir="auto" class="mt-3 text-sm text-ink-muted">{{ $room->description }}</p>
                        @endif

                        {{-- Book — §16.7. Only for a quoted, free room behind
                             a door that is on; coming_soon takes no money. --}}
                        @if($bookable && $entry['quote'] && $entry['available'] && ($filters->guests ?? 1) <= $room->sleeps)
                            <a href="{{ route('stays.book', ['property' => $property->slug, 'room' => $room->getKey(), 'from' => $filters->checkIn->toDateString(), 'to' => $filters->checkOut->toDateString(), 'adults' => $filters->guests ?? 1, 'audience' => $filters->audience]) }}"
                               class="btn-primary mt-4 w-full sm:w-auto">
                                {{ __('messages.Book this room') }}
                            </a>
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
                {{-- §15.2 decision 5. Both halves matter: *whether* Rihla
                     collects it, and *how much it is*. Saying only the
                     first is how a family of four meet twenty guest-nights
                     of tax at the check-out desk, in a currency they do
                     not hold, having read a page that mentioned it. --}}
                @if(! $greenTaxApplies)
                    {{-- Nothing said: Green Tax is not this reader's to pay. --}}
                @elseif($greenTaxAtProperty)
                    <li>
                        {{ __('messages.Green tax is paid at the guesthouse, not here.') }}
                        @if($greenTaxRate)
                            <span class="text-ink-muted">{{ __('messages.:amount per guest per night.', ['amount' => $greenTaxRate->format()]) }}</span>
                            @if($greenTaxEstimate)
                                <strong>{{ __('messages.For your dates and party: :amount.', ['amount' => $greenTaxEstimate->format()]) }}</strong>
                            @endif
                        @endif
                    </li>
                @else
                    {{-- `included` was stored and acted on nowhere, so both
                         modes printed the same page. It says so now. --}}
                    <li>{{ __('messages.Green tax is already included in this price.') }}</li>
                @endif
            </ul>
        </section>

        {{-- Where it is — §16.7. Only when somebody has placed it; the map
             loads when scrolled near, and the link works without it. --}}
        @if($property->latitude !== null && $property->longitude !== null)
            <section class="mb-10" aria-labelledby="stays-map-heading">
                <h2 id="stays-map-heading" dir="auto" class="mb-3 text-2xl font-bold text-ink">
                    {{ __('messages.Where it is') }}
                </h2>
                <div id="stay-map"
                     data-lat="{{ $property->latitude }}" data-lng="{{ $property->longitude }}" data-label="{{ $property->name }}"
                     class="h-72 w-full overflow-hidden rounded-2xl bg-cream-deep"
                     role="region" aria-label="{{ __('messages.Map') }}"></div>
                <p class="mt-2 text-sm">
                    <a href="https://www.openstreetmap.org/?mlat={{ $property->latitude }}&amp;mlon={{ $property->longitude }}#map=15/{{ $property->latitude }}/{{ $property->longitude }}"
                       target="_blank" rel="noopener" class="text-wine-700 underline hover:no-underline">
                        {{ __('messages.Open the map in a new tab') }}
                    </a>
                </p>
            </section>
        @endif

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

            {{-- The other half of the share kit. One page, in the language
                 of the URL it was asked for from, for a ferry with no
                 signal or for forwarding to whoever is actually paying.

                 Not offered in Arabic: dompdf applies no contextual
                 shaping, so an Arabic sheet prints every letter joined to
                 nothing. The reasoning is on
                 StaysController::SHEET_LOCALES. This page is the
                 shareable artefact for an Arabic reader meanwhile, and it
                 renders correctly in any browser. --}}
            @if($hasFactSheet)
            <p class="mt-4">
                <a href="{{ route('stays.sheet', ['property' => $property->slug]) }}"
                   class="text-sm text-wine-700 underline hover:no-underline">
                    {{ __('messages.Download a one-page summary (PDF)') }}
                </a>
            </p>
            @endif
        </div>
    </div>
</div>
@endsection
