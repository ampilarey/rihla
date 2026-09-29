@extends('layouts.app')

@section('title', $partner->name)
@section('og_title', $partner->name)
@section('og_description', $page->tagline ?: __('messages.A guesthouse in the Maldives, booked through Rihla.'))
@section('og_image', $shareCard)
@section('twitter_title', $partner->name)
@section('twitter_description', $page->tagline ?: __('messages.A guesthouse in the Maldives, booked through Rihla.'))
@section('twitter_image', $shareCard)

@push('schema')
    @unless($previewing)
        @php($hostSchema = \App\Support\Seo::host($partner, $page, $listings, url()->current()))
        <script type="application/ld+json">{!! \App\Support\Seo::json($hostSchema) !!}</script>
    @endunless
@endpush

{{--
    A host's own page — §16.8. Two layouts inside Rihla's own header and
    footer. The host's colours reach this page only as inline values, each
    checked for contrast when it was saved (ReadableColour); the trust line
    at the top is Rihla's and the host cannot edit it.
--}}
@section('content')
@php($primary = $page->primary())
@php($order = $page->layout === \App\Models\HostPage::GRID
    ? ['listings', 'about', 'gallery', 'map', 'faq', 'contact']
    : ['about', 'listings', 'gallery', 'map', 'faq', 'contact'])

<div style="font-family: {{ $page->fontStack() }};">

    @if($previewing)
        <p class="bg-ink px-4 py-2 text-center text-sm text-white" role="status">
            {{ __('messages.Preview: this page is not published yet, and only people with this link can see it.') }}
        </p>
    @endif

    {{-- The cover, with the host's name and words over the bottom of it. --}}
    <header class="relative">
        @if($page->cover_path)
            <img src="{{ \Illuminate\Support\Facades\Storage::url($page->cover_path) }}" alt=""
                 width="1600" height="600" decoding="async" fetchpriority="high"
                 class="h-64 w-full object-cover md:h-96">
        @endif

        <div class="container mx-auto px-4 {{ $page->cover_path ? '-mt-16 md:-mt-20' : 'pt-10' }}">
            <div class="card relative flex flex-col gap-4 md:flex-row md:items-center">
                @if($page->logo_path)
                    <img src="{{ \Illuminate\Support\Facades\Storage::url($page->logo_path) }}" alt="{{ $partner->name }}"
                         width="96" height="96" class="h-20 w-20 shrink-0 rounded-xl bg-white object-contain md:h-24 md:w-24">
                @endif
                <div class="grow">
                    <h1 dir="auto" class="text-3xl font-bold md:text-4xl" style="color: {{ $primary }};">{{ $partner->name }}</h1>
                    @if($page->tagline)
                        <p dir="auto" class="mt-1 text-lg text-ink-muted">{{ $page->tagline }}</p>
                    @endif

                    {{-- The frame's trust, which the host cannot edit. --}}
                    <p class="mt-3 flex flex-wrap gap-2 text-xs">
                        <span dir="auto" class="inline-flex items-center rounded-full border border-success bg-white px-3 py-1 font-medium text-success-dark">
                            {{ __('messages.Checked by Rihla') }}
                            @if($partner->registration_number)
                                · <span class="ltr ms-1" dir="ltr">{{ $partner->registration_number }}</span>
                            @endif
                        </span>
                        @if($partner->recommended_at)
                            <span dir="auto" class="inline-flex items-center rounded-full bg-cream-deep px-3 py-1 font-medium text-ink">
                                {{ __('messages.Rihla recommends') }}
                            </span>
                        @endif
                    </p>
                </div>
            </div>
        </div>
    </header>

    <div class="container mx-auto px-4 section-y-tight">
        @foreach($order as $section)
            @switch($section)
                @case('about')
                    @if($page->shows('about') && $about['text'])
                        <section class="mb-12 max-w-3xl" aria-labelledby="host-about">
                            <h2 id="host-about" dir="auto" class="mb-3 text-2xl font-bold" style="color: {{ $primary }};">{{ __('messages.Our story') }}</h2>
                            @if($about['inEnglish'])
                                <p dir="auto" class="mb-3 rounded-xl border-s-4 border-s-gold bg-cream-deep px-4 py-3 text-sm text-ink">
                                    {{ __('messages.This host has not written their story in your language yet, so it is shown in English.') }}
                                </p>
                            @endif
                            <div dir="auto" class="whitespace-pre-line text-ink">{{ $about['text'] }}</div>
                        </section>
                    @endif
                    @break

                @case('listings')
                    <section class="mb-12" aria-labelledby="host-listings">
                        <h2 id="host-listings" dir="auto" class="mb-4 text-2xl font-bold" style="color: {{ $primary }};">{{ __('messages.Where you can stay') }}</h2>
                        @if($listings->isEmpty())
                            <p dir="auto" class="text-ink-muted">{{ __('messages.Nothing to book here just yet.') }}</p>
                        @else
                            <div class="grid gap-6 md:grid-cols-2 lg:grid-cols-3">
                                @foreach($listings as $property)
                                    @php($link = route('stays.show', ['property' => $property->slug]))
                                    <article class="card flex flex-col overflow-hidden">
                                        @if($property->cover_image)
                                            <img src="{{ \Illuminate\Support\Facades\Storage::url($property->cover_image) }}"
                                                 alt="{{ $property->name }}" width="640" height="420" loading="lazy" decoding="async"
                                                 class="mb-4 h-44 w-full rounded-xl object-cover">
                                        @endif
                                        <h3 dir="auto" class="text-lg font-bold text-ink">
                                            <a href="{{ $link }}" class="hover:underline">{{ $property->name }}</a>
                                        </h3>
                                        <p dir="auto" class="text-sm text-ink-muted">
                                            {{ collect([$property->island, $property->atoll])->filter()->implode(', ') }}
                                            @if($property->kind)
                                                <span aria-hidden="true">·</span> {{ \App\Models\Property::kindLabel($property->kind) }}
                                            @endif
                                        </p>
                                        <p dir="auto" class="mt-2 grow text-sm text-ink-muted">{{ $property->summary }}</p>
                                        @php($from = $property->cheapestRateFor($audience))
                                        @if($from)
                                            <p dir="auto" class="mt-4 text-sm font-medium text-ink">{{ __('messages.From :price a night', ['price' => $from->format()]) }}</p>
                                        @endif
                                        <a href="{{ $link }}" class="mt-4 inline-block rounded-lg px-4 py-2 text-center font-semibold"
                                           style="background: {{ $page->accent() }}; color: {{ $page->onAccent() }};">
                                            {{ __('messages.See the rooms') }}
                                        </a>
                                    </article>
                                @endforeach
                            </div>
                        @endif
                    </section>
                    @break

                @case('gallery')
                    @if($page->shows('gallery') && $photos !== [])
                        <section class="mb-12" aria-labelledby="host-gallery">
                            <h2 id="host-gallery" dir="auto" class="mb-4 text-2xl font-bold" style="color: {{ $primary }};">{{ __('messages.Photographs') }}</h2>
                            <div class="grid grid-cols-2 gap-3 md:grid-cols-4">
                                @foreach($photos as $photo)
                                    <img src="{{ $photo['src'] }}" alt="{{ $photo['alt'] }}" width="400" height="300" loading="lazy" decoding="async"
                                         class="h-36 w-full rounded-xl object-cover md:h-44">
                                @endforeach
                            </div>
                        </section>
                    @endif
                    @break

                @case('map')
                    @if($page->shows('map') && $points !== [])
                        <section class="mb-12" aria-labelledby="host-map">
                            <h2 id="host-map" dir="auto" class="mb-3 text-2xl font-bold" style="color: {{ $primary }};">{{ __('messages.Where it is') }}</h2>
                            <div id="stay-map" data-points="{{ json_encode($points) }}"
                                 class="h-72 w-full overflow-hidden rounded-2xl bg-cream-deep"
                                 role="region" aria-label="{{ __('messages.Map') }}"></div>
                        </section>
                    @endif
                    @break

                @case('faq')
                    @if($faq !== [])
                        <section class="mb-12 max-w-3xl" aria-labelledby="host-faq">
                            <h2 id="host-faq" dir="auto" class="mb-4 text-2xl font-bold" style="color: {{ $primary }};">{{ __('messages.Questions and answers') }}</h2>
                            @foreach($faq as $item)
                                <details class="card mb-3">
                                    <summary dir="auto" class="cursor-pointer font-semibold text-ink">{{ $item['question'] }}</summary>
                                    <p dir="auto" class="mt-2 whitespace-pre-line text-ink">{{ $item['answer'] }}</p>
                                </details>
                            @endforeach
                        </section>
                    @endif
                    @break

                @case('contact')
                    @if($page->shows('contact') && ($page->whatsapp || $page->instagram || $page->facebook || $page->website_url))
                        <section class="mb-12 max-w-3xl" aria-labelledby="host-contact">
                            <h2 id="host-contact" dir="auto" class="mb-3 text-2xl font-bold" style="color: {{ $primary }};">{{ __('messages.Contact') }}</h2>
                            <ul class="flex flex-wrap gap-3">
                                @if($whatsapp = \App\Support\Contact::whatsappUrlFor($page->whatsapp))
                                    <li><a href="{{ $whatsapp }}" rel="noopener" target="_blank"
                                           class="inline-block rounded-lg px-4 py-2 font-semibold" style="background: {{ $page->accent() }}; color: {{ $page->onAccent() }};">WhatsApp</a></li>
                                @endif
                                @if($page->instagram)
                                    <li><a href="{{ $page->instagram }}" rel="noopener nofollow" target="_blank" class="btn-secondary">Instagram</a></li>
                                @endif
                                @if($page->facebook)
                                    <li><a href="{{ $page->facebook }}" rel="noopener nofollow" target="_blank" class="btn-secondary">Facebook</a></li>
                                @endif
                                @if($page->website_url)
                                    <li><a href="{{ $page->website_url }}" rel="noopener nofollow" target="_blank" class="btn-secondary">{{ __('messages.Website') }}</a></li>
                                @endif
                            </ul>
                        </section>
                    @endif
                    @break
            @endswitch
        @endforeach

        {{-- Rihla's line — the frame the host's page sits in. --}}
        <p dir="auto" class="mx-auto max-w-3xl border-t border-gray-200 pt-4 text-center text-sm text-ink-muted">
            {{ __('messages.Booked through Rihla Travels. Rihla checks every host before their page goes live.') }}
            @if(config('invoices.issuer.registration'))
                <span class="ltr" dir="ltr">({{ config('invoices.issuer.registration') }})</span>
            @endif
        </p>
    </div>
</div>
@endsection
