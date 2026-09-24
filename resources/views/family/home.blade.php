@extends('layouts.app')

@section('title', 'Following the journey')

@section('content')
    <div class="container mx-auto max-w-2xl px-4 section-y-tight">

        <h1 dir="auto" class="mb-1 text-2xl font-bold text-ink">
            {{ $departure->package->title ?? 'The journey' }}
        </h1>

        <p class="mb-6 text-ink-muted" dir="ltr">
            <x-local-date :date="$departure->date_start" />
            &ndash;
            <x-local-date :date="$departure->date_end" />
        </p>

        <x-emergency-notice :broadcasts="$broadcasts" />

        {{-- Where the group is. Derived from the recorded hotel nights, and
             honest about not knowing — see App\Support\JourneyProgress. --}}
        <section class="card mb-6">
            <h2 class="mb-2 text-xl font-bold text-ink">{{ $progress['headline'] }}</h2>
            <p class="text-ink-muted">{{ $progress['detail'] }}</p>
        </section>

        @if ($flights->isNotEmpty())
            {{-- When they fly, and when to be at the airport to meet them.
                 The same times the pilgrim sees, and never the booking
                 reference. --}}
            <section class="card mb-6">
                <h2 class="mb-1 text-lg font-bold text-ink">Flights</h2>
                <p class="mb-4 text-sm text-ink-muted">Times are local at each airport.</p>
                <ul class="divide-y divide-cream-deep">
                    @foreach ($flights as $flight)
                        <li class="py-3">
                            <span class="block text-sm text-ink-muted">{{ \App\Models\DepartureFlight::directionLabel($flight->direction) }}</span>
                            <span dir="ltr" class="block font-medium text-ink">{{ $flight->airline }} {{ $flight->flight_number }} &middot; {{ $flight->from_airport }} → {{ $flight->to_airport }}</span>
                            <span class="block text-sm text-ink" dir="ltr">
                                Departs {{ $flight->departs_at->format('D j M, H:i') }}@if ($flight->arrives_at) &middot; arrives {{ $flight->arrives_at->format('D j M, H:i') }}@endif
                            </span>
                        </li>
                    @endforeach
                </ul>
            </section>
        @endif

        @if ($accountedFor->isNotEmpty())
            {{-- Only reached when the pilgrim turned sharing on. The
                 controller returns an empty collection otherwise, so this
                 page cannot leak it by forgetting a check. --}}
            <section class="card mb-6">
                <h2 class="mb-3 text-lg font-bold text-ink">At the last head count</h2>

                <ul class="space-y-2">
                    @foreach ($accountedFor as $person)
                        <li class="flex items-center justify-between gap-4 border-b border-cream-deep pb-2 last:border-0">
                            <span class="font-medium text-ink">{{ $person['name'] }}</span>

                            @if ($person['accounted'])
                                <span class="whitespace-nowrap rounded-full bg-cream px-3 py-1 text-sm text-ink">
                                    Accounted for
                                </span>
                            @else
                                <span class="whitespace-nowrap rounded-full bg-wine px-3 py-1 text-sm text-white">
                                    Not yet counted
                                </span>
                            @endif
                        </li>
                    @endforeach
                </ul>

                <p class="mt-3 text-sm text-ink-muted">
                    This says whether the group leader has counted them, and nothing about where
                    they are.
                </p>
            </section>
        @endif

        <section class="card mb-6">
            <h2 class="mb-3 text-lg font-bold text-ink">News from the group</h2>

            @if ($announcements->isEmpty())
                <p class="text-ink-muted">
                    Nothing has been posted yet. Anything the office or the group leader announces
                    will appear here.
                </p>
            @else
                <ul class="space-y-4">
                    @foreach ($announcements as $announcement)
                        <li class="border-b border-cream-deep pb-4 last:border-0 last:pb-0">
                            <p class="font-medium text-ink">{{ $announcement->headline }}</p>

                            @if ($announcement->body)
                                <p class="mt-1 text-ink-muted">{{ $announcement->body }}</p>
                            @endif

                            <p class="mt-1 text-sm text-ink-muted" dir="ltr">
                                {{ $announcement->published_at->format('j M Y, H:i') }}
                            </p>
                        </li>
                    @endforeach
                </ul>
            @endif
        </section>

        {{-- Said plainly, because a family who thinks they are being shown
             everything will worry when they are not. --}}
        <section class="card mb-6">
            <h2 class="mb-2 text-lg font-bold text-ink">What this page does not show</h2>
            <p class="text-ink-muted">
                Nothing about money, documents or anybody else on the trip,
                and no location for anyone.
                The person travelling chooses what appears here and can turn this link off
                whenever they want.
            </p>
        </section>

        <section class="card">
            <h2 class="mb-2 text-lg font-bold text-ink">If something is wrong</h2>
            <p class="mb-3 text-ink-muted">Call or message the office.</p>
            <p>
                <a href="{{ \App\Support\Contact::telUrl() }}" class="font-medium text-wine underline" dir="ltr">
                    {{ \App\Support\Contact::displayNumber() }}
                </a>
            </p>
        </section>
    </div>
@endsection
