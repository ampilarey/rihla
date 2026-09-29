@component('pdf._document', [
    'title' => 'Bill',
    'number' => $stay->reference,
    'issuedOn' => now(),
    'issuer' => $issuer,
    'locale' => 'en',
    'footer' => $footer,
])

{{--
    The host's bill at check-out — §16.10. Issued by the host, in the host's
    name and registration, with Rihla's line in the footer; `Brand` colours
    only, through the shared shell, because a host's own colours never
    enter a PDF. English only for now: the host panel is in English, and
    this is the host's document before it is the guest's.

    Generated on demand and never stored.
--}}
<table class="parties">
    <tr>
        <td>
            <div class="muted">Guest</div>
            <div><strong>{{ $stay->customer->name }}</strong></div>
            <div class="muted ltr">{{ $stay->customer->phone }}</div>
        </td>
        <td>
            <div class="muted">Stay</div>
            <div><strong>{{ $stay->property->name }}</strong></div>
            <div class="muted">
                {{ $stay->check_in->format('j M Y') }} – {{ $stay->check_out->format('j M Y') }}
                @if($stay->unit) · {{ $stay->unit->label }} @endif
            </div>
        </td>
    </tr>
</table>

<table class="lines">
    <thead>
        <tr>
            <th>Description</th>
            <th class="num">Amount</th>
        </tr>
    </thead>
    <tbody>
        @foreach($bill->lines() as $line)
            <tr>
                <td>{{ $line['description'] }}</td>
                <td class="num ltr">{{ $line['total']->format() }}</td>
            </tr>
        @endforeach
    </tbody>
</table>

<table class="totals">
    <tr>
        <td>Total</td>
        <td class="num ltr">{{ $bill->total()->format() }}</td>
    </tr>
    @foreach($stay->payments->where('status', \App\Models\Payment::SUCCEEDED) as $payment)
        <tr>
            <td>
                {{ $payment->amount_minor < 0 ? 'Refunded' : 'Paid' }}
                {{ $payment->collected_by === \App\Models\Payment::COLLECTED_BY_HOST ? 'here' : 'online, to Rihla' }}
                · {{ $payment->paid_at?->format('j M Y') }}
            </td>
            <td class="num ltr">{{ \App\Support\Money::ofMinor(-$payment->amount_minor, $payment->currency)->format() }}</td>
        </tr>
    @endforeach
    <tr class="grand">
        <td>{{ $bill->balance()->minor < 0 ? 'In credit' : 'Balance' }}</td>
        <td class="num ltr">{{ \App\Support\Money::ofMinor(abs($bill->balance()->minor), $stay->currency)->format() }}</td>
    </tr>
</table>

@foreach($bill->notes() as $note)
    <p>{{ $note }}</p>
@endforeach

@endcomponent
