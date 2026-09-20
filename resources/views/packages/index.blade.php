@extends('layouts.app')

@section('title', __('messages.Umrah Packages'))

@section('content')
    <div class="container mx-auto px-4 section-y-tight">
        <header class="mx-auto mb-10 max-w-2xl text-center">
            <h1 class="section-title">{{ __('messages.Umrah Packages') }}</h1>
            <p class="text-brand-body">
                {{ __('messages.Each package runs on set dates. Prices are per person and include what is listed on the package.') }}
            </p>
        </header>

        {{--
            The finder. Every option offered comes from departures that exist
            — the months something actually departs in, budget bands spanning
            the real prices — because a dropdown offering December when
            nothing departs in December wastes the one interaction a visitor
            gives you.

            A separate GET form from the comparison one below, and it submits
            to this same page, so filtering is a URL: shareable, bookmarkable
            and readable by a crawler, with no JavaScript.
        --}}
        @if(! $options->isEmpty())
            <form method="GET" action="{{ route('packages.index') }}"
                  class="card mb-8 grid gap-4 p-5 sm:grid-cols-2 lg:grid-cols-4">
                <div>
                    <label for="finder-month" class="mb-1 block text-sm font-medium text-ink">
                        {{ __('messages.Departing in') }}
                    </label>
                    <select id="finder-month" name="month"
                            class="w-full rounded-lg border-cream-deep text-ink focus:border-wine-500 focus:ring-wine-500">
                        <option value="">{{ __('messages.Any month') }}</option>
                        @foreach($options->months as $month)
                            <option value="{{ $month['value'] }}" @selected($filters->month === $month['value'])>
                                {{ $month['label'] }}
                            </option>
                        @endforeach
                    </select>
                </div>

                @if($options->budgets->isNotEmpty())
                    <div>
                        <label for="finder-budget" class="mb-1 block text-sm font-medium text-ink">
                            {{ __('messages.Budget per person') }}
                        </label>
                        <select id="finder-budget" name="budget"
                                class="w-full rounded-lg border-cream-deep text-ink focus:border-wine-500 focus:ring-wine-500">
                            <option value="">{{ __('messages.Any budget') }}</option>
                            @foreach($options->budgets as $budget)
                                <option value="{{ $budget['value'] }}"
                                    @selected($filters->maxBudgetMinor === \App\Support\Money::ofMajor($budget['value'])->minor)>
                                    {{ $budget['label'] }}
                                </option>
                            @endforeach
                        </select>
                    </div>
                @endif

                @if($options->durations->isNotEmpty())
                    <div>
                        <label for="finder-nights" class="mb-1 block text-sm font-medium text-ink">
                            {{ __('messages.Length') }}
                        </label>
                        <select id="finder-nights" name="nights"
                                class="w-full rounded-lg border-cream-deep text-ink focus:border-wine-500 focus:ring-wine-500">
                            <option value="">{{ __('messages.Any length') }}</option>
                            @foreach($options->durations as $duration)
                                <option value="{{ $duration['value'] }}" @selected($filters->maxNights === $duration['value'])>
                                    {{ $duration['label'] }}
                                </option>
                            @endforeach
                        </select>
                    </div>
                @endif

                <div class="flex items-end gap-2">
                    <button type="submit" class="btn-primary flex-1">{{ __('messages.Find packages') }}</button>
                    @if(! $filters->isEmpty())
                        <a href="{{ route('packages.index') }}" class="btn-secondary">{{ __('messages.Clear') }}</a>
                    @endif
                </div>
            </form>
        @endif

    {{--
        A plain GET form. Ticking two boxes and pressing the button lands on
        /packages/compare?departures[]=…, with no JavaScript involved — which
        matters on a 3G phone in Malé, and means the comparison is a URL that
        can be sent to whoever is paying.
    --}}
    <form method="GET" action="{{ route('packages.compare') }}">
        @forelse($packages as $package)
            @php($next = $package->publishedDepartures->first())

            <article class="card mb-6 overflow-hidden">
                <div class="grid gap-6 p-6 md:grid-cols-3">
                    <div class="md:col-span-2">
                        <h2 class="text-xl font-bold text-ink">
                            <a href="{{ route('packages.show', $package->slug) }}"
                               class="hover:text-wine-600 focus:outline-none focus:ring-2 focus:ring-wine-500 focus:ring-offset-2 rounded">
                                <span dir="auto">{{ $package->title }}</span>
                            </a>
                        </h2>

                        @if($package->summary)
                            <p class="mt-2 text-brand-body" dir="auto">{{ $package->summary }}</p>
                        @endif

                        <dl class="mt-4 flex flex-wrap gap-x-6 gap-y-2 text-sm">
                            @if($package->nights)
                                <div>
                                    <dt class="sr-only">{{ __('Duration') }}</dt>
                                    <dd dir="auto" class="font-medium text-ink">
                                        {{ trans_choice('{1}:count night|[2,*]:count nights', $package->nights, ['count' => $package->nights]) }}
                                    </dd>
                                </div>
                            @endif

                            <div>
                                <dt class="sr-only">{{ __('Departures') }}</dt>
                                <dd dir="auto" class="text-ink-muted">
                                    @if($next)
                            <label class="mt-4 inline-flex items-center gap-2 text-sm">
                                <input type="checkbox"
                                       name="departures[]"
                                       value="{{ $next->id }}"
                                       class="h-4 w-4 rounded border-cream-deep text-wine-500 focus:ring-wine-500">
                                <span class="text-ink-muted">
                                    {{ __('messages.Compare this departure') }}
                                </span>
                            </label>
                        @endif

                        @if($package->publishedDepartures->isEmpty())
                                        {{ __('messages.No dates announced yet') }}
                                    @else
                                        {{ trans_choice(
                                            '{1}:count departure|[2,*]:count departures',
                                            $package->publishedDepartures->count(),
                                            ['count' => $package->publishedDepartures->count()],
                                        ) }}
                                    @endif
                                </dd>
                            </div>
                        </dl>

                        {{-- The hotels of the next departure, because the walk
                             to the Haram is what people compare first. --}}
                        @if($next && $next->hotels->isNotEmpty())
                            <ul class="mt-4 space-y-1.5">
                                @foreach($next->hotels as $hotel)
                                    <li><x-hotel-distance :hotel="$hotel" /></li>
                                @endforeach
                            </ul>
                        @endif
                    </div>

                    <div class="flex flex-col justify-between gap-4 border-t border-cream-deep pt-4 md:border-l md:border-t-0 md:pl-6 md:pt-0">
                        @if($next)
                            <div class="space-y-2">
                                <p class="text-sm text-ink-muted">{{ __('messages.Next departure') }}</p>
                                <p class="font-semibold text-ink" dir="auto">
                                    <x-local-date :date="$next->date_start" />
                                </p>
                                <x-departure-countdown :departure="$next" />

                                @if($next->lead_price)
                                    <p class="pt-1 text-sm text-ink-muted">{{ __('From') }}</p>
                                    <p class="text-2xl font-bold text-wine-600" dir="ltr">
                                        {{ $next->lead_price->format() }}
                                    </p>
                                @endif

                                <x-seats-bar :departure="$next" class="pt-2" />
                            </div>
                        @else
                            <p class="text-sm text-ink-muted">
                                {{ __('messages.Dates for the next run have not been announced. Message us and we will tell you first.') }}
                            </p>
                        @endif

                        <a href="{{ route('packages.show', $package->slug) }}" class="btn-primary w-full">
                            {{ __('messages.View package') }}
                        </a>
                    </div>
                </div>
            </article>
        @empty
            @if($filters->isEmpty())
                <p class="py-12 text-center text-ink-muted">{{ __('messages.No packages are published yet.') }}</p>
            @else
                <div class="card p-8 text-center">
                    <p class="mb-4 text-brand-body">
                        {{ __('messages.Nothing matches that search. Try a wider budget or a different month.') }}
                    </p>
                    <a href="{{ route('packages.index') }}" class="btn-secondary">
                        {{ __('messages.Show all packages') }}
                    </a>
                </div>
            @endif
        @endforelse

        @if($packages->isNotEmpty())
            <div class="sticky bottom-4 flex justify-center">
                <button type="submit" class="btn-primary shadow-soft">
                    {{ __('messages.Compare selected departures') }}
                </button>
            </div>
        @endif
    </form>
    </div>
@endsection
