@extends('layouts.app')

@section('title', __('messages.Book seats on :route', ['route' => $sailing->route->label()]))

{{-- Naming the passengers — docs/TRANSPORT_PLAN.md (T2). Works with no JavaScript: ten rows, blank ones are ignored. --}}
@section('content')
<div class="container mx-auto max-w-3xl px-4 section-y-tight">
    <header class="mb-6">
        <p dir="auto" class="text-sm text-ink-muted">
            <a href="{{ route('transport.index') }}" class="text-wine-700 underline hover:no-underline">{{ __('messages.Boats between islands') }}</a>
        </p>
        <h1 dir="auto" class="section-title text-start">{{ $sailing->route->label() }}</h1>
        <p dir="auto" class="text-brand-body">
            {{ $sailing->departs_at->isoFormat('dddd D MMMM YYYY, HH:mm') }} · {{ $sailing->vessel->name }}
            · {{ trans_choice('messages.:count seat left|:count seats left', $sailing->seatsLeft(), ['count' => $sailing->seatsLeft()]) }}
        </p>
    </header>

    <form method="POST" action="{{ route('transport.book.store', ['sailing' => $sailing->getKey()]) }}" class="card space-y-4" novalidate>
        @csrf

        @if($errors->any())
            <div role="alert" class="rounded-xl border-s-4 border-s-error bg-cream-deep px-4 py-3 text-sm text-ink">
                <ul class="list-disc ps-5">
                    @foreach($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <h2 dir="auto" class="text-lg font-bold text-ink">{{ __('messages.Passengers') }}</h2>
        <p dir="auto" class="text-sm text-ink-muted">
            {{ __('messages.Adult :amount', ['amount' => $sailing->route->money($sailing->route->adult_fare_minor)->format()]) }}
            @if($sailing->route->child_fare_minor !== null)
                · {{ __('messages.Child :amount', ['amount' => $sailing->route->money($sailing->route->child_fare_minor)->format()]) }}
            @endif
            · {{ __('messages.Leave the rows you do not need empty.') }}
        </p>

        {{-- Three rows to start; the rest folded away so a phone is not handed
             ten empty boxes. A <details>, so it needs no JavaScript, and it opens
             itself when the form comes back with a later row filled in. --}}
        @php($rows = min(10, max(1, $sailing->seatsLeft())))
        @php($moreTyped = collect(range(3, 9))->contains(fn ($n) => filled(old('passengers.'.$n.'.full_name'))))
        @for($i = 0; $i < min(3, $rows); $i++)
            @include('transport._passenger', ['i' => $i])
        @endfor
        @if($rows > 3)
            <details class="space-y-4" @if($moreTyped) open @endif>
                <summary dir="auto" class="cursor-pointer text-sm font-medium text-wine-700 underline">{{ __('messages.More passengers') }}</summary>
                @for($i = 3; $i < $rows; $i++)
                    @include('transport._passenger', ['i' => $i])
                @endfor
            </details>
        @endif

        <h2 dir="auto" class="pt-2 text-lg font-bold text-ink">{{ __('messages.Your details') }}</h2>
        <div class="grid gap-4 sm:grid-cols-2">
            <div>
                <label for="boat-contact-name" class="mb-1 block text-sm font-medium text-ink">{{ __('messages.Your name') }}</label>
                <input id="boat-contact-name" name="contact_name" type="text" required maxlength="120" autocomplete="name" dir="auto"
                       value="{{ old('contact_name') }}" class="w-full rounded-lg border border-gray-500 px-3 py-2 text-ink">
            </div>
            <div>
                <label for="boat-contact-phone" class="mb-1 block text-sm font-medium text-ink">{{ __('messages.Phone or WhatsApp') }}</label>
                <input id="boat-contact-phone" name="contact_phone" type="tel" required maxlength="30" autocomplete="tel" dir="ltr"
                       value="{{ old('contact_phone') }}" class="w-full rounded-lg border border-gray-500 px-3 py-2 text-ink">
            </div>
        </div>

        {{-- A field no human sees. --}}
        <div class="hidden" aria-hidden="true">
            <label for="boat-website">Website</label>
            <input id="boat-website" name="website" type="text" tabindex="-1" autocomplete="off">
        </div>

        <button type="submit" class="btn-primary w-full">{{ __('messages.Hold these seats') }}</button>
    </form>
</div>
@endsection
