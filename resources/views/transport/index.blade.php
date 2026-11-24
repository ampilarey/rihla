@extends('layouts.app')

@section('title', __('messages.Boats between islands'))

{{-- Boats — docs/TRANSPORT_PLAN.md (T2). A server-rendered search that works with no JavaScript. --}}
@section('content')
<div class="container mx-auto max-w-3xl px-4 section-y-tight">
    <header class="mb-6">
        <h1 dir="auto" class="section-title text-start">{{ __('messages.Boats between islands') }}</h1>
        <p dir="auto" class="text-brand-body">{{ __('messages.Choose where you are going and when. Seats are held for :minutes minutes while you pay.', ['minutes' => (int) config('transport.online_hold_minutes', 60)]) }}</p>
    </header>

    @unless($selling)
        <div role="status" dir="auto" class="card mb-6 border-s-4 border-s-gold">
            {{ __('messages.Online booking for boats is coming soon. Here is the timetable; call us to book a seat.') }}
        </div>
    @endunless

    <form method="GET" action="{{ route('transport.index') }}" class="card mb-6 grid gap-4 sm:grid-cols-4">
        <div>
            <label for="boat-from" class="mb-1 block text-sm font-medium text-ink">{{ __('messages.From') }}</label>
            <select id="boat-from" name="from" class="w-full rounded-lg border border-gray-500 px-3 py-2 text-ink">
                <option value="">{{ __('messages.Anywhere') }}</option>
                @foreach($origins as $id => $name)
                    <option value="{{ $id }}" @selected($from === $id)>{{ $name }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label for="boat-to" class="mb-1 block text-sm font-medium text-ink">{{ __('messages.To') }}</label>
            <select id="boat-to" name="to" class="w-full rounded-lg border border-gray-500 px-3 py-2 text-ink">
                <option value="">{{ __('messages.Anywhere') }}</option>
                @foreach($destinations as $id => $name)
                    <option value="{{ $id }}" @selected($to === $id)>{{ $name }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label for="boat-date" class="mb-1 block text-sm font-medium text-ink">{{ __('messages.From date') }}</label>
            <input id="boat-date" name="date" type="date" value="{{ $date->toDateString() }}" min="{{ now()->toDateString() }}"
                   class="w-full rounded-lg border border-gray-500 px-3 py-2 text-ink">
        </div>
        <div class="flex items-end">
            <button type="submit" class="btn-primary w-full">{{ __('messages.Search') }}</button>
        </div>
    </form>

    <p dir="auto" class="mb-6 text-sm text-brand-body">
        {{ __('messages.Need cargo moved or a whole boat?') }}
        <a href="{{ route('transport.requests.create') }}" class="text-wine-700 underline hover:no-underline">{{ __('messages.Ask for a price') }}</a>
    </p>

    @if($sailings->isEmpty())
        <p dir="auto" class="card text-brand-body">{{ __('messages.No sailings in the week from that date. Try another date or route.') }}</p>
    @else
        <ul class="space-y-3">
            @foreach($sailings as $sailing)
                <li class="card flex flex-wrap items-center justify-between gap-4">
                    <div dir="auto">
                        <p class="font-bold text-ink">{{ $sailing->route->label() }}</p>
                        <p class="text-sm text-ink-muted">
                            {{ $sailing->departs_at->isoFormat('ddd D MMM, HH:mm') }}
                            · {{ trans_choice('messages.:count minute|:count minutes', $sailing->route->duration_minutes, ['count' => $sailing->route->duration_minutes]) }}
                            · {{ $sailing->vessel->name }}
                        </p>
                        <p class="text-sm text-ink">
                            {{ __('messages.Adult :amount', ['amount' => $sailing->route->money($sailing->route->adult_fare_minor)->format()]) }}
                            @if($sailing->route->child_fare_minor !== null)
                                · {{ __('messages.Child :amount', ['amount' => $sailing->route->money($sailing->route->child_fare_minor)->format()]) }}
                            @endif
                        </p>
                    </div>
                    <div class="text-end">
                        @if($sailing->seatsLeft() === 0)
                            <span dir="auto" class="text-sm font-medium text-ink-muted">{{ __('messages.Full') }}</span>
                        @else
                            <p dir="auto" class="mb-2 text-sm text-ink-muted">{{ trans_choice('messages.:count seat left|:count seats left', $sailing->seatsLeft(), ['count' => $sailing->seatsLeft()]) }}</p>
                            @if($selling)
                                <a href="{{ route('transport.book', ['sailing' => $sailing->getKey()]) }}" class="btn-primary">{{ __('messages.Book seats') }}</a>
                            @endif
                        @endif
                    </div>
                </li>
            @endforeach
        </ul>
    @endif
</div>
@endsection
