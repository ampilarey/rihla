@extends('layouts.app')

@section('title', $module->title)

@section('content')
    <div class="container mx-auto max-w-3xl px-4 section-y-tight">

        <x-portal-nav current="learning" />

        <nav class="mb-4 text-sm">
            <a href="{{ route('learning.index', ['locale' => app()->getLocale()]) }}"
               class="text-wine-600 hover:underline">&larr; {{ __('messages.Getting ready') }}</a>
        </nav>

        <article>
            <h1 class="text-2xl font-bold text-ink">{{ $module->title }}</h1>

            @if($module->summary)
                <p class="mt-2 text-ink-muted">{{ $module->summary }}</p>
            @endif

            <p class="mt-3 text-sm text-ink-muted">
                @if($module->lengthLabel()){{ $module->lengthLabel() }}@endif
                @if($module->reviewer)
                    @if($module->lengthLabel()) · @endif
                    {{-- The scholar's name on the page, not in a footer. The
                         whole apparatus behind this is pointless if the reader
                         cannot tell it happened. --}}
                    {{ __('messages.Checked by :name', ['name' => $module->reviewer->name]) }}
                @endif
            </p>

            @if($module->body)
                <div class="mt-6 whitespace-pre-line text-ink">{{ $module->body }}</div>
            @endif

            @if($module->location?->isLive())
                <p class="mt-6">
                    <a href="{{ route('ziyarah.show', ['locale' => app()->getLocale(), 'slug' => $module->location->slug]) }}"
                       class="btn-secondary">{{ __('messages.Read about this place') }}</a>
                </p>
            @endif

            @if($module->references->isNotEmpty())
                <section class="mt-8">
                    <h2 class="mb-3 text-lg font-bold text-ink">{{ __('messages.Sources') }}</h2>

                    <ul class="space-y-2 text-sm">
                        @foreach($module->references as $reference)
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
                            </li>
                        @endforeach
                    </ul>
                </section>
            @endif

            @if($module->quizQuestions->isNotEmpty())
                <section class="mt-10">
                    <h2 class="mb-2 text-lg font-bold text-ink">{{ __('messages.A few questions') }}</h2>

                    {{--
                        Said out loud, because a quiz on a religious subject
                        looks like a test and this one is not: nobody fails
                        their way out of Umrah, and nothing in this system
                        reads the score as a permission.
                    --}}
                    <p class="mb-4 text-sm text-ink-muted">
                        {{ __('messages.Nothing here is marked or kept against you. It is a way to find out what to ask about while there is still time.') }}
                    </p>

                    @if($results)
                        <div class="card mb-6 border-s-4 border-s-gold">
                            <p class="font-semibold text-ink">
                                {{ __('messages.You got :correct of :answered', ['correct' => $results['correct'], 'answered' => $results['answered']]) }}
                            </p>
                        </div>
                    @endif

                    <form method="POST"
                          action="{{ route('learning.quiz', ['locale' => app()->getLocale(), 'slug' => $module->slug]) }}">
                        @csrf

                        @foreach($module->quizQuestions as $question)
                            @php
                                $outcome = $results
                                    ? $results['results']->first(fn ($answer) => $answer->question->is($question))
                                    : null;
                            @endphp

                            <fieldset class="card mb-4">
                                <legend class="font-semibold text-ink">{{ $question->prompt }}</legend>

                                <div class="mt-3 space-y-2">
                                    @foreach($question->options as $option)
                                        <label class="flex items-start gap-2 text-ink">
                                            {{-- Checkboxes, not radios: several of these questions
                                                 genuinely have more than one right answer, and a
                                                 single-answer control forces the author to write a
                                                 false version of a real question. --}}
                                            <input type="checkbox"
                                                   name="answers[{{ $question->getKey() }}][]"
                                                   value="{{ $option->getKey() }}"
                                                   @checked($outcome && in_array($option->getKey(), $outcome->chosen, true))
                                                   class="mt-1 rounded border-cream-deep text-wine-600 focus:ring-wine-500">
                                            <span>{{ $option->text }}</span>
                                        </label>
                                    @endforeach
                                </div>

                                @if($outcome)
                                    <p class="mt-3 text-sm font-semibold {{ $outcome->correct ? 'text-success' : 'text-error-dark' }}">
                                        {{ $outcome->correct ? __('messages.That is right') : __('messages.Not quite') }}
                                    </p>

                                    @if($question->explanation)
                                        {{-- Shown whether the answer was right or wrong. The mark
                                             tells a pilgrim they have a problem; this tells them
                                             what it is. --}}
                                        <p class="mt-1 text-sm text-ink-muted">{{ $question->explanation }}</p>
                                    @endif

                                    @if($question->references->isNotEmpty())
                                        <ul class="mt-2 space-y-1 text-xs text-ink-muted">
                                            @foreach($question->references as $reference)
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
                                @endif
                            </fieldset>
                        @endforeach

                        <button type="submit" class="btn-primary">
                            {{ $results ? __('messages.Try again') : __('messages.Check my answers') }}
                        </button>
                    </form>
                </section>
            @endif
        </article>
    </div>
@endsection
