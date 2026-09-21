@extends('layouts.app')

@section('title', $package->title)

@push('schema')
    @php($packageSchema = \App\Support\Seo::package($package, url()->current()))
    @if($packageSchema)
        <script type="application/ld+json">{!! \App\Support\Seo::json($packageSchema) !!}</script>
    @endif
@endpush

@section('content')
    <div class="container mx-auto px-4 section-y-tight">
        <nav class="mb-6 text-sm" aria-label="{{ __('Breadcrumb') }}">
            <a href="{{ route('packages.index') }}" class="text-wine-600 hover:underline">{{ __('Packages') }}</a>
            <span class="text-ink-muted" aria-hidden="true">/</span>
            <span class="text-ink-muted" dir="auto">{{ $package->title }}</span>
        </nav>

        <header class="mb-8">
            <h1 class="text-3xl font-bold text-ink md:text-4xl" dir="auto">{{ $package->title }}</h1>

            @if($package->summary)
                <p class="mt-3 max-w-3xl text-lg text-brand-body" dir="auto">{{ $package->summary }}</p>
            @endif
        </header>

        <div class="grid gap-8 lg:grid-cols-3">
            <div class="space-y-8 lg:col-span-2">
                @if($package->details)
                    <section>
                        <h2 class="mb-3 text-xl font-bold text-ink">{{ __('messages.About this package') }}</h2>
                        <div class="prose max-w-none text-brand-body" dir="auto">
                            {!! nl2br(e($package->details)) !!}
                        </div>
                    </section>
                @endif

                {{-- Lists, not prose. "What's included" is the most asked
                     question and burying it in a paragraph means nobody
                     reads it. --}}
                @if($package->inclusion_list || $package->exclusion_list)
                    <section class="grid gap-6 sm:grid-cols-2">
                        @if($package->inclusion_list)
                            <div>
                                <h2 class="mb-3 text-xl font-bold text-ink">{{ __('messages.What is included') }}</h2>
                                <ul class="space-y-2">
                                    @foreach($package->inclusion_list as $item)
                                        <li class="flex gap-2 text-brand-body">
                                            <svg class="mt-1 h-4 w-4 shrink-0 text-wine-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5" aria-hidden="true">
                                                <path stroke-linecap="round" stroke-linejoin="round" d="m5 13 4 4L19 7" />
                                            </svg>
                                            <span dir="auto">{{ $item }}</span>
                                        </li>
                                    @endforeach
                                </ul>
                            </div>
                        @endif

                        @if($package->exclusion_list)
                            <div>
                                <h2 class="mb-3 text-xl font-bold text-ink">{{ __('messages.What is not included') }}</h2>
                                <ul class="space-y-2">
                                    @foreach($package->exclusion_list as $item)
                                        <li class="flex gap-2 text-ink-muted">
                                            <svg class="mt-1 h-4 w-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5" aria-hidden="true">
                                                <path stroke-linecap="round" d="M6 18 18 6M6 6l12 12" />
                                            </svg>
                                            <span dir="auto">{{ $item }}</span>
                                        </li>
                                    @endforeach
                                </ul>
                            </div>
                        @endif
                    </section>
                @endif

                @if($package->accessibility_rating || $package->accessibility_note_list)
                    <section class="card p-5">
                        <h2 class="mb-2 text-lg font-bold text-ink">{{ __('messages.Walking and accessibility') }}</h2>

                        @if($package->accessibility_rating)
                            <p class="mb-2 font-medium text-ink">{{ __(ucfirst($package->accessibility_rating)) }}</p>
                        @endif

                        <ul class="space-y-1 text-brand-body">
                            @foreach($package->accessibility_note_list as $note)
                                <li dir="auto">{{ $note }}</li>
                            @endforeach
                        </ul>
                    </section>
                @endif
            </div>

            <aside class="lg:col-span-1">
                <div class="card p-5">
                    <h2 class="mb-4 text-lg font-bold text-ink">{{ __('messages.Ask about this package') }}</h2>
                    <p class="mb-4 text-sm text-brand-body">
                        {{ __('messages.Message us on WhatsApp and we will answer with dates, prices and what is left.') }}
                    </p>
                    {{-- One click, with the package already named. The plan
                         rates this highest for the effort: it matches how
                         Maldivians actually enquire, and it saves the first
                         two messages of every conversation.

                         The English title, not the translated one: the person
                         reading it at the Rihla end needs to recognise which
                         package it is. --}}
                    <a href="{{ \App\Support\Contact::whatsappUrl(
                            __('messages.Hello, I would like to ask about :package.', [
                                'package' => $package->getTranslation('title', 'en'),
                            ]),
                        ) }}"
                       class="btn-primary w-full"
                       target="_blank"
                       rel="noopener noreferrer">
                        {{ __('messages.cta_whatsapp') }}
                    </a>
                </div>
            </aside>
        </div>

        {{-- Departures ------------------------------------------------- --}}
        <section class="mt-12">
            <h2 class="mb-6 text-2xl font-bold text-ink">{{ __('Departures') }}</h2>

            @forelse($package->publishedDepartures as $departure)
                <article class="card mb-6 p-6">
                    <div class="grid gap-6 md:grid-cols-3">
                        <div class="md:col-span-2 space-y-4">
                            <div class="flex flex-wrap items-baseline gap-x-4 gap-y-2">
                                <h3 class="text-lg font-bold text-ink" dir="auto">
                                    <x-local-date :date="$departure->date_start" />
                                    <span class="text-ink-muted" aria-hidden="true">–</span>
                                    <x-local-date :date="$departure->date_end" />
                                </h3>
                                <x-departure-countdown :departure="$departure" />
                            </div>

                            <dl class="flex flex-wrap gap-x-6 gap-y-2 text-sm">
                                <div>
                                    <dt class="sr-only">{{ __('Duration') }}</dt>
                                    <dd dir="auto" class="font-medium text-ink">
                                        {{ trans_choice('{1}:count night|[2,*]:count nights', $departure->nights, ['count' => $departure->nights]) }}
                                    </dd>
                                </div>
                                @if($departure->airline)
                                    <div>
                                        <dt class="sr-only">{{ __('Airline') }}</dt>
                                        <dd dir="auto" class="text-ink-muted">{{ $departure->airline }}</dd>
                                    </div>
                                @endif
                            </dl>

                            {{-- Who travels with this party. The plan's
                                 reason: pilgrims choose people, not
                                 packages. Shown only when somebody has been
                                 named. --}}
                            @if($departure->tourLeader || $departure->scholar)
                                <div>
                                    <h4 class="mb-2 text-sm font-semibold uppercase tracking-wide text-ink-muted">
                                        {{ __('messages.Travelling with you') }}
                                    </h4>
                                    <ul class="space-y-1">
                                        @foreach([$departure->tourLeader, $departure->scholar] as $person)
                                            @if($person)
                                                <li class="text-sm" dir="auto">
                                                    <span class="font-medium text-ink">{{ $person->name }}</span>
                                                    <span class="text-ink-muted">— {{ $person->title ?: $person->role_label }}</span>
                                                </li>
                                            @endif
                                        @endforeach
                                    </ul>
                                    <a href="{{ route('people.index') }}"
                                       class="mt-1 inline-block text-sm text-wine-600 hover:underline">
                                        {{ __('messages.Meet the people who travel with you') }}
                                    </a>
                                </div>
                            @endif

                            @if($departure->hotels->isNotEmpty())
                                <div>
                                    <h4 class="mb-2 text-sm font-semibold uppercase tracking-wide text-ink-muted">{{ __('Hotels') }}</h4>
                                    <ul class="space-y-1.5">
                                        @foreach($departure->hotels as $hotel)
                                            <li><x-hotel-distance :hotel="$hotel" /></li>
                                        @endforeach
                                    </ul>
                                </div>
                            @endif

                            @if($departure->itinerary->isNotEmpty())
                                <details class="group">
                                    <summary class="cursor-pointer text-sm font-semibold text-wine-600 hover:underline">
                                        {{ __('messages.Day by day') }}
                                    </summary>
                                    <ol class="mt-3 space-y-3 border-s-2 border-cream-deep ps-4">
                                        @foreach($departure->itinerary as $day)
                                            <li>
                                                <p class="text-sm font-semibold text-ink">
                                                    {{ __('messages.Day :number', ['number' => $day->day_number]) }}
                                                    <span class="font-normal" dir="auto">— {{ $day->title }}</span>
                                                </p>
                                                @if($day->description)
                                                    <p class="text-sm text-brand-body" dir="auto">{{ $day->description }}</p>
                                                @endif
                                            </li>
                                        @endforeach
                                    </ol>
                                </details>
                            @endif
                        </div>

                        <div class="space-y-4 border-t border-cream-deep pt-4 md:border-s md:border-t-0 md:ps-6 md:pt-0">
                            @if($departure->priceTiers->isNotEmpty())
                                <div>
                                    <h4 class="mb-2 text-sm font-semibold uppercase tracking-wide text-ink-muted">{{ __('messages.Price per person') }}</h4>
                                    <dl class="space-y-1">
                                        @foreach($departure->priceTiers as $tier)
                                            <div class="flex items-baseline justify-between gap-4">
                                                <dt dir="auto" class="text-sm text-brand-body">
                                                    {{ __(ucfirst($tier->occupancy)) }}
                                                    @if($tier->pax_type !== 'adult')
                                                        <span class="text-ink-muted">({{ __($tier->pax_type) }})</span>
                                                    @endif
                                                </dt>
                                                <dd class="font-semibold text-ink" dir="ltr">{{ $tier->formatted }}</dd>
                                            </div>
                                        @endforeach
                                    </dl>
                                </div>
                            @endif

                            <x-seats-bar :departure="$departure" />

                            {{-- The booking flow proper. Offered only when
                                 seats can actually be sold: a departure with
                                 no capacity recorded is not "unlimited", it is
                                 unconfigured, and a button that throws on the
                                 next page is worse than no button. --}}
                            @if($departure->is_bookable)
                                <a href="{{ route('booking.start', ['slug' => $package->slug, 'departure' => $departure->id]) }}"
                                   class="btn-action w-full">
                                    {{ __('messages.Book now') }}
                                </a>
                            @elseif($departure->is_sold_out && $departure->date_start->isFuture())
                                {{-- A sold-out departure was a dead end: the page
                                     said "Fully booked" and the visitor left. Seats
                                     do come back — a hold lapses, a passport turns
                                     out to be expired, a family cancels — and
                                     nobody was told. --}}
                                <details class="rounded-xl border border-cream-deep p-4">
                                    <summary dir="auto" class="cursor-pointer font-medium text-wine-600">
                                        {{ __('messages.Join the waiting list') }}
                                    </summary>

                                    <form method="POST" action="{{ route('waitlist.join', $package->slug) }}"
                                          class="mt-4 grid gap-3 sm:grid-cols-2">
                                        @csrf
                                        <input type="hidden" name="departure" value="{{ $departure->id }}">

                                        <div class="sm:col-span-2">
                                            <label dir="auto" for="wl-name-{{ $departure->id }}" class="mb-1 block text-sm font-medium text-ink">
                                                {{ __('messages.Full name') }}
                                            </label>
                                            <input id="wl-name-{{ $departure->id }}" name="name" type="text" required dir="auto"
                                                   class="w-full rounded-lg border-cream-deep text-ink focus:border-wine-500 focus:ring-wine-500">
                                        </div>

                                        <div>
                                            <label dir="auto" for="wl-phone-{{ $departure->id }}" class="mb-1 block text-sm font-medium text-ink">
                                                {{ __('messages.Phone') }}
                                            </label>
                                            <input id="wl-phone-{{ $departure->id }}" name="phone" type="tel" required dir="ltr"
                                                   class="w-full rounded-lg border-cream-deep text-ink focus:border-wine-500 focus:ring-wine-500">
                                        </div>

                                        <div>
                                            <label dir="auto" for="wl-seats-{{ $departure->id }}" class="mb-1 block text-sm font-medium text-ink">
                                                {{ __('messages.How many travellers?') }}
                                            </label>
                                            <input id="wl-seats-{{ $departure->id }}" name="seats" type="number" inputmode="numeric"
                                                   min="1" max="{{ config('booking.party.max') }}" value="1" dir="ltr"
                                                   class="w-full rounded-lg border-cream-deep text-ink focus:border-wine-500 focus:ring-wine-500">
                                        </div>

                                        <div class="sm:col-span-2">
                                            <label dir="auto" for="wl-email-{{ $departure->id }}" class="mb-1 block text-sm font-medium text-ink">
                                                {{ __('messages.Email (optional)') }}
                                            </label>
                                            <input id="wl-email-{{ $departure->id }}" name="email" type="email" dir="ltr"
                                                   class="w-full rounded-lg border-cream-deep text-ink focus:border-wine-500 focus:ring-wine-500">
                                        </div>

                                        <button type="submit" class="btn-secondary sm:col-span-2">
                                            {{ __('messages.Add me to the list') }}
                                        </button>

                                        <p dir="auto" class="text-xs text-ink-muted sm:col-span-2">
                                            {{ __('messages.We message you if a seat comes back. It costs nothing and commits you to nothing.') }}
                                        </p>
                                    </form>
                                </details>
                            @endif

                            <x-cost-calculator :departure="$departure" />
                        </div>
                    </div>
                </article>
            @empty
                <p class="card p-6 text-center text-ink-muted">
                    {{ __('messages.No dates are announced for this package yet. Message us and we will tell you first.') }}
                </p>
            @endforelse
        </section>
    </div>
@endsection
