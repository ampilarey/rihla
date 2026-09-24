@extends('layouts.app')

@section('title', __('messages.Stays'))

{{--
    The Stays hub — §15.4 (Phase 9.4). One card per strand that is not off,
    read from the service registry so the page cannot advertise something
    the switch has turned away.
--}}
@section('content')
<div class="container mx-auto px-4 section-y-tight">
    <header class="mx-auto mb-10 max-w-2xl text-center">
        <h1 dir="auto" class="section-title">{{ __('messages.Stays') }}</h1>
        <p dir="auto" class="text-brand-body">
            {{ __('messages.Guesthouses on the islands, short holidays for Maldivian families, and rooms in Malé — arranged by the same people who run our Umrah groups.') }}
        </p>
    </header>

    <div class="mx-auto grid max-w-4xl gap-6 md:grid-cols-3">
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
</div>
@endsection
