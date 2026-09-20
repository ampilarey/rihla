@extends('layouts.app')

@section('content')
<!-- Hero Section -->
<x-hero-banner :banners="$heroBanners" />

<x-welcome-back :personal="$personal" />

{{--
    Three routes in, immediately under the hero: browse what is for sale,
    read about the rite, or talk to a person. The plan asks for exactly these
    three, and all three destinations exist.

    Below the hero rather than inside it: the banners are admin-written and
    carry their own calls to action, and overriding those would take the
    choice away from whoever writes them.
--}}
<section class="bg-cream-deep py-5 md:py-6">
    <div class="container mx-auto grid gap-3 px-4 sm:grid-cols-3">
        <a href="{{ route('packages.index') }}" class="btn-primary text-center">
            {{ __('messages.View packages') }}
        </a>
        <a href="{{ route('guide') }}" class="btn-secondary text-center">
            {{ __('messages.Learn about Umrah') }}
        </a>
        <a href="{{ \App\Support\Contact::whatsappUrl() }}"
           class="btn-outline text-center" target="_blank" rel="noopener noreferrer">
            {{ __('messages.Talk to an advisor') }}
        </a>
    </div>
</section>

{{--
    What is actually for sale, with seats and a countdown — the section the
    plan puts fifth and the one that does the selling. Absent entirely when
    nothing is upcoming, rather than showing an empty rail.
--}}
@if($upcomingDepartures->isNotEmpty())
    <section class="section-y bg-cream">
        <div class="container mx-auto px-4">
            <h2 class="section-title">{{ __('messages.Upcoming departures') }}</h2>

            {{-- The rail is capped to what it holds. A single card in a
                 three-column grid sits against the left edge with two empty
                 columns beside it, which reads as a page that failed to
                 load rather than as a season with one departure left.

                 Each class string is written out in full. Tailwind scans
                 source text for literal class names, so building one by
                 interpolating a count into it — md:grid-cols-{{ '{{ $n }}' }} —
                 compiles to no CSS whatsoever. That is D57 in the defect
                 register, and the same note sits in layouts/app.blade.php.
                 The first draft of this line did exactly that. --}}
            @php($rail = match ($upcomingDepartures->count()) {
                1 => 'max-w-sm',
                2 => 'max-w-3xl md:grid-cols-2',
                default => 'md:grid-cols-2 lg:grid-cols-3',
            })

            <div class="mx-auto grid gap-6 {{ $rail }}">
                @foreach($upcomingDepartures as $departure)
                    <x-departure-card :departure="$departure" />
                @endforeach
            </div>

            <div class="mt-8 text-center">
                <a href="{{ route('packages.index') }}" class="btn-secondary">
                    {{ __('messages.See all packages and dates') }}
                </a>
            </div>
        </div>
    </section>
@endif

<!-- Why Section -->
<x-section-why :why="$why" />

{{--
    What happens between deciding to go and coming home.

    Written as the real journey, not as this website's checkout: there is no
    online booking yet (Phase 3), and a timeline implying one would promise a
    button that does not exist. Every stage below is something Rihla actually
    does for a pilgrim today, including the visa and the Nusuk permit, which
    are two separate authorisations and are named separately here for the
    same reason they are two deliverables in the plan — a traveller can hold
    a valid visa and still be barred from the Rawdah without a permit.
--}}
<section class="section-y bg-white">
    <div class="container mx-auto px-4">
        <h2 class="section-title">{{ __('messages.How the journey works') }}</h2>

        <ol class="mx-auto grid max-w-5xl gap-6 sm:grid-cols-2 lg:grid-cols-3">
            @foreach ([
                ['messages.Talk to us', 'messages.Tell us who is travelling and when. We will say what is available and what it costs.'],
                ['messages.Reserve your place', 'messages.A place is held for your party while the paperwork starts.'],
                ['messages.Documents and visa', 'messages.Passports are checked for validity and the Umrah visa is applied for.'],
                ['messages.Nusuk permit', 'messages.A separate authorisation from the visa, and needed for the Mataf and the Rawdah.'],
                ['messages.Before you fly', 'messages.A briefing on the rites, what to pack, and what happens on arrival.'],
                ['messages.Madinah and Makkah', 'messages.A Dhivehi-speaking group leader travels with the party throughout.'],
            ] as $index => [$heading, $body])
                <li class="relative ps-12">
                    <span class="absolute start-0 top-0 flex h-9 w-9 items-center justify-center rounded-full bg-wine-500 font-bold text-white"
                          aria-hidden="true" dir="ltr">{{ $index + 1 }}</span>
                    <h3 class="font-bold text-ink" dir="auto">{{ __($heading) }}</h3>
                    <p class="mt-1 text-sm text-brand-body" dir="auto">{{ __($body) }}</p>
                </li>
            @endforeach
        </ol>
    </div>
</section>

<!-- Current Trip Section -->
@if($currentTrip)
<section class="section-y bg-cream">
    <div class="container mx-auto px-4">
        <h2 class="section-title">
            {{ __('Current Trip') }}
        </h2>
        <div class="max-w-4xl mx-auto">
            <div class="card">
                <div class="grid grid-cols-1 md:grid-cols-2 gap-8 items-center">
                    @if($currentTrip->cover_image)
                    <div class="relative">
                        <img src="{{ Storage::url($currentTrip->cover_image) }}" 
                             alt="{{ $currentTrip->title }}" 
                             class="w-full h-64 object-cover rounded-2xl"
         loading="lazy"
         decoding="async">
                        <div class="absolute top-4 left-4 bg-wine-500 text-white px-3 py-1 rounded-full text-sm font-medium">
                            {{ __('Now') }}
                        </div>
                    </div>
                    @endif
                    <div>
                        <h3 class="text-2xl font-bold mb-4 text-gray-800">{{ $currentTrip->title }}</h3>
                        <div class="space-y-3 mb-6">
                            <div class="flex items-center text-gray-600">
                                <svg aria-hidden="true" focusable="false" class="w-5 h-5 mr-2 text-wine-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"></path>
                                </svg>
                                <span>{{ $currentTrip->date_start->format('M d') }} - {{ $currentTrip->date_end->format('M d, Y') }}</span>
                            </div>
                            @if($currentTrip->location)
                            <div class="flex items-center text-gray-600">
                                <svg aria-hidden="true" focusable="false" class="w-5 h-5 mr-2 text-wine-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z"></path>
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 11a3 3 0 11-6 0 3 3 0 016 0z"></path>
                                </svg>
                                <span>{{ $currentTrip->location }}</span>
                            </div>
                            @endif
                            @if($currentTrip->price_from_mvr)
                            <div class="flex items-center text-gray-600">
                                <svg aria-hidden="true" focusable="false" class="w-5 h-5 mr-2 text-wine-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1"></path>
                                </svg>
                                <span>{{ __('From') }} MVR {{ number_format($currentTrip->price_from_mvr) }}</span>
                            </div>
                            @endif
                        </div>
                        <p class="text-gray-600 mb-6">{{ $currentTrip->summary }}</p>
                        <a href="{{ route('trips.show', $currentTrip->slug) }}" class="btn-primary">
                            {{ __('View Details') }}
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>
@endif

<!-- Upcoming Trip Section -->
@if($upcomingTrip)
<section class="section-y bg-cream-deep">
    <div class="container mx-auto px-4">
        <h2 class="section-title">
            {{ __('messages.section_upcoming') }}
        </h2>
        <div class="max-w-4xl mx-auto">
            <div class="card">
                <div class="grid grid-cols-1 md:grid-cols-2 gap-8 items-center">
                    <div>
                        <h3 class="text-2xl font-bold mb-4 text-gray-800">{{ $upcomingTrip->title }}</h3>
                        <div class="space-y-3 mb-6">
                            <div class="flex items-center text-gray-600">
                                <svg aria-hidden="true" focusable="false" class="w-5 h-5 mr-2 text-wine-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"></path>
                                </svg>
                                <span>{{ $upcomingTrip->date_start->format('M d') }} - {{ $upcomingTrip->date_end->format('M d, Y') }}</span>
                            </div>
                            @if($upcomingTrip->location)
                            <div class="flex items-center text-gray-600">
                                <svg aria-hidden="true" focusable="false" class="w-5 h-5 mr-2 text-wine-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z"></path>
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 11a3 3 0 11-6 0 3 3 0 016 0z"></path>
                                </svg>
                                <span>{{ $upcomingTrip->location }}</span>
                            </div>
                            @endif
                            @if($upcomingTrip->price_from_mvr)
                            <div class="flex items-center text-gray-600">
                                <svg aria-hidden="true" focusable="false" class="w-5 h-5 mr-2 text-wine-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1"></path>
                                </svg>
                                <span>{{ __('From') }} MVR {{ number_format($upcomingTrip->price_from_mvr) }}</span>
                            </div>
                            @endif
                        </div>
                        <p class="text-gray-600 mb-6">{{ $upcomingTrip->summary }}</p>
                        <a href="{{ route('trips.show', $upcomingTrip->slug) }}" class="btn-primary">
                            {{ __('View Details') }}
                        </a>
                    </div>
                    @if($upcomingTrip->cover_image)
                    <div class="relative">
                        <img src="{{ Storage::url($upcomingTrip->cover_image) }}" 
                             alt="{{ $upcomingTrip->title }}" 
                             class="w-full h-64 object-cover rounded-2xl"
         loading="lazy"
         decoding="async">
                        <div class="absolute top-4 left-4 bg-gold-500 text-ink px-3 py-1 rounded-full text-sm font-medium">
                            {{ __('Coming Soon') }}
                        </div>
                    </div>
                    @endif
                </div>
            </div>
        </div>
    </div>
</section>
@endif



<!-- Gallery Teaser Section -->
@if($recentMedia->count() > 0)
<section class="section-y bg-white">
    <div class="container mx-auto px-4">
        <div class="text-center mb-12">
            <h2 class="section-title mb-4">
                {{ __('messages.section_memories') }}
            </h2>
            <p class="text-gray-600 max-w-2xl mx-auto">
                {{ __('messages.memories_sub') }}
            </p>
        </div>
        
        <div class="grid grid-cols-2 md:grid-cols-4 gap-4 mb-8">
            @foreach($recentMedia->take(8) as $media)
            <div class="relative group overflow-hidden rounded-2xl">
                @if($media->type === 'photo')
                    <img src="{{ Storage::url($media->thumb_path ?? $media->file_path) }}" 
                         alt="{{ $media->title }}" 
                         class="w-full h-32 object-cover transition-transform duration-300 group-hover:scale-110"
                         loading="lazy"
         decoding="async">
                @else
                    <div class="w-full h-32 bg-gray-200 flex items-center justify-center">
                        <svg aria-hidden="true" focusable="false" class="w-12 h-12 text-gray-400" fill="currentColor" viewBox="0 0 24 24">
                            <path d="M8 5v14l11-7z"/>
                        </svg>
                    </div>
                @endif
                @if($media->title)
                <div class="absolute bottom-0 left-0 right-0 bg-black/50 text-white p-2 text-sm">
                    {{ $media->title }}
                </div>
                @endif
            </div>
            @endforeach
        </div>
        
        <div class="text-center">
            <a href="{{ route('gallery') }}" class="btn-primary">
                {{ __('View All Photos & Videos') }}
            </a>
        </div>
    </div>
</section>
@endif

<!-- CTA Section -->
<section class="section-y bg-wine-500 text-white">
    <div class="container mx-auto px-4 text-center">
        <h2 class="text-3xl font-bold mb-6">
            {{ __('messages.join_next_title') }}
        </h2>
        <p class="text-xl mb-8 text-gray-100 max-w-2xl mx-auto">
            {{ __('messages.contact_whatsapp') }}
        </p>
        <div class="flex flex-col sm:flex-row gap-4 justify-center">
            <a href="{{ route('contact') }}" class="btn-secondary text-lg px-8 py-4">
                {{ __('Get in Touch') }}
            </a>
            <a href="{{ \App\Support\Contact::whatsappUrl() }}" 
               target="_blank" 
               rel="noopener"
               class="btn-primary text-lg px-8 py-4">
                {{ __('messages.cta_whatsapp') }}
            </a>
        </div>
    </div>
</section>
@endsection
