@extends('layouts.app')

@section('title', $article->title)

@section('content')
    <div class="container mx-auto px-4 section-y-tight">
        <article class="mx-auto max-w-3xl">
            <nav class="mb-6 text-sm">
                <a href="{{ route('knowledge.index') }}" class="text-wine-600 hover:underline">
                    &larr; {{ __('messages.Knowledge Centre') }}
                </a>
            </nav>

            <header class="mb-8">
                <p class="text-sm font-medium text-ink-muted">{{ $article->categoryLabel() }}</p>
                <h1 class="mt-1 text-3xl font-bold text-ink">{{ $article->title }}</h1>

                @if($article->summary)
                    <p class="mt-3 text-lg text-brand-body">{{ $article->summary }}</p>
                @endif

                {{--
                    The scholar's name, on the page rather than in a footer
                    nobody reads. §7.1 asks for a named reviewer a reader can
                    see, and the whole apparatus behind this page is pointless
                    if the reader cannot tell it happened.
                --}}
                @if($article->reviewer)
                    <p class="mt-4 text-sm text-ink-muted">
                        {{ __('messages.Checked by :name', ['name' => $article->reviewer->name]) }}
                    </p>
                @endif
            </header>

            @if($article->body)
                <div class="card mb-6">
                    <div class="whitespace-pre-line text-brand-body">{{ $article->body }}</div>
                </div>
            @endif

            @if($article->references->isNotEmpty())
                <section class="mb-6">
                    <h2 class="mb-3 text-xl font-bold text-ink">{{ __('messages.Sources') }}</h2>

                    <ul class="space-y-2 text-sm">
                        @foreach($article->references as $reference)
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

            @if($related->isNotEmpty())
                <section>
                    <h2 class="mb-3 text-xl font-bold text-ink">{{ __('messages.Also on this') }}</h2>

                    <ul class="grid gap-3 sm:grid-cols-2">
                        @foreach($related as $other)
                            <li>
                                <a href="{{ route('knowledge.show', $other->slug) }}"
                                   class="block rounded-lg border border-cream-deep p-3 text-ink hover:bg-cream">
                                    {{ $other->title }}
                                </a>
                            </li>
                        @endforeach
                    </ul>
                </section>
            @endif
        </article>
    </div>
@endsection
