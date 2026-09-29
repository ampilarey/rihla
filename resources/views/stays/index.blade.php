@extends('layouts.app')

@section('title', __('messages.Stays'))

{{--
    The Stays hub and search — §15.4 (Phase 9.4), §16.7 (Phase 13.1).

    The strand cards are still the three doors, read from the service
    registry so the page cannot advertise something the switch has turned
    away. Below them, every listing behind a door that is not off.

    The search is a GET form submitting to this same page, so a search is
    a URL — shareable, bookmarkable, crawlable, working with no JavaScript,
    as the strand pages and the package finder already are.
--}}
@section('content')
<div class="container mx-auto px-4 section-y-tight">
    <header class="mx-auto mb-10 max-w-2xl text-center">
        <h1 dir="auto" class="section-title">{{ __('messages.Stays') }}</h1>
        <p dir="auto" class="text-brand-body">
            {{ __('messages.Guesthouses on the islands, short holidays for Maldivian families, and rooms in Malé — arranged by the same people who run our Umrah groups.') }}
        </p>
    </header>

    <div class="mx-auto mb-12 grid max-w-4xl gap-6 md:grid-cols-3">
        @foreach($strands as $key => $meta)
            <a href="{{ route($meta['route']) }}"
               class="card group flex flex-col gap-2 text-start transition hover:shadow-lg">
                <h2 dir="auto" class="text-xl font-bold text-ink group-hover:text-wine-700">
                    {{ __($meta['label']) }}
                </h2>

                @if(\App\Support\Services::isComingSoon($key))
                    <span dir="auto" class="inline-flex w-fit rounded-full bg-cream-deep px-3 py-1 text-xs font-medium text-ink">
                        {{ __('messages.Coming soon') }}
                    </span>
                @endif

                <p dir="auto" class="text-sm text-ink-muted">
                    @switch($key)
                        @case('stays_guesthouses')
                            {{ __('messages.Hand-picked guesthouses across the islands, booked through us.') }}
                            @break
                        @case('stays_island_holidays')
                            {{ __('messages.A weekend away on a local island, planned end to end.') }}
                            @break
                        @default
                            {{ __('messages.Nightly rooms in Malé, booked and paid for online.') }}
                    @endswitch
                </p>
            </a>
        @endforeach
    </div>

    @if($hasListings)
        <section aria-labelledby="stays-search-heading">
            <div class="mb-4 flex flex-wrap items-baseline justify-between gap-2">
                <h2 id="stays-search-heading" dir="auto" class="text-2xl font-bold text-ink">
                    {{ __('messages.Find a place to stay') }}
                </h2>
                <p dir="auto" class="text-sm">
                    <a href="{{ route('stays.atolls') }}" class="text-wine-700 underline hover:no-underline">{{ __('messages.Stays by atoll') }}</a>
                    <span aria-hidden="true" class="text-ink-muted">·</span>
                    <a href="{{ route('stays.hosts') }}" class="text-wine-700 underline hover:no-underline">{{ __('messages.Our hosts') }}</a>
                </p>
            </div>

            <form method="GET" action="{{ route('stays.index') }}" class="card mb-8 grid gap-4 p-5 sm:grid-cols-2 lg:grid-cols-4">
                {{-- Who is booking decides which price is shown — §16.7. A
                     visitor who says nothing gets the guess their language
                     implies, and can change it here. --}}
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
                    <label for="search-island" class="mb-1 block text-sm font-medium text-ink">{{ __('messages.Island') }}</label>
                    <select id="search-island" name="island"
                            class="w-full rounded-lg border border-gray-500 px-3 py-2 text-ink focus:border-wine-500 focus:ring-wine-500">
                        <option value="">{{ __('messages.Any island') }}</option>
                        @foreach($islands as $island)
                            <option value="{{ $island }}" @selected($filters->island === $island)>{{ $island }}</option>
                        @endforeach
                    </select>
                </div>

                @if($atolls->isNotEmpty())
                    <div>
                        <label for="search-atoll" class="mb-1 block text-sm font-medium text-ink">{{ __('messages.Atoll') }}</label>
                        <select id="search-atoll" name="atoll"
                                class="w-full rounded-lg border border-gray-500 px-3 py-2 text-ink focus:border-wine-500 focus:ring-wine-500">
                            <option value="">{{ __('messages.Any atoll') }}</option>
                            @foreach($atolls as $atoll)
                                <option value="{{ $atoll }}" @selected($filters->atoll === $atoll)>{{ $atoll }}</option>
                            @endforeach
                        </select>
                    </div>
                @endif

                <div>
                    <label for="search-from" class="mb-1 block text-sm font-medium text-ink">{{ __('messages.Check in') }}</label>
                    <input id="search-from" name="from" type="date" dir="ltr"
                           value="{{ $filters->checkIn?->toDateString() }}"
                           class="w-full rounded-lg border border-gray-500 px-3 py-2 text-ink focus:border-wine-500 focus:ring-wine-500">
                </div>

                <div>
                    <label for="search-to" class="mb-1 block text-sm font-medium text-ink">{{ __('messages.Check out') }}</label>
                    <input id="search-to" name="to" type="date" dir="ltr"
                           value="{{ $filters->checkOut?->toDateString() }}"
                           class="w-full rounded-lg border border-gray-500 px-3 py-2 text-ink focus:border-wine-500 focus:ring-wine-500">
                </div>

                <div>
                    <label for="search-guests" class="mb-1 block text-sm font-medium text-ink">{{ __('messages.Guests') }}</label>
                    <input id="search-guests" name="guests" type="number" min="1" max="30" inputmode="numeric" dir="ltr"
                           value="{{ $filters->guests }}"
                           class="w-full rounded-lg border border-gray-500 px-3 py-2 text-ink focus:border-wine-500 focus:ring-wine-500">
                </div>

                <div>
                    <label for="search-price-min" class="mb-1 block text-sm font-medium text-ink">
                        {{ __('messages.Lowest price a night (:currency)', ['currency' => $priceCurrency]) }}
                    </label>
                    <input id="search-price-min" name="price_min" type="number" min="0" inputmode="numeric" dir="ltr"
                           value="{{ $filters->priceMin }}"
                           class="w-full rounded-lg border border-gray-500 px-3 py-2 text-ink focus:border-wine-500 focus:ring-wine-500">
                </div>

                <div>
                    <label for="search-price-max" class="mb-1 block text-sm font-medium text-ink">
                        {{ __('messages.Highest price a night (:currency)', ['currency' => $priceCurrency]) }}
                    </label>
                    <input id="search-price-max" name="price_max" type="number" min="0" inputmode="numeric" dir="ltr"
                           value="{{ $filters->priceMax }}"
                           class="w-full rounded-lg border border-gray-500 px-3 py-2 text-ink focus:border-wine-500 focus:ring-wine-500">
                </div>

                <div>
                    <label for="search-sort" class="mb-1 block text-sm font-medium text-ink">{{ __('messages.Sort by') }}</label>
                    <select id="search-sort" name="sort"
                            class="w-full rounded-lg border border-gray-500 px-3 py-2 text-ink focus:border-wine-500 focus:ring-wine-500">
                        <option value="recommended" @selected($filters->sort === 'recommended')>{{ __('messages.Recommended') }}</option>
                        <option value="price" @selected($filters->sort === 'price')>{{ __('messages.Lowest price') }}</option>
                        <option value="newest" @selected($filters->sort === 'newest')>{{ __('messages.Newest') }}</option>
                        <option value="rating" @selected($filters->sort === 'rating')>{{ __('messages.Best rated') }}</option>
                    </select>
                </div>

                @if($kinds->count() > 1)
                    <fieldset class="sm:col-span-2 lg:col-span-4">
                        <legend class="mb-1 block text-sm font-medium text-ink">{{ __('messages.Type of place') }}</legend>
                        <div class="flex flex-wrap gap-4">
                            @foreach($kinds as $kind)
                                <label class="inline-flex items-center gap-2 text-sm text-ink">
                                    <input type="checkbox" name="kind[]" value="{{ $kind }}" @checked(in_array($kind, $filters->kinds, true))
                                           class="rounded border border-gray-500 text-wine-600 focus:ring-wine-500">
                                    {{ \App\Models\Property::kindLabel($kind) }}
                                </label>
                            @endforeach
                        </div>
                    </fieldset>
                @endif

                <div class="flex items-end sm:col-span-2 lg:col-span-4">
                    <button type="submit" class="btn-primary w-full sm:w-auto">{{ __('messages.Search') }}</button>
                </div>
            </form>

            @if($results->isEmpty())
                <div class="card mx-auto max-w-xl text-center">
                    <h3 dir="auto" class="mb-2 text-lg font-bold text-ink">{{ __('messages.Nothing matches that search') }}</h3>
                    <p dir="auto" class="mb-4 text-sm text-ink-muted">
                        {{ __('messages.Try different dates, or ask us — we often have something that is not on the site yet.') }}
                    </p>
                    <a href="{{ route('contact') }}" class="btn-secondary">{{ __('messages.Ask us') }}</a>
                </div>
            @else
                <p dir="auto" class="mb-4 text-sm text-ink-muted" role="status">
                    {{ trans_choice('messages.:count place to stay|:count places to stay', $results->total(), ['count' => $results->total()]) }}
                </p>

                <div class="grid gap-6 md:grid-cols-2 lg:grid-cols-3">
                    @foreach($results as $property)
                        @php($link = route('stays.show', ['property' => $property->slug] + $filters->toQuery()))
                        <article class="card flex flex-col overflow-hidden">
                            @if($property->cover_image)
                                <img src="{{ \Illuminate\Support\Facades\Storage::url($property->cover_image) }}"
                                     alt="{{ $property->name }}"
                                     width="640" height="420" loading="lazy" decoding="async"
                                     class="mb-4 h-44 w-full rounded-xl object-cover">
                            @endif

                            <h3 dir="auto" class="text-lg font-bold text-ink">
                                <a href="{{ $link }}" class="hover:text-wine-700">{{ $property->name }}</a>
                            </h3>

                            <p dir="auto" class="text-sm text-ink-muted">
                                {{ collect([$property->island, $property->atoll])->filter()->implode(', ') }}
                                @if($property->kind)
                                    <span aria-hidden="true">·</span> {{ \App\Models\Property::kindLabel($property->kind) }}
                                @endif
                            </p>

                            <p dir="auto" class="mt-2 grow text-sm text-ink-muted">{{ $property->summary }}</p>

                            @if($stars = $property->rating())
                                <p dir="auto" class="mt-2 text-sm text-ink">
                                    <span aria-hidden="true" class="text-gold-600">★</span>
                                    {{ number_format($stars['average'], 1) }} · {{ trans_choice('messages.:count review|:count reviews', $stars['count'], ['count' => $stars['count']]) }}
                                </p>
                            @endif

                            @php($from = $property->cheapestRateFor($filters->audience))
                            @if($from)
                                <p dir="auto" class="mt-4 text-sm font-medium text-ink">
                                    {{ __('messages.From :price a night', ['price' => $from->format()]) }}
                                </p>
                            @endif

                            @if($property->partner)
                                <p dir="auto" class="mt-1 text-xs text-ink-muted">
                                    @if($hostPage = $property->hostPageUrl())
                                        {!! __('messages.Hosted by :host', ['host' => '<a href="'.e($hostPage).'" class="underline hover:no-underline">'.e($property->partner->name).'</a>']) !!}
                                    @else
                                        {{ __('messages.Hosted by :host', ['host' => $property->partner->name]) }}
                                    @endif
                                </p>
                            @endif

                            <a href="{{ $link }}" class="btn-secondary mt-4 text-center">
                                {{ __('messages.See the rooms') }}
                            </a>
                        </article>
                    @endforeach
                </div>

                <div class="mt-8">
                    {{ $results->links() }}
                </div>
            @endif
        </section>
    @endif
</div>
@endsection
