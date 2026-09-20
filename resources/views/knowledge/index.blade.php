@extends('layouts.app')

@section('title', __('messages.Knowledge Centre'))

@section('content')
    <div class="container mx-auto px-4 section-y-tight">
        <header class="mx-auto mb-8 max-w-2xl text-center">
            <h1 class="section-title">{{ __('messages.Knowledge Centre') }}</h1>
            <p class="text-brand-body">
                {{ __('messages.What we have written down, and who checked it. Every page here carries the name of the scholar who approved it and the sources it rests on.') }}
            </p>
        </header>

        @forelse($byCategory as $category => $articles)
            <section class="mb-10">
                <h2 class="mb-4 text-xl font-bold text-ink">{{ $articles->first()->categoryLabel() }}</h2>

                <ul class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                    @foreach($articles as $article)
                        <li class="card h-full p-5">
                            <a href="{{ route('knowledge.show', $article->slug) }}" class="block">
                                <h3 class="font-semibold text-ink">{{ $article->title }}</h3>
                                @if($article->summary)
                                    <p class="mt-1 text-sm text-brand-body">{{ $article->summary }}</p>
                                @endif
                            </a>
                        </li>
                    @endforeach
                </ul>
            </section>
        @empty
            {{--
                The same sentence the Ziyarah Guide uses, and for the same
                reason: an empty page that says "nothing yet" with no cause
                reads as a broken site. This one has a cause, and naming it
                is also the most honest pressure on the thing that is
                actually missing.
            --}}
            <p class="mx-auto max-w-2xl text-center text-brand-body">
                {{ __('messages.Nothing here yet. Every page in this guide is checked by a named scholar before it goes up, and none has been yet.') }}
            </p>
        @endforelse
    </div>
@endsection
