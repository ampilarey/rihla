@extends('layouts.app')

@section('title', __('messages.Ask a scholar'))

@section('content')
    <div class="container mx-auto max-w-3xl px-4 section-y-tight">

        <x-portal-nav current="learning" />

        <nav class="mb-4 text-sm">
            <a href="{{ route('learning.index', ['locale' => app()->getLocale()]) }}"
               class="text-wine-600 hover:underline">&larr; {{ __('messages.Getting ready') }}</a>
        </nav>

        <h1 class="mb-2 text-2xl font-bold text-ink">{{ __('messages.Ask a scholar') }}</h1>

        <p class="mb-6 text-ink-muted">
            {{ __('messages.Write your question and somebody will come back to you. What you write is only seen by the office and the scholar unless you say otherwise below.') }}
        </p>

        @if (session('status'))
            <div class="card mb-6 border-s-4 border-s-wine">
                <p class="text-ink">{{ session('status') }}</p>
            </div>
        @endif

        {{--
            §9.6's pilgrim assistant, in front of the scholar's queue rather
            than beside it. Two separate "ask a question" boxes would only
            teach a pilgrim to guess which one to use.

            It answers strictly from pages a named scholar has approved, and
            hands over to a person whenever it cannot. Today it always hands
            over, because nothing has been approved yet — which is the
            feature working, not failing.
        --}}
        <form method="POST" action="{{ route('learning.questions.guide', ['locale' => app()->getLocale()]) }}"
              class="card mb-6">
            @csrf

            <label for="guide-question" class="mb-1 block font-medium text-ink">
                {{ __('messages.Look it up first') }}
            </label>

            <p class="mb-3 text-sm text-ink-muted">
                {{ __('messages.We will check the pages our scholars have already approved. If they do not answer it, the question goes to a scholar and nothing is guessed.') }}
            </p>

            <textarea id="guide-question" name="question" rows="3" required minlength="10" maxlength="4000"
                      dir="auto"
                      class="w-full rounded-lg border-cream-deep text-ink focus:border-wine-500 focus:ring-wine-500">{{ old('question') }}</textarea>

            @error('question')
                <p class="mt-1 text-sm text-error-dark">{{ $message }}</p>
            @enderror

            <button type="submit" class="btn-secondary mt-3">{{ __('messages.Look it up') }}</button>
        </form>

        @if (session('assistant_answer'))
            @php($answer = session('assistant_answer'))

            <div class="card mb-8 {{ $answer->referred ? 'border-s-4 border-s-cream-deep' : 'border-s-4 border-s-wine' }}">
                @unless ($answer->referred)
                    {{--
                        The label §9.6 requires on anything a model wrote,
                        above the text rather than under it: a disclosure
                        below the answer is read after the answer has
                        already been believed.
                    --}}
                    <p class="mb-2 text-xs font-semibold uppercase tracking-wide text-ink-muted">
                        {{ $answer->label() }}
                    </p>
                @endunless

                <div class="whitespace-pre-line text-brand-body">{{ $answer->text }}</div>

                @if ($answer->because)
                    <p class="mt-3 text-sm text-ink-muted">{{ $answer->because }}</p>
                @endif

                @if ($answer->sources->isNotEmpty())
                    <div class="mt-4 border-t border-cream-deep pt-3">
                        <p class="mb-2 text-sm font-medium text-ink">{{ __('messages.Where this comes from') }}</p>
                        <ul class="space-y-1 text-sm">
                            @foreach ($answer->sources as $index => $source)
                                <li>
                                    <span class="text-ink-muted">[{{ $index + 1 }}]</span>
                                    <a href="{{ $source->url }}" class="text-wine-600 hover:underline">{{ $source->title }}</a>
                                    <span class="text-ink-muted">— {{ $source->kindLabel }}</span>
                                </li>
                            @endforeach
                        </ul>
                    </div>
                @endif
            </div>
        @endif

        <form method="POST" action="{{ route('learning.questions.store', ['locale' => app()->getLocale()]) }}"
              class="card mb-8">
            @csrf

            <label for="question-body" class="mb-1 block font-medium text-ink">
                {{ __('messages.Your question') }}
            </label>

            <textarea id="question-body" name="body" rows="6" required minlength="10" maxlength="4000"
                      dir="auto"
                      class="w-full rounded-lg border-cream-deep text-ink focus:border-wine-500 focus:ring-wine-500">{{ old('body', session('assistant_question')) }}</textarea>

            @error('body')
                <p class="mt-1 text-sm text-error-dark">{{ $message }}</p>
            @enderror

            {{--
                Consent, asked for at the moment of asking and never assumed
                afterwards. Unchecked by default, and the sentence says what
                it actually means rather than "share my question".
            --}}
            <label class="mt-4 flex items-start gap-2 text-ink">
                <input type="checkbox" name="may_publish" value="1" @checked(old('may_publish'))
                       class="mt-1 rounded border-cream-deep text-wine-600 focus:ring-wine-500">
                <span>
                    {{ __('messages.Other pilgrims may find this useful. You can put my question and the answer on the website — without my name.') }}
                </span>
            </label>

            <p class="mt-2 text-sm text-ink-muted">
                {{ __('messages.If you leave this unticked, your question stays between you, the office and the scholar.') }}
            </p>

            <button type="submit" class="btn-primary mt-4">{{ __('messages.Send the question') }}</button>
        </form>

        @if($questions->isNotEmpty())
            <h2 class="mb-3 text-lg font-bold text-ink">{{ __('messages.What you have asked') }}</h2>

            <ul class="space-y-4">
                @foreach($questions as $question)
                    <li class="card">
                        <p class="whitespace-pre-line text-ink" dir="auto">{{ $question->body }}</p>

                        <p class="mt-2 text-sm text-ink-muted">{{ $question->statusLabel() }}</p>

                        @if($question->answer)
                            <div class="mt-4 rounded-lg bg-cream p-4">
                                @if($question->scholar)
                                    <p class="mb-1 text-sm font-semibold text-ink">
                                        {{ __('messages.Answered by :name', ['name' => $question->scholar->name]) }}
                                    </p>
                                @endif

                                <p class="whitespace-pre-line text-ink" dir="auto">{{ $question->answer }}</p>

                                @if($question->references->isNotEmpty())
                                    <ul class="mt-3 space-y-1 text-sm text-ink-muted">
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
                            </div>
                        @elseif($question->declined_reason)
                            {{-- An honest ending, shown as one. Silence is the
                                 thing this feature exists to avoid. --}}
                            <div class="mt-4 rounded-lg border border-cream-deep p-4">
                                <p class="whitespace-pre-line text-ink" dir="auto">{{ $question->declined_reason }}</p>
                            </div>
                        @endif
                    </li>
                @endforeach
            </ul>
        @endif
    </div>
@endsection
