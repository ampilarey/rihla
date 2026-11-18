@component('pdf._document', [
    'title' => 'Invoice',
    'number' => $excursionBooking->reference,
    'issuedOn' => now(),
    'issuer' => $issuer,
    'locale' => 'en',
])

{{--
    One family's places on an additional trip (companies plan, Phase 3e):
    its own invoice, in the trip's currency, because it is its own payable.
    English only for now, as the guesthouse bill is: the riyal is paid in
    Saudi Arabia, to the tour leader or the office there. `Brand` colours
    only, through the shared shell. Generated on demand and never stored.
--}}
<table class="parties">
    <tr>
        <td>
            <div class="muted">Billed to</div>
            <div><strong>{{ $excursionBooking->customer->name }}</strong></div>
            <div class="muted ltr">{{ $excursionBooking->customer->phone }}</div>
        </td>
        <td>
            <div class="muted">Additional trip</div>
            <div><strong>{{ $excursionBooking->excursion->title }}</strong></div>
            <div class="muted">
                @if($excursionBooking->excursion->scheduled_on)
                    {{ $excursionBooking->excursion->scheduled_on->format('j M Y') }} ·
                @endif
                Booking {{ $excursionBooking->booking->reference }}
            </div>
        </td>
    </tr>
</table>

<table class="lines">
    <thead>
        <tr>
            <th>Traveller</th>
            <th class="num">Amount</th>
        </tr>
    </thead>
    <tbody>
        @foreach($excursionBooking->participants as $participant)
            <tr>
                <td>{{ $participant->bookingTraveller->traveller->full_name }}</td>
                <td class="num ltr">{{ $participant->amount_minor === 0 ? 'Included' : \App\Support\Money::ofMinor($participant->amount_minor, $excursionBooking->currency)->format() }}</td>
            </tr>
        @endforeach
    </tbody>
</table>

<table class="totals">
    <tr>
        <td>Total</td>
        <td class="num ltr">{{ $excursionBooking->total()->format() }}</td>
    </tr>
    <tr>
        <td>Paid</td>
        <td class="num ltr">{{ $excursionBooking->paid()->format() }}</td>
    </tr>
    <tr class="grand">
        <td>Balance</td>
        <td class="num ltr">{{ $excursionBooking->balance()->format() }}</td>
    </tr>
</table>

@if($excursionBooking->status === \App\Models\ExcursionBooking::CANCELLED)
    <p><strong>These places were cancelled.</strong></p>
@endif

@endcomponent
