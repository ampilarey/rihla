@extends('layouts.app')

@section('title', __('messages.Learning'))

@section('content')
    <div class="container mx-auto max-w-3xl px-4 section-y-tight">

        <x-portal-nav current="learning" />

        <h1 class="mb-2 text-2xl font-bold text-ink">{{ __('messages.Getting ready') }}</h1>

        @if($plan->isHistory())
            {{--
                The journey has been and gone. Showing eleven overdue modules
                to somebody who has already travelled is nagging about a thing
                that cannot be fixed.
            --}}
            <p class="mb-6 text-ink-muted">
                {{ __('messages.Your journey has been and gone. Everything here is still yours to read whenever you like.') }}
            </p>
        @else
            <p class="mb-6 text-ink-muted">{{ $plan->summary() }}</p>
        @endif

        @if($plan->total() === 0)
            <div class="card">
                <p class="text-ink-muted">
                    {{ __('messages.Nothing has been put up yet. Every lesson here is checked by a named scholar before it appears.') }}
                </p>
            </div>
        @else
            @php
                $groups = [
                    'now' => __('messages.To read now'),
                    'later' => __('messages.Coming up'),
                    'done' => __('messages.Read'),
                ];
            @endphp

            @foreach($groups as $state => $heading)
                @php $entries = $plan->inState($state); @endphp

                @if($entries->isNotEmpty())
                    <section class="mb-8">
                        <h2 class="mb-3 text-lg font-bold text-ink">{{ $heading }}</h2>

                        <ul class="space-y-3">
                            @foreach($entries as $entry)
                                <li class="card">
                                    <a href="{{ route('learning.show', ['locale' => app()->getLocale(), 'slug' => $entry->module->slug]) }}"
                                       class="block">
                                        <h3 class="font-semibold text-ink">{{ $entry->module->title }}</h3>

                                        @if($entry->module->summary)
                                            <p class="mt-1 text-sm text-ink-muted">{{ $entry->module->summary }}</p>
                                        @endif
                                    </a>

                                    <p class="mt-2 text-sm text-ink-muted">
                                        @if($state === 'done')
                                            {{ __('messages.Read') }}
                                            @if($entry->completion?->quizLabel())
                                                {{-- The last attempt, not the best: it is a prompt
                                                     to go back, not a grade. --}}
                                                · {{ __('messages.Questions: :score', ['score' => $entry->completion->quizLabel()]) }}
                                            @endif
                                        @elseif(! $plan->isHistory())
                                            {{--
                                                A date that has passed cannot be phrased as a
                                                deadline. "By 21 August" under a heading saying
                                                "to read now" reads as a date still to come —
                                                a screenshot caught it saying that about a day
                                                thirty days gone.
                                            --}}
                                            {{-- The date goes through <x-local-date>, never
                                                 translatedFormat() directly: an unisolated month
                                                 on a Dhivehi page drags the numbers around it
                                                 into the right-to-left run. BidiTest caught this
                                                 line doing exactly that. --}}
                                            @if($entry->isOverdue())
                                                <span class="font-medium text-wine-700">
                                                    {{ __('messages.It was due') }}
                                                    <x-local-date :date="$entry->due_on" format="j F" />
                                                </span>
                                            @elseif($entry->isDueToday())
                                                <span class="font-medium text-wine-700">{{ __('messages.Due today') }}</span>
                                            @else
                                                {{ __('messages.Read it by') }}
                                                <x-local-date :date="$entry->due_on" format="j F" />
                                            @endif
                                        @endif

                                        @if($entry->module->lengthLabel())
                                            · {{ $entry->module->lengthLabel() }}
                                        @endif
                                    </p>

                                    @if($entry->module->location)
                                        {{-- §7.3's itinerary tie-in, said out loud. A module
                                             that appeared because of where this trip goes should
                                             say so, or it reads as an unexplained extra. --}}
                                        <p class="mt-2 text-xs font-medium text-wine-700">
                                            {{ __('messages.Your trip visits this place') }}
                                        </p>
                                    @endif
                                </li>
                            @endforeach
                        </ul>
                    </section>
                @endif
            @endforeach
        @endif

        {{-- §6.4, on the page somebody is reading rather than in its own
             corner of the portal: the moment you want to ask is while you
             are reading, and a form two clicks away is one nobody uses. --}}
        <div class="card mb-8">
            <h2 class="mb-1 font-semibold text-ink">{{ __('messages.Have a question?') }}</h2>
            <p class="text-sm text-brand-body">
                {{ __('messages.Write your question and somebody will come back to you. What you write is only seen by the office and the scholar unless you say otherwise below.') }}
            </p>
            <a href="{{ route('learning.questions', ['locale' => app()->getLocale()]) }}"
               class="btn-secondary mt-3">{{ __('messages.Ask a scholar') }}</a>
        </div>

        @if($paths->isNotEmpty())
            <section class="mb-8">
                <h2 class="mb-3 text-lg font-bold text-ink">{{ __('messages.If you would rather read in order') }}</h2>

                <ul class="space-y-3">
                    @foreach($paths as $path)
                        @continue($path->publishedModules->isEmpty())

                        <li class="card">
                            <h3 class="font-semibold text-ink">{{ $path->name }}</h3>
                            <p class="text-sm text-ink-muted">{{ $path->audienceLabel() }}</p>

                            @if($path->summary)
                                <p class="mt-1 text-sm text-ink-muted">{{ $path->summary }}</p>
                            @endif

                            <ol class="mt-3 space-y-1 text-sm">
                                @foreach($path->publishedModules as $module)
                                    <li>
                                        <a href="{{ route('learning.show', ['locale' => app()->getLocale(), 'slug' => $module->slug]) }}"
                                           class="text-wine-600 hover:underline">{{ $module->title }}</a>
                                    </li>
                                @endforeach
                            </ol>
                        </li>
                    @endforeach
                </ul>
            </section>
        @endif
    </div>
@endsection
