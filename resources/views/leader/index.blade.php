@extends('layouts.app')

@section('title', 'Your groups')

@section('content')
    <div class="container mx-auto max-w-3xl px-4 section-y-tight">
        <h1 class="mb-6 text-2xl font-bold text-ink">Your groups</h1>

        @if ($unlinked)
            {{-- A missing link means less access, never more: this account
                 is a tour leader with no profile, so it is shown nothing
                 rather than everything. Saying so is the difference between
                 a fixable problem and a leader who thinks the app is
                 broken. --}}
            <div class="card border-s-4 border-s-wine">
                <h2 class="mb-2 text-lg font-bold text-ink">Nobody has linked your account yet</h2>
                <p class="text-ink-muted">
                    Your staff login is not yet joined to your profile, so this page cannot tell
                    which groups are yours. Ask the office to link them — it takes a moment, and
                    until then this page stays empty on purpose.
                </p>
            </div>
        @elseif ($departures->isEmpty())
            <div class="card">
                <h2 class="mb-2 text-lg font-bold text-ink">Nothing on the ground</h2>
                <p class="text-ink-muted">
                    You have no group travelling now or in the next month. A trip that has come
                    home is not shown here.
                </p>
            </div>
        @else
            <ul class="space-y-4">
                @foreach ($departures as $departure)
                    <li>
                        <a href="{{ route('leader.departure', $departure) }}" class="card block hover:border-wine">
                            <h2 dir="auto" class="text-lg font-bold text-ink">
                                {{ $departure->package->title ?? 'Departure' }}
                            </h2>
                            <p class="text-ink-muted" dir="ltr">
                                <x-local-date :date="$departure->date_start" />
                                &ndash;
                                <x-local-date :date="$departure->date_end" />
                            </p>
                        </a>
                    </li>
                @endforeach
            </ul>
        @endif
    </div>
@endsection
