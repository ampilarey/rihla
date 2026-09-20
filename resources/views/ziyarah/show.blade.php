@extends('layouts.app')

@section('title', $location->name)

@section('content')
    <div class="container mx-auto px-4 section-y-tight">
        <article class="mx-auto max-w-3xl">
            <nav class="mb-6 text-sm">
                <a href="{{ route('ziyarah.index') }}" class="text-wine-600 hover:underline">
                    &larr; {{ __('messages.Ziyarah Guide') }}
                </a>
            </nav>

            <header class="mb-8">
                <p class="text-sm font-medium text-ink-muted">{{ $location->cityLabel() }}</p>
                <h1 class="mt-1 text-3xl font-bold text-ink">{{ $location->name }}</h1>

                @if($location->summary)
                    <p class="mt-3 text-lg text-brand-body">{{ $location->summary }}</p>
                @endif

                {{--
                    The scholar's name, on the page rather than in a footer
                    nobody reads. §7.1 asks for a named reviewer a reader can
                    see, and the whole apparatus behind this page is pointless
                    if the reader cannot tell it happened.
                --}}
                @if($location->reviewer)
                    <p class="mt-4 text-sm text-ink-muted">
                        {{ __('messages.Checked by :name', ['name' => $location->reviewer->name]) }}
                    </p>
                @endif
            </header>

            @if($location->etiquette)
                {{--
                    Etiquette first, before history. A pilgrim reading this on
                    their phone is usually already standing outside; what to do
                    is the answer they came for, and the history is what they
                    read on the bus.
                --}}
                <section class="card mb-6">
                    <h2 class="mb-2 text-xl font-bold text-ink">{{ __('messages.When you are there') }}</h2>
                    <div class="whitespace-pre-line text-brand-body">{{ $location->etiquette }}</div>
                </section>
            @endif

            @if($location->best_time)
                <section class="mb-6">
                    <h2 class="mb-2 text-xl font-bold text-ink">{{ __('messages.Best time to go') }}</h2>
                    <div class="whitespace-pre-line text-brand-body">{{ $location->best_time }}</div>
                </section>
            @endif

            @if($location->significance)
                <section class="mb-6">
                    <h2 class="mb-2 text-xl font-bold text-ink">{{ __('messages.Why it matters') }}</h2>
                    <div class="whitespace-pre-line text-brand-body">{{ $location->significance }}</div>
                </section>
            @endif

            @if($location->history)
                <section class="mb-6">
                    <h2 class="mb-2 text-xl font-bold text-ink">{{ __('messages.History') }}</h2>
                    <div class="whitespace-pre-line text-brand-body">{{ $location->history }}</div>
                </section>
            @endif

            @if($location->misconceptions->isNotEmpty())
                {{--
                    §7.2's common misconceptions. The belief and the correction
                    are set out as a pair rather than woven into a paragraph,
                    because the contrast is what a reader can repeat to the
                    person who told them.
                --}}
                <section class="mb-6">
                    <h2 class="mb-3 text-xl font-bold text-ink">{{ __('messages.Things people get told that are not so') }}</h2>

                    <ul class="space-y-4">
                        @foreach($location->misconceptions as $misconception)
                            <li class="card">
                                <p class="text-sm font-semibold uppercase tracking-wide text-ink-muted">
                                    {{ __('messages.What people say') }}
                                </p>
                                <p class="mt-1 text-brand-body">{{ $misconception->belief }}</p>

                                <p class="mt-4 text-sm font-semibold uppercase tracking-wide text-wine-700">
                                    {{ __('messages.What is actually the case') }}
                                </p>
                                <p class="mt-1 text-ink">{{ $misconception->correction }}</p>

                                @if($misconception->references->isNotEmpty())
                                    <ul class="mt-3 space-y-1 text-sm text-ink-muted">
                                        @foreach($misconception->references as $reference)
                                            <li>
                                                {{ $reference->kindLabel() }} — {{ $reference->citation }}
                                                @if($reference->gradingLabel())
                                                    <span class="{{ $reference->isCautionary() ? 'font-semibold text-error-dark' : '' }}">
                                                        ({{ $reference->gradingLabel() }})
                                                    </span>
                                                @endif
                                            </li>
                                        @endforeach
                                    </ul>
                                @endif
                            </li>
                        @endforeach
                    </ul>
                </section>
            @endif

            @if($location->references->isNotEmpty())
                <section class="mb-6">
                    <h2 class="mb-3 text-xl font-bold text-ink">{{ __('messages.Sources') }}</h2>

                    <ul class="space-y-2 text-sm">
                        @foreach($location->references as $reference)
                            {{--
                                A weak or fabricated narration stays on the page
                                and is labelled. Removing it leaves a pilgrim
                                hearing it elsewhere with no correction (§7.1) —
                                which only works if the label is noticed, so a
                                cautionary one gets its own bordered block
                                rather than a pale pill that reads as
                                decoration on a cream page.
                            --}}
                            <li class="{{ $reference->isCautionary() ? 'rounded-lg border border-error/40 bg-white p-3' : 'text-ink-muted' }}">
                                @if($reference->isCautionary())
                                    <p class="font-semibold text-error-dark">{{ $reference->gradingLabel() }}</p>
                                    <p class="mt-1 text-ink-muted">{{ $reference->kindLabel() }} — {{ $reference->citation }}</p>
                                @else
                                    {{ $reference->kindLabel() }} — {{ $reference->citation }}
                                    @if($reference->gradingLabel())
                                        <span class="badge-success ms-1">{{ $reference->gradingLabel() }}</span>
                                    @endif
                                @endif

                                @if($reference->note)
                                    <span class="mt-1 block text-ink-muted">{{ $reference->note }}</span>
                                @endif
                            </li>
                        @endforeach
                    </ul>
                </section>
            @endif

            @if($location->mapUrl())
                {{--
                    A link out, not an embed. Nobody has supplied a Google Maps
                    key, and an unkeyed embed renders a grey rectangle stamped
                    "for development purposes only" across a page like this one.
                    A link also works from a saved copy with no data: the map
                    application on the phone takes it from here.
                --}}
                <p class="mb-6">
                    <a href="{{ $location->mapUrl() }}" rel="noopener" target="_blank" class="btn-secondary">
                        {{ __('messages.Open in maps') }}
                    </a>
                </p>
            @endif

            @if($nearby->isNotEmpty())
                <section>
                    <h2 class="mb-3 text-xl font-bold text-ink">
                        {{ __('messages.Also in :city', ['city' => $location->cityLabel()]) }}
                    </h2>

                    <ul class="grid gap-3 sm:grid-cols-2">
                        @foreach($nearby as $other)
                            <li>
                                <a href="{{ route('ziyarah.show', $other->slug) }}"
                                   class="block rounded-lg border border-cream-deep p-3 text-ink hover:bg-cream">
                                    {{ $other->name }}
                                </a>
                            </li>
                        @endforeach
                    </ul>
                </section>
            @endif
        </article>
    </div>
@endsection
