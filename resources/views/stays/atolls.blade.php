@extends('layouts.app')

@section('title', __('messages.Stays by atoll'))

{{--
    Browse by atoll — §16 Phase 15. Each card is a link into the search with
    the atoll chosen, and its count is the count that search will show.
--}}
@section('content')
<div class="container mx-auto px-4 section-y-tight">
    <header class="mx-auto mb-10 max-w-2xl text-center">
        <h1 dir="auto" class="section-title">{{ __('messages.Stays by atoll') }}</h1>
        <p dir="auto" class="text-brand-body">
            {{ __('messages.Pick an atoll to see every place to stay in it.') }}
        </p>
        <p dir="auto" class="mt-4 text-sm">
            <a href="{{ route('stays.index') }}" class="text-wine-700 underline hover:no-underline">{{ __('messages.Search every place to stay') }}</a>
            <span aria-hidden="true" class="text-ink-muted">·</span>
            <a href="{{ route('stays.hosts') }}" class="text-wine-700 underline hover:no-underline">{{ __('messages.Our hosts') }}</a>
        </p>
    </header>

    @if($atolls->isEmpty())
        <div class="card mx-auto max-w-xl text-center">
            <h2 dir="auto" class="mb-2 text-lg font-bold text-ink">{{ __('messages.Nothing listed by atoll yet') }}</h2>
            <p dir="auto" class="mb-4 text-sm text-ink-muted">
                {{ __('messages.Try different dates, or ask us — we often have something that is not on the site yet.') }}
            </p>
            <a href="{{ route('contact') }}" class="btn-secondary">{{ __('messages.Ask us') }}</a>
        </div>
    @else
        <ul class="mx-auto grid max-w-5xl gap-6 md:grid-cols-2 lg:grid-cols-3">
            @foreach($atolls as $atoll)
                <li>
                    <a href="{{ $atoll['url'] }}" class="card group flex h-full flex-col gap-2 transition hover:shadow-lg">
                        <h2 dir="auto" class="text-xl font-bold text-ink group-hover:text-wine-700">{{ $atoll['name'] }}</h2>
                        <p dir="auto" class="text-sm font-medium text-ink">
                            {{ trans_choice('messages.:count place to stay|:count places to stay', $atoll['count'], ['count' => $atoll['count']]) }}
                        </p>
                        @if($atoll['islands'] !== [])
                            <p dir="auto" class="text-sm text-ink-muted">{{ implode(', ', $atoll['islands']) }}</p>
                        @endif
                    </a>
                </li>
            @endforeach
        </ul>
    @endif
</div>
@endsection
