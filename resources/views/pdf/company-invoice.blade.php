@component('pdf._document', [
    'title' => 'Invoice',
    'number' => $invoice->number,
    'issuedOn' => $invoice->issued_on,
    'issuer' => $issuer,
    'locale' => 'en',
])

{{--
    Rihla's invoice to an Umrah company (billing automation). Rihla's own
    document, so Rihla's name and Rihla's account — the one place a company
    is ever shown Rihla's bank details, because here Rihla is the one being
    paid. `Brand` colours only, through the shared shell.
--}}
<table class="parties">
    <tr>
        <td>
            <div class="muted">To</div>
            <div><strong>{{ $company?->name }}</strong></div>
            @if($company?->registration_number)
                <div class="muted ltr">{{ $company->registration_number }}</div>
            @endif
            @if($company?->address)
                <div class="muted">{{ $company->address }}</div>
            @endif
        </td>
        <td>
            <div class="muted">Due</div>
            <div><strong>{{ $invoice->due_on->format('j M Y') }}</strong></div>
            <div class="muted">{{ $invoice->stateLabel() }}</div>
        </td>
    </tr>
</table>

<table class="lines">
    <thead>
        <tr>
            <th>For</th>
            <th class="num">Amount</th>
        </tr>
    </thead>
    <tbody>
        <tr>
            <td>
                {{ $invoice->description() }}
                @if($invoice->period_start)
                    <div class="muted">{{ $invoice->period_start->format('j M Y') }} – {{ $invoice->period_end?->format('j M Y') }}</div>
                @endif
            </td>
            <td class="num ltr">{{ $invoice->money()->format() }}</td>
        </tr>
    </tbody>
</table>

<table class="totals">
    <tr class="grand">
        <td>Total</td>
        <td class="num ltr">{{ $invoice->money()->format() }}</td>
    </tr>
</table>

@if($invoice->isOutstanding())
    <div class="note">
        Pay by bank transfer and quote <strong class="ltr">{{ $invoice->number }}</strong> as the reference.
        @if($bank['account'])
            <br>{{ $bank['name'] }} · {{ $bank['account_name'] }} · <span class="ltr">{{ $bank['account'] }}</span> ({{ $invoice->currency }})
        @else
            <br>Ask Rihla for the account to pay into.
        @endif
    </div>
@endif

@endcomponent
