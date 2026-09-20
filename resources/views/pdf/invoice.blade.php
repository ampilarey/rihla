@php
    $balance = $booking->balance();
@endphp

@component('pdf._document', [
    'title' => __('messages.Invoice'),
    'number' => $booking->reference,
    'issuedOn' => now(),
    'issuer' => $issuer,
    'locale' => $locale,
])

<table class="parties">
    <tr>
        <td>
            <div class="muted">{{ __('messages.Billed to') }}</div>
            <div><strong>{{ $booking->customer->name }}</strong></div>
            <div class="muted ltr">{{ $booking->customer->phone }}</div>
            @if($booking->customer->email)
                <div class="muted ltr">{{ $booking->customer->email }}</div>
            @endif
        </td>
        <td>
            <div class="muted">{{ __('messages.Package') }}</div>
            <div><strong>{{ $booking->departure->package->title }}</strong></div>
            <div class="muted ltr">
                {{ $booking->departure->date_start->format('j M Y') }}
                &ndash;
                {{ $booking->departure->date_end->format('j M Y') }}
            </div>
        </td>
    </tr>
</table>

{{--
    The lines as they were agreed. `booking_lines` is the record; the total
    on the booking is a cached sum of it, so this reads the lines rather
    than describing the total.

    A booking with no lines is possible — one taken over the phone before
    anything was itemised — and says so rather than printing an empty table
    that looks like a rendering failure.
--}}
@if($booking->lines->isNotEmpty())
    <table class="lines">
        <thead>
            <tr>
                <th>{{ __('messages.Description') }}</th>
                <th class="num">{{ __('messages.Quantity') }}</th>
                <th class="num">{{ __('messages.Amount') }}</th>
            </tr>
        </thead>
        <tbody>
            @foreach($booking->lines as $line)
                <tr>
                    <td>{{ $line->description }}</td>
                    <td class="num ltr">{{ $line->quantity }}</td>
                    <td class="num ltr">{{ $line->money()->format() }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>
@else
    <p class="muted">
        {{ __('messages.This booking was agreed as a single amount. Message us if you need it broken down.') }}
    </p>
@endif

<table class="totals">
    <tr>
        <td>{{ __('messages.Total') }}</td>
        <td class="num ltr">{{ $booking->total()->format() }}</td>
    </tr>
    <tr>
        <td>{{ __('messages.Paid') }}</td>
        <td class="num ltr">{{ $booking->paid()->format() }}</td>
    </tr>
    <tr class="grand">
        <td>{{ __('messages.Balance') }}</td>
        <td class="num ltr">{{ $balance->format() }}</td>
    </tr>
</table>

{{-- Only payments somebody has checked. A claim nobody has verified is not
     a payment, and an invoice that counted one would tell a customer their
     money had arrived before anybody looked. --}}
@if($payments->isNotEmpty())
    <table class="lines" style="margin-top: 20px;">
        <thead>
            <tr>
                <th>{{ __('messages.Payments received') }}</th>
                <th class="num">{{ __('messages.Date') }}</th>
                <th class="num">{{ __('messages.Amount') }}</th>
            </tr>
        </thead>
        <tbody>
            @foreach($payments as $payment)
                <tr>
                    <td class="ltr">{{ $payment->reference }}</td>
                    <td class="num ltr">{{ optional($payment->paid_at ?? $payment->created_at)->format('j M Y') }}</td>
                    <td class="num ltr">{{ $payment->money()->format() }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>
@endif

@if($balance->minor > 0)
    <div class="note">
        {{ __('messages.Quote :reference when you pay, so we can match it to your booking.', ['reference' => $booking->reference]) }}
    </div>
@endif

@endcomponent
