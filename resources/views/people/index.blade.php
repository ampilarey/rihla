@extends('layouts.app')

@section('title', __('messages.Who travels with you'))

@section('content')
    <div class="container mx-auto px-4 section-y-tight">
        <header class="mx-auto mb-10 max-w-2xl text-center">
            <h1 class="section-title">{{ __('messages.Who travels with you') }}</h1>
            <p class="text-brand-body">
                {{ __('messages.A group leader travels with every party from Malé and back. On some departures a scholar travels too.') }}
            </p>
        </header>

        @if($leaders->isEmpty() && $scholars->isEmpty())
            <p class="py-12 text-center text-ink-muted">
                {{ __('messages.Nobody has been published here yet.') }}
            </p>
        @endif

        @foreach ([
            ['messages.Group leaders', $leaders],
            ['messages.Scholars', $scholars],
        ] as [$heading, $group])
            @if($group->isNotEmpty())
                <section class="mb-12">
                    <h2 class="mb-6 text-2xl font-bold text-ink">{{ __($heading) }}</h2>
                    <div class="grid gap-6 sm:grid-cols-2 lg:grid-cols-3">
                        @foreach($group as $person)
                            <x-person-card :person="$person" />
                        @endforeach
                    </div>
                </section>
            @endif
        @endforeach
    </div>
@endsection
