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
                                    {{ $next->date_start->translatedFormat('j M Y') }}
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
            <p class="py-12 text-center text-ink-muted">{{ __('messages.No packages are published yet.') }}</p>
        @endforelse
    </div>
@endsection
