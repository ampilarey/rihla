@component('pdf._document', [
    'title' => 'Statement',
    'number' => $statement->reference,
    'issuedOn' => $statement->issued_at,
    'issuer' => $issuer,
    'locale' => 'en',
])

{{--
    A host's monthly statement — §16.9. Rihla's document to the host, in
    Rihla's name, from the figures frozen on the day it was issued. `Brand`
    colours only, through the shared shell: a host's colours never enter a
    PDF.
--}}
<table class="parties">
    <tr>
        <td>
            <div class="muted">To</div>
            <div><strong>{{ $host->name }}</strong></div>
            @if($host->registration_number)
                <div class="muted ltr">{{ $host->registration_number }}</div>
            @endif
        </td>
        <td>
            <div class="muted">Period</div>
            <div><strong>{{ $statement->period_start->format('j M Y') }} – {{ $statement->period_end->format('j M Y') }}</strong></div>
            <div class="muted">Stays that ended in the period, in {{ $statement->currency }}</div>
        </td>
    </tr>
</table>

<table class="lines">
    <thead>
        <tr>
            <th>Booked through Rihla ({{ $statement->marketplace_count }})</th>
            <th class="num">Amount</th>
        </tr>
    </thead>
    <tbody>
        <tr><td>Gross</td><td class="num ltr">{{ $statement->money('gross_minor')->format() }}</td></tr>
        <tr><td>Rihla's commission</td><td class="num ltr">{{ $statement->money('commission_minor')->format() }}</td></tr>
        <tr><td>Yours</td><td class="num ltr">{{ $statement->money('net_minor')->format() }}</td></tr>
    </tbody>
</table>

<table class="lines">
    <thead>
        <tr>
            <th>Where the money is</th>
            <th class="num">Amount</th>
        </tr>
    </thead>
    <tbody>
        <tr><td>Paid online to Rihla</td><td class="num ltr">{{ $statement->money('paid_to_rihla_minor')->format() }}</td></tr>
        <tr><td>Paid to you at the property</td><td class="num ltr">{{ $statement->money('paid_here_minor')->format() }}</td></tr>
    </tbody>
</table>

<table class="totals">
    <tr class="grand">
        <td>Rihla holds for you</td>
        <td class="num ltr">{{ $statement->money('rihla_holds_minor')->format() }}</td>
    </tr>
    <tr>
        <td>Commission still to settle with Rihla</td>
        <td class="num ltr">{{ $statement->money('commission_outstanding_minor')->format() }}</td>
    </tr>
</table>

<p>Your own bookings this period ({{ $statement->direct_count }}): {{ $statement->money('direct_gross_minor')->format() }} — no commission, shown for your records.</p>

@endcomponent
