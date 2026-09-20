@extends('layouts.app')

@section('title', $article->title)

@push('schema')
    @php($articleSchema = \App\Support\Seo::article($article, url()->current()))
    @if($articleSchema)
        <script type="application/ld+json">{!! \App\Support\Seo::json($articleSchema) !!}</script>
    @endif
@endpush

@section('content')
    <div class="container mx-auto px-4 section-y-tight">
        <nav class="mb-6 text-sm" aria-label="{{ __('messages.Breadcrumb') }}">
            <a href="{{ route('articles.index') }}" class="text-wine-600 hover:underline">{{ __('messages.Articles') }}</a>
        </nav>

        <article class="mx-auto max-w-3xl">
            <header class="mb-6">
                <h1 class="text-3xl font-bold text-ink md:text-4xl" dir="auto">{{ $article->title }}</h1>

                <p class="mt-2 text-sm text-ink-muted" dir="auto">
                    <time datetime="{{ $article->published_at->toDateString() }}">
                        {{ $article->published_at->translatedFormat('j F Y') }}
                    </time>
                    @if($article->author)
                        <span aria-hidden="true">·</span> {{ $article->author->name }}
                    @endif
                </p>
            </header>

            @if($article->cover_image)
                <img src="{{ \Illuminate\Support\Facades\Storage::url($article->cover_image) }}"
                     alt="" class="mb-6 w-full rounded-2xl object-cover"
                     loading="lazy" decoding="async">
            @endif

            {{-- Escaped, then newlines turned into breaks. The body is
                 written by staff in a textarea, not pasted HTML, and
                 rendering it raw would make the admin panel an injection
                 route straight past the Content-Security-Policy. --}}
            <div class="prose max-w-none text-brand-body" dir="auto">
                {!! nl2br(e($article->body)) !!}
            </div>
        </article>

        @if($related->isNotEmpty())
            <section class="mx-auto mt-12 max-w-3xl">
                <h2 class="mb-4 text-xl font-bold text-ink">{{ __('messages.More to read') }}</h2>
                <ul class="space-y-2">
                    @foreach($related as $other)
                        <li>
                            <a href="{{ route('articles.show', $other->slug) }}"
                               class="text-wine-600 hover:underline" dir="auto">{{ $other->title }}</a>
                        </li>
                    @endforeach
                </ul>
            </section>
        @endif
    </div>
@endsection
