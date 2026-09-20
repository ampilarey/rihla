@component('pdf._document', [
    'title' => $payment->isRefund() ? __('messages.Refund') : __('messages.Receipt'),
    'number' => $payment->reference,
    'issuedOn' => $payment->reviewed_at ?? $payment->created_at,
    'issuer' => $issuer,
    'locale' => $locale,
])

<table class="parties">
    <tr>
        <td>
            <div class="muted">{{ __('messages.Received from') }}</div>
            <div><strong>{{ $payment->payer_name ?: $booking->customer->name }}</strong></div>
            <div class="muted ltr">{{ $booking->customer->phone }}</div>
        </td>
        <td>
            <div class="muted">{{ __('messages.For booking') }}</div>
            <div class="ltr"><strong>{{ $booking->reference }}</strong></div>
            <div class="muted">{{ $booking->departure->package->title }}</div>
        </td>
    </tr>
</table>

<table class="lines">
    <thead>
        <tr>
            <th>{{ __('messages.Description') }}</th>
            <th class="num">{{ __('messages.Amount') }}</th>
        </tr>
    </thead>
    <tbody>
        <tr>
            <td>
                {{ $payment->isRefund()
                    ? __('messages.Refund against booking :reference', ['reference' => $booking->reference])
                    : __('messages.Payment towards booking :reference', ['reference' => $booking->reference]) }}
                <div class="muted">{{ \App\Support\PortalWords::paymentStatus($payment->status) }}</div>
                @if($payment->payer_reference)
                    <div class="muted ltr">{{ $payment->payer_reference }}</div>
                @endif
            </td>
            <td class="num ltr">{{ $payment->money()->format() }}</td>
        </tr>
    </tbody>
</table>

<table class="totals">
    <tr class="grand">
        <td>{{ $payment->isRefund() ? __('messages.Refunded') : __('messages.Received') }}</td>
        <td class="num ltr">{{ $payment->money()->format() }}</td>
    </tr>
    <tr>
        <td>{{ __('messages.Balance on the booking') }}</td>
        <td class="num ltr">{{ $booking->balance()->format() }}</td>
    </tr>
</table>

@endcomponent
