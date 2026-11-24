@extends('layouts.app')

@section('title', __('messages.Your boat tickets'))

{{--
    "My tickets" — docs/TRANSPORT_PLAN.md (T2). Reached only by the random
    link the booking hands back, so it carries no sign-in and no personal
    data beyond what the booker typed. One QR per passenger for the crew.
--}}
@section('content')
<div class="container mx-auto max-w-3xl px-4 section-y-tight">
    <header class="mb-6">
        <h1 dir="auto" class="section-title text-start">{{ __('messages.Your boat tickets') }}</h1>
        <p dir="auto" class="text-brand-body">
            {{ $booking->sailing->route->label() }} · {{ $booking->sailing->departs_at->isoFormat('dddd D MMMM YYYY, HH:mm') }}
        </p>
        <p dir="auto" class="mt-1 text-sm text-ink-muted">{{ __('messages.Keep this page: its address is your key to these tickets.') }}</p>
    </header>

    <section class="card mb-6" aria-labelledby="boat-booking-heading">
        <h2 id="boat-booking-heading" dir="auto" class="mb-3 text-lg font-bold text-ink">{{ __('messages.Booking') }}</h2>
        <dl dir="auto" class="space-y-2 text-sm text-ink">
            <div class="flex justify-between gap-4"><dt class="text-ink-muted">{{ __('messages.Reference') }}</dt><dd class="text-end font-medium" dir="ltr">{{ $booking->reference }}</dd></div>
            <div class="flex justify-between gap-4"><dt class="text-ink-muted">{{ __('messages.Status') }}</dt><dd class="text-end font-medium">{{ __('messages.'.\Modules\Transport\Models\BoatBooking::STATUSES[$booking->status]) }}</dd></div>
            <div class="flex justify-between gap-4"><dt class="text-ink-muted">{{ __('messages.Total') }}</dt><dd class="text-end">{{ $booking->total()->format() }}</dd></div>
            @if($booking->status === \Modules\Transport\Models\BoatBooking::HELD && $booking->held_until)
                <div class="flex justify-between gap-4"><dt class="text-ink-muted">{{ __('messages.Held until') }}</dt><dd class="text-end">{{ $booking->held_until->isoFormat('D MMM, HH:mm') }}</dd></div>
            @endif
        </dl>

        @if($booking->status === \Modules\Transport\Models\BoatBooking::HELD)
            <p dir="auto" class="mt-4 font-medium text-ink">{{ __('messages.To pay now: :amount', ['amount' => $booking->owed()->format()]) }}</p>
            @if($transfer !== null)
                <div dir="auto" class="mt-4 rounded-xl border border-gray-300 bg-cream p-4">
                    <h3 class="mb-2 font-semibold text-ink">{{ __('messages.Paying by bank transfer') }}</h3>
                    <dl class="space-y-1 text-sm text-ink">
                        @if($transfer['bank'])
                            <div class="flex justify-between gap-4"><dt class="text-ink-muted">{{ __('messages.Bank') }}</dt><dd class="text-end font-medium">{{ $transfer['bank'] }}</dd></div>
                        @endif
                        @if($transfer['account_name'])
                            <div class="flex justify-between gap-4"><dt class="text-ink-muted">{{ __('messages.Account name') }}</dt><dd class="text-end font-medium">{{ $transfer['account_name'] }}</dd></div>
                        @endif
                        <div class="flex justify-between gap-4"><dt class="text-ink-muted">{{ __('messages.Account number') }}</dt><dd class="text-end font-medium" dir="ltr">{{ $transfer['account'] }}</dd></div>
                        <div class="flex justify-between gap-4"><dt class="text-ink-muted">{{ __('messages.Reference') }}</dt><dd class="text-end font-medium" dir="ltr">{{ $booking->reference }}</dd></div>
                    </dl>
                </div>
            @endif
            <p dir="auto" class="mt-3 text-sm text-ink-muted">{{ __('messages.Pay before the hold ends, or pay cash at our office; we confirm as soon as the money arrives. Message us with your reference if you need more time.') }}</p>
        @endif
    </section>

    @if(in_array($booking->status, \Modules\Transport\Models\BoatBooking::HOLDS_SEATS, true))
        <ul class="grid gap-4 sm:grid-cols-2">
            @foreach($booking->tickets as $ticket)
                <li class="card text-center">
                    <p dir="auto" class="font-bold text-ink">{{ $ticket->full_name }}</p>
                    <p dir="auto" class="mb-3 text-sm text-ink-muted">{{ $ticket->is_child ? __('messages.Child') : __('messages.Adult') }}</p>
                    <img src="{{ $qr[$ticket->id] }}" alt="{{ __('messages.Boarding code for :name', ['name' => $ticket->full_name]) }}" width="240" height="240" class="mx-auto">
                </li>
            @endforeach
        </ul>
    @else
        <p dir="auto" class="card text-brand-body">{{ __('messages.This booking no longer holds seats, so its tickets are not valid.') }}</p>
    @endif
</div>
@endsection
