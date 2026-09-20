@extends('layouts.app')

@section('title', $departure->package->title ?? 'Your group')

@section('content')
    <div
        class="container mx-auto max-w-3xl px-4 section-y-tight"
        data-leader-departure="{{ $departure->getKey() }}"
        data-snapshot-url="{{ route('leader.snapshot', $departure) }}"
    >
        <p class="mb-2">
            <a href="{{ route('leader.index') }}" class="text-ink-muted underline">All your groups</a>
        </p>

        <h1 dir="auto" class="mb-1 text-2xl font-bold text-ink">
            {{ $departure->package->title ?? 'Departure' }}
        </h1>

        <p class="mb-6 text-ink-muted" dir="ltr">
            <x-local-date :date="$departure->date_start" />
            &ndash;
            <x-local-date :date="$departure->date_end" />
        </p>

        <x-leader-offline-banner />

        @if ($openIncidents->isNotEmpty())
            <section class="card mb-6 border-s-4 border-s-wine">
                <h2 class="mb-3 text-lg font-bold text-ink">
                    {{ trans_choice('{1}:count open incident|[2,*]:count open incidents', $openIncidents->count(), ['count' => $openIncidents->count()]) }}
                </h2>

                <ul class="space-y-2">
                    @foreach ($openIncidents as $incident)
                        <li class="border-b border-cream-deep pb-2 last:border-0">
                            <p class="font-medium text-ink">{{ $incident->summary }}</p>
                            <p class="text-sm text-ink-muted">
                                {{ $incident->severityLabel() }} · {{ $incident->categoryLabel() }}
                            </p>
                        </li>
                    @endforeach
                </ul>
            </section>
        @endif

        <section class="card mb-6">
            <h2 class="mb-3 text-lg font-bold text-ink">Head counts</h2>

            @if ($rollCalls->isEmpty())
                <p class="text-ink-muted">
                    None yet. A count belongs to a moment where somebody could be left behind —
                    the office starts one and it appears here.
                </p>
            @else
                <ul class="space-y-2">
                    @foreach ($rollCalls as $rollCall)
                        @php($missing = $rollCall->unaccountedFor()->count())
                        <li>
                            <a
                                href="{{ route('leader.count', [$departure, $rollCall]) }}"
                                class="flex items-center justify-between gap-4 border-b border-cream-deep py-2 last:border-0"
                            >
                                <span>
                                    <span class="font-medium text-ink">{{ $rollCall->moment }}</span>
                                    <span class="block text-sm text-ink-muted" dir="ltr">
                                        {{ $rollCall->taken_at->format('j M, H:i') }}
                                    </span>
                                </span>

                                @if ($missing === 0)
                                    <span class="whitespace-nowrap rounded-full bg-cream px-3 py-1 text-sm font-medium text-ink">
                                        All here
                                    </span>
                                @else
                                    <span class="whitespace-nowrap rounded-full bg-wine px-3 py-1 text-sm font-medium text-white">
                                        {{ $missing }} missing
                                    </span>
                                @endif
                            </a>
                        </li>
                    @endforeach
                </ul>
            @endif
        </section>

        <section class="card">
            <h2 class="mb-3 text-lg font-bold text-ink">
                Who is on this trip ({{ $travellers->count() }})
            </h2>

            <ul class="space-y-2" data-leader-roster>
                @foreach ($travellers as $traveller)
                    <li class="border-b border-cream-deep pb-2 last:border-0">
                        <span class="font-medium text-ink">{{ $traveller->full_name }}</span>

                        @php($rooms = $departure->hotels->flatMap(fn ($hotel) => $hotel->rooms
                            ->filter(fn ($room) => $room->assignments->contains('traveller_id', $traveller->getKey()))
                            ->map(fn ($room) => $hotel->cityLabel().' '.$room->label)))

                        @if ($rooms->isNotEmpty())
                            <span class="block text-sm text-ink-muted">{{ $rooms->implode(' · ') }}</span>
                        @else
                            <span class="block text-sm text-ink-muted">No room yet</span>
                        @endif
                    </li>
                @endforeach
            </ul>
        </section>
    </div>
@endsection
