@extends('layouts.app')

@section('title', __('messages.Articles'))

@section('content')
    <div class="container mx-auto px-4 section-y-tight">
        <header class="mx-auto mb-10 max-w-2xl text-center">
            <h1 class="section-title">{{ __('messages.Articles') }}</h1>
            <p class="text-brand-body">
                {{ __('messages.What to pack, how the visa works, and what to expect when you arrive.') }}
            </p>
        </header>

        @forelse($articles as $article)
            <article class="card mb-6 p-6">
                <h2 class="text-xl font-bold text-ink">
                    <a href="{{ route('articles.show', $article->slug) }}"
                       class="hover:text-wine-600 focus:outline-none focus:ring-2 focus:ring-wine-500 focus:ring-offset-2 rounded">
                        <span dir="auto">{{ $article->title }}</span>
                    </a>
                </h2>

                <p class="mt-1 text-sm text-ink-muted" dir="auto">
                    <time datetime="{{ $article->published_at->toDateString() }}">
                        <x-local-date :date="$article->published_at" format="j F Y" />
                    </time>
                    @if($article->author)
                        <span aria-hidden="true">·</span> {{ $article->author->name }}
                    @endif
                </p>

                @if($article->excerpt)
                    <p class="mt-3 text-brand-body" dir="auto">{{ $article->excerpt }}</p>
                @endif

                <a href="{{ route('articles.show', $article->slug) }}"
                   class="mt-4 inline-block font-medium text-wine-600 hover:underline">
                    {{ __('messages.Read this') }}
                </a>
            </article>
        @empty
            <p class="py-12 text-center text-ink-muted">{{ __('messages.Nothing has been written here yet.') }}</p>
        @endforelse

        <div class="mt-8">{{ $articles->links() }}</div>
    </div>
@endsection
