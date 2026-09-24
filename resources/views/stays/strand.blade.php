@extends('layouts.app')

@section('title', $label)

{{--
    A strand's listing — §15.4 (Phase 9.4).

    The filter is a GET form submitting to this same page, so a search is a
    URL: shareable, bookmarkable, crawlable, and working with no JavaScript.
    The package finder does the same.

    Dates are filtered in the controller through the same Availability class
    the booking path uses, rather than in SQL. A faster query that
    eventually disagrees with the booking path about whether somewhere is
    free is the one disagreement this line cannot afford.
--}}
@section('content')
<div class="container mx-auto px-4 section-y-tight">
    <header class="mx-auto mb-8 max-w-2xl text-center">
        <h1 dir="auto" class="section-title">{{ $label }}</h1>
        <p dir="auto" class="text-brand-body">{{ $blurb }}</p>

        @unless($bookable)
            {{-- §15.2 decision 6: coming_soon shows the pages, takes
                 enquiries, and takes no money. Saying so plainly beats a
                 booking button that does nothing. --}}
            <p dir="auto" class="mt-4 inline-flex rounded-full bg-cream-deep px-4 py-2 text-sm text-ink">
                {{ __('messages.Booking opens soon. Ask us and we will hold something for you.') }}
            </p>
        @endunless
    </header>

    <form method="GET" action="{{ url()->current() }}"
          class="card mb-8 grid gap-4 p-5 sm:grid-cols-2 lg:grid-cols-5">
        <div>
            <label for="stays-island" class="mb-1 block text-sm font-medium text-ink">{{ __('messages.Island') }}</label>
            <select id="stays-island" name="island"
                    class="w-full rounded-lg border-cream-deep text-ink focus:border-wine-500 focus:ring-wine-500">
                <option value="">{{ __('messages.Any island') }}</option>
                @foreach($islands as $island)
                    <option value="{{ $island }}" @selected($filters->island === $island)>{{ $island }}</option>
                @endforeach
            </select>
        </div>

        <div>
            <label for="stays-from" class="mb-1 block text-sm font-medium text-ink">{{ __('messages.Check in') }}</label>
            <input id="stays-from" name="from" type="date" dir="ltr"
                   value="{{ $filters->checkIn?->toDateString() }}"
                   class="w-full rounded-lg border-cream-deep text-ink focus:border-wine-500 focus:ring-wine-500">
        </div>

        <div>
            <label for="stays-to" class="mb-1 block text-sm font-medium text-ink">{{ __('messages.Check out') }}</label>
            <input id="stays-to" name="to" type="date" dir="ltr"
                   value="{{ $filters->checkOut?->toDateString() }}"
                   class="w-full rounded-lg border-cream-deep text-ink focus:border-wine-500 focus:ring-wine-500">
        </div>

        <div>
            <label for="stays-guests" class="mb-1 block text-sm font-medium text-ink">{{ __('messages.Guests') }}</label>
            <input id="stays-guests" name="guests" type="number" min="1" max="30" inputmode="numeric" dir="ltr"
                   value="{{ $filters->guests }}"
                   class="w-full rounded-lg border-cream-deep text-ink focus:border-wine-500 focus:ring-wine-500">
        </div>

        <div class="flex items-end">
            <button type="submit" class="btn-primary w-full">{{ __('messages.Search') }}</button>
        </div>
    </form>

    @if($properties->isEmpty())
        <div class="card mx-auto max-w-xl text-center">
            <h2 dir="auto" class="mb-2 text-lg font-bold text-ink">{{ __('messages.Nothing free for those dates') }}</h2>
            <p dir="auto" class="mb-4 text-sm text-ink-muted">
                {{ __('messages.Try different dates, or ask us — we often have something that is not on the site yet.') }}
            </p>
            <a href="{{ route('contact') }}" class="btn-secondary">{{ __('messages.Ask us') }}</a>
        </div>
    @else
        <div class="grid gap-6 md:grid-cols-2 lg:grid-cols-3">
            @foreach($properties as $property)
                <article class="card flex flex-col overflow-hidden">
                    @if($property->cover_image)
                        <img src="{{ \Illuminate\Support\Facades\Storage::url($property->cover_image) }}"
                             alt="{{ $property->name }}"
                             width="640" height="420" loading="lazy" decoding="async"
                             class="mb-4 h-44 w-full rounded-xl object-cover">
                    @endif

                    <h2 dir="auto" class="text-lg font-bold text-ink">
                        <a href="{{ route('stays.show', ['property' => $property->slug] + $filters->toQuery()) }}"
                           class="hover:text-wine-700">{{ $property->name }}</a>
                    </h2>

                    @if($property->island)
                        <p dir="auto" class="text-sm text-ink-muted">{{ $property->island }}</p>
                    @endif

                    <p dir="auto" class="mt-2 grow text-sm text-ink-muted">{{ $property->summary }}</p>

                    @php($from = $property->cheapestRate())
                    @if($from)
                        <p dir="auto" class="mt-4 text-sm text-ink">
                            {{ __('messages.From :price a night', ['price' => $from->format()]) }}
                        </p>
                    @endif

                    <a href="{{ route('stays.show', ['property' => $property->slug] + $filters->toQuery()) }}"
                       class="btn-secondary mt-4 text-center">
                        {{ __('messages.See the rooms') }}
                    </a>
                </article>
            @endforeach
        </div>
    @endif
</div>
@endsection
