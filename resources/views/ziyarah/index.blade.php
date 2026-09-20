@extends('layouts.app')

@section('title', __('messages.Ziyarah Guide'))

@section('content')
    <div class="container mx-auto px-4 section-y-tight">
        <header class="mx-auto mb-8 max-w-2xl text-center">
            <h1 class="section-title">{{ __('messages.Ziyarah Guide') }}</h1>
            <p class="text-brand-body">
                {{ __('messages.The places pilgrims visit, what is known about them, and what to do when you are there.') }}
            </p>
        </header>

        {{--
            The offline control — §7.2's "full offline support", which is the
            feature pilgrims use with no data in Saudi Arabia.

            Rendered server-side with its fallback sentence showing. The
            script replaces that only once it has confirmed the browser can
            actually do it, so a phone with no service worker reads an honest
            line rather than a button that does nothing.
        --}}
        @if($total > 0)
            <div class="card mx-auto mb-10 max-w-2xl p-5"
                 data-ziyarah-offline
                 data-manifest="{{ route('ziyarah.manifest') }}">
                <h2 class="mb-1 font-semibold text-ink">{{ __('messages.Keep this guide on your phone') }}</h2>
                <p class="text-sm text-brand-body" data-ziyarah-status>
                    {{ __('messages.Saving works in most phone browsers. Open this page once on the phone you are taking, before you fly.') }}
                </p>
                <button type="button"
                        class="btn-secondary mt-3 hidden"
                        {{--
                            $total + 1: the list page is saved too, and it is
                            how a pilgrim with no data finds the others. A
                            button offering 3 that then reports 4 reads as a
                            bug on the one screen that has to be trusted.
                        --}}
                        data-ziyarah-save>{{ __('messages.Save all :count pages', ['count' => $total + 1]) }}</button>
            </div>
        @endif

        @forelse($byCity as $city => $locations)
            <section class="mb-10">
                <h2 class="mb-4 text-xl font-bold text-ink">{{ $locations->first()->cityLabel() }}</h2>

                <ul class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                    @foreach($locations as $location)
                        <li class="card h-full p-5">
                            <a href="{{ route('ziyarah.show', $location->slug) }}" class="block">
                                <h3 class="font-semibold text-ink">{{ $location->name }}</h3>
                                @if($location->summary)
                                    <p class="mt-1 text-sm text-brand-body">{{ $location->summary }}</p>
                                @endif
                            </a>

                            @if($location->misconceptions_count > 0)
                                {{--
                                    Named on the card, because it is the reason
                                    to open the page. §7.2: knowing what people
                                    are wrongly told is what prevents the
                                    innovations pilgrims are warned about.
                                --}}
                                <p class="mt-3 text-xs font-medium text-wine-700">
                                    {{ trans_choice('messages.:count thing people get told that is not so|:count things people get told that are not so', $location->misconceptions_count, ['count' => $location->misconceptions_count]) }}
                                </p>
                            @endif
                        </li>
                    @endforeach
                </ul>
            </section>
        @empty
            <p class="mx-auto max-w-2xl text-center text-brand-body">
                {{ __('messages.Nothing here yet. Every page in this guide is checked by a named scholar before it goes up, and none has been yet.') }}
            </p>
        @endforelse
    </div>
@endsection
