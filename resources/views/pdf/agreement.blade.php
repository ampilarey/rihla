@component('pdf._document', [
    'title' => 'Agreement',
    'number' => $booking->reference,
    'issuedOn' => $agreement->signed_at ?? now(),
    'issuer' => $issuer,
    'locale' => 'en',
])

{{--
    The booking agreement as signed (companies plan, Phase 3h, part 3): the
    wording frozen at signing, never today's terms, and how it was signed.
--}}
<table class="parties">
    <tr>
        <td>
            <div class="muted">Customer</div>
            <div><strong>{{ $booking->customer->name }}</strong></div>
        </td>
        <td>
            <div class="muted">Booking</div>
            <div class="ltr"><strong>{{ $booking->reference }}</strong></div>
            <div class="muted">{{ $booking->departure->package->title ?? '' }}</div>
        </td>
    </tr>
</table>

<div style="white-space: pre-line;">{{ $agreement->wording }}</div>

<div class="note">
    @if($agreement->method === \App\Models\Agreement::PAPER)
        Signed on paper; the scan is kept with the booking's documents. Recorded {{ $agreement->signed_at?->format('j M Y, H:i') }}.
    @else
        Signed on screen by <strong>{{ $agreement->signed_name }}</strong> on {{ $agreement->signed_at?->format('j M Y, H:i') }}.
        @if($agreement->signature)
            <div><img src="{{ $agreement->signature }}" alt="Signature" style="height: 60px;"></div>
        @endif
    @endif
</div>

@endcomponent
