@extends('layouts.app')

@php
    $locale = app()->getLocale();
    $tagline = $site->getTranslation('tagline', $locale) ?: $site->getTranslation('tagline', 'en');
    $about = $site->getTranslation('about', $locale) ?: $site->getTranslation('about', 'en');
@endphp

@section('description', $tagline ?: $company->name)

@push('schema')
    <script type="application/ld+json">{!! \App\Support\Seo::json(array_filter([
        '@context' => 'https://schema.org',
        '@type' => 'TravelAgency',
        'name' => $company->name,
        'url' => url('/'),
        'identifier' => $company->registration_number,
        'telephone' => $company->phone,
        'email' => $company->email,
        'logo' => $site->logoUrl() ? url($site->logoUrl()) : null,
    ])) !!}</script>
@endpush

@section('content')
    {{-- Each theme opens its own way (Phase 4b). --}}
    @if ($site->theme === \Modules\Companies\Models\CompanySite::MINIMAL)
        <section class="container mx-auto px-4 pt-12 pb-6 md:pt-20">
            <h1 class="max-w-3xl text-3xl font-bold text-[color:var(--site-primary)] md:text-5xl" dir="auto">{{ $company->name }}</h1>
            @if ($tagline)
                <p class="mt-4 max-w-2xl text-lg text-ink-muted md:text-xl" dir="auto">{{ $tagline }}</p>
            @endif
            <a href="{{ route('packages.index') }}" class="btn-primary mt-8 inline-flex items-center">{{ __('messages.See our packages') }}</a>
            @if ($site->coverUrl())
                <img src="{{ $site->coverUrl() }}" alt="" class="mt-10 aspect-[16/7] w-full rounded-2xl object-cover" fetchpriority="high">
            @endif
        </section>
    @elseif ($site->theme === \Modules\Companies\Models\CompanySite::BOLD)
        <section class="bg-[var(--site-accent)] text-[color:var(--site-on-accent)]">
            <div class="container mx-auto px-4 pb-16 pt-6 text-center md:pb-24">
                @if ($tagline)
                    <p class="mx-auto max-w-3xl text-3xl font-bold md:text-5xl" dir="auto">{{ $tagline }}</p>
                @endif
                <a href="{{ route('packages.index') }}" class="mt-8 inline-flex items-center rounded-lg border-2 border-current px-6 py-3 font-semibold hover:underline">{{ __('messages.See our packages') }}</a>
            </div>
        </section>
        @if ($site->coverUrl())
            <img src="{{ $site->coverUrl() }}" alt="" class="aspect-[16/6] w-full object-cover" fetchpriority="high">
        @endif
    @else
        <section class="relative overflow-hidden bg-gray-900 text-white">
            @if ($site->coverUrl())
                <img src="{{ $site->coverUrl() }}" alt="" class="absolute inset-0 h-full w-full object-cover opacity-60" fetchpriority="high">
            @endif
            <div class="relative container mx-auto px-4 py-20 md:py-28">
                <h1 class="max-w-3xl text-3xl font-bold md:text-5xl" dir="auto">{{ $company->name }}</h1>
                @if ($tagline)
                    <p class="mt-4 max-w-2xl text-lg md:text-xl" dir="auto">{{ $tagline }}</p>
                @endif
                <a href="{{ route('packages.index') }}" class="btn-primary mt-8 inline-flex items-center">{{ __('messages.See our packages') }}</a>
            </div>
        </section>
    @endif

    @if ($site->theme === \Modules\Companies\Models\CompanySite::BOLD)
        {{-- The bold theme's heading is the tagline; the name still has to be the page's one h1. --}}
        <h1 class="sr-only">{{ $company->name }}</h1>
    @endif

    @foreach ($site->homeSections() as $section)
        @if ($section === 'departures' && $departures->isNotEmpty())
            <section class="container mx-auto px-4 section-y-tight" aria-labelledby="site-departures">
                <h2 id="site-departures" class="mb-6 text-2xl font-bold text-[color:var(--site-primary)]">{{ __('messages.Upcoming trips') }}</h2>
                <div class="grid gap-6 md:grid-cols-2 lg:grid-cols-3">
                    @foreach ($departures as $departure)
                        <x-departure-card :departure="$departure" />
                    @endforeach
                </div>
            </section>
        @elseif ($section === 'about' && $about)
            <section class="bg-gray-50" aria-labelledby="site-about">
                <div class="container mx-auto max-w-3xl px-4 section-y-tight">
                    <h2 id="site-about" class="mb-4 text-2xl font-bold text-[color:var(--site-primary)]">{{ __('messages.About us') }}</h2>
                    <div class="prose max-w-none text-ink" dir="auto">{!! nl2br(e($about)) !!}</div>
                </div>
            </section>
        @elseif ($section === 'contact')
            <section class="container mx-auto px-4 section-y-tight text-center" aria-labelledby="site-reach">
                <h2 id="site-reach" class="mb-3 text-2xl font-bold text-[color:var(--site-primary)]">{{ __('messages.How to reach us') }}</h2>
                <p class="mb-6 text-ink-muted">{{ __('messages.Ask us anything about a trip, or send us a message.') }}</p>
                <a href="{{ route('contact') }}" class="btn-primary inline-flex items-center">{{ __('messages.Contact us') }}</a>
            </section>
        @endif
    @endforeach
@endsection
