@extends('layouts.app')

@section('title', $rollCall->moment)

@section('content')
    <div
        class="container mx-auto max-w-3xl px-4 section-y-tight"
        data-leader-count="{{ $rollCall->getKey() }}"
        data-leader-departure="{{ $departure->getKey() }}"
        data-sync-url="{{ route('leader.sync') }}"
    >
        <p class="mb-2">
            <a href="{{ route('leader.departure', $departure) }}" class="text-ink-muted underline">
                Back to the group
            </a>
        </p>

        <h1 class="mb-1 text-2xl font-bold text-ink">{{ $rollCall->moment }}</h1>

        <p class="mb-6 text-ink-muted" dir="ltr">{{ $rollCall->taken_at->format('j M Y, H:i') }}</p>

        <x-leader-offline-banner />

        {{-- Said on the screen, not only in the code: a leader who thinks an
             unmarked name is fine will stop looking for them. --}}
        <p class="mb-6 text-ink-muted">
            Anybody you have not tapped is <strong class="text-ink">not counted as here</strong>.
            Tap every name.
        </p>

        <ul class="space-y-3">
            @foreach ($travellers as $traveller)
                @php($mark = $marks[$traveller->getKey()] ?? null)
                <li
                    class="card"
                    data-leader-traveller="{{ $traveller->getKey() }}"
                    data-leader-state="{{ $mark->state ?? '' }}"
                >
                    <p class="mb-3 font-medium text-ink">{{ $traveller->full_name }}</p>

                    <div class="flex flex-wrap gap-2">
                        @foreach ($states as $state)
                            <button
                                type="button"
                                data-leader-mark="{{ $state }}"
                                class="min-h-11 flex-1 rounded-xl border-2 px-4 py-2 text-sm font-medium
                                    {{ ($mark->state ?? null) === $state
                                        ? 'border-wine bg-cream text-ink'
                                        : 'border-cream-deep text-ink-muted' }}"
                            >
                                @switch($state)
                                    @case('present') Here @break
                                    @case('absent') Not here @break
                                    @default Excused
                                @endswitch
                            </button>
                        @endforeach
                    </div>

                    <p class="mt-2 text-sm text-ink-muted" data-leader-mark-status>
                        @if ($mark === null)
                            Not counted
                        @else
                            {{ $mark->stateLabel() }}
                        @endif
                    </p>
                </li>
            @endforeach
        </ul>
    </div>
@endsection

@push('scripts')
    <script nonce="@cspNonce">window.rihlaCsrfToken = '{{ csrf_token() }}';</script>
@endpush
