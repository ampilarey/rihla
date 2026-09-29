@extends('layouts.app')

@section('title', __('messages.Our hosts'))

{{--
    The host directory — §16 Phase 15. Only hosts whose own page a guest may
    open (HostPageController::index holds the gate), each linking to it.
--}}
@section('content')
<div class="container mx-auto px-4 section-y-tight">
    <header class="mx-auto mb-10 max-w-2xl text-center">
        <h1 dir="auto" class="section-title">{{ __('messages.Our hosts') }}</h1>
        <p dir="auto" class="text-brand-body">
            {{ __('messages.The guesthouses and landlords you book through Rihla. Every one has been checked by a person at Rihla before anything was listed.') }}
        </p>
        <p dir="auto" class="mt-4 text-sm">
            <a href="{{ route('stays.index') }}" class="text-wine-700 underline hover:no-underline">{{ __('messages.Search every place to stay') }}</a>
            <span aria-hidden="true" class="text-ink-muted">·</span>
            <a href="{{ route('stays.atolls') }}" class="text-wine-700 underline hover:no-underline">{{ __('messages.Stays by atoll') }}</a>
        </p>
    </header>

    @if($hosts->isEmpty())
        <div class="card mx-auto max-w-xl text-center">
            <h2 dir="auto" class="mb-2 text-lg font-bold text-ink">{{ __('messages.No host pages yet') }}</h2>
            <p dir="auto" class="mb-4 text-sm text-ink-muted">
                {{ __('messages.Try different dates, or ask us — we often have something that is not on the site yet.') }}
            </p>
            <a href="{{ route('contact') }}" class="btn-secondary">{{ __('messages.Ask us') }}</a>
        </div>
    @else
        <ul class="mx-auto grid max-w-5xl gap-6 md:grid-cols-2 lg:grid-cols-3">
            @foreach($hosts as $host)
                <li>
                    <a href="{{ route('stays.host', ['partner' => $host['partner']->slug]) }}"
                       class="card group flex h-full flex-col gap-2 transition hover:shadow-lg">
                        <div class="flex items-center gap-3">
                            @if($host['page']->logo_path)
                                <img src="{{ \Illuminate\Support\Facades\Storage::url($host['page']->logo_path) }}" alt=""
                                     width="56" height="56" loading="lazy" decoding="async"
                                     class="h-14 w-14 shrink-0 rounded-lg bg-white object-contain">
                            @endif
                            <h2 dir="auto" class="text-xl font-bold text-ink group-hover:text-wine-700">{{ $host['partner']->name }}</h2>
                        </div>

                        @if($host['page']->tagline)
                            <p dir="auto" class="text-sm text-ink-muted">{{ $host['page']->tagline }}</p>
                        @endif

                        @if($host['places'] !== [])
                            <p dir="auto" class="text-sm text-ink-muted">{{ implode(' · ', $host['places']) }}</p>
                        @endif

                        <p dir="auto" class="mt-auto pt-2 text-sm font-medium text-ink">
                            {{ trans_choice('messages.:count place to stay|:count places to stay', $host['listings'], ['count' => $host['listings']]) }}
                            @if($host['rating'])
                                <span aria-hidden="true">·</span>
                                <span aria-hidden="true" class="text-gold-600">★</span>
                                {{ number_format($host['rating']['average'], 1) }} · {{ trans_choice('messages.:count review|:count reviews', $host['rating']['count'], ['count' => $host['rating']['count']]) }}
                            @endif
                        </p>
                    </a>
                </li>
            @endforeach
        </ul>
    @endif
</div>
@endsection
