@component('pdf._document', [
    'title' => __('messages.Stay confirmation'),
    'number' => $stay->reference,
    'issuedOn' => now(),
    'issuer' => $issuer,
    'locale' => $locale,
])

{{--
    A guest's stay, on paper — §16.7. What the guest agreed to, read from
    the stay's own snapshot: the prices, the deposit, the cancellation terms
    and the Green Tax as they were on the day, not as the property says now.

    Generated on demand and never stored, as the invoice is. No tax line and
    no terms document, because nobody has stated either (§16.16).
--}}
<table class="parties">
    <tr>
        <td>
            <div class="muted">{{ __('messages.Guest') }}</div>
            <div><strong>{{ $stay->customer->name }}</strong></div>
            <div class="muted ltr">{{ $stay->customer->phone }}</div>
        </td>
        <td>
            <div class="muted">{{ __('messages.Where') }}</div>
            <div><strong>{{ $stay->property->name }}</strong></div>
            <div class="muted">{{ collect([$stay->property->island, $stay->property->atoll])->filter()->implode(', ') }}</div>
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
                {{ $stay->roomType?->name }}
                <div class="muted">
                    {{ $stay->check_in->isoFormat('D MMM YYYY') }} – {{ $stay->check_out->isoFormat('D MMM YYYY') }},
                    {{ trans_choice('messages.:count night|:count nights', $stay->nights, ['count' => $stay->nights]) }}
                </div>
            </td>
            <td class="num ltr">{{ $stay->total()->format() }}</td>
        </tr>
        @foreach($stay->charges as $charge)
            <tr>
                <td>{{ $charge->description }}</td>
                <td class="num ltr">{{ $charge->total()->format() }}</td>
            </tr>
        @endforeach
    </tbody>
</table>

<table class="totals">
    <tr>
        <td>{{ __('messages.Deposit') }}</td>
        <td class="num ltr">{{ $stay->deposit()->format() }}</td>
    </tr>
    <tr>
        <td>{{ __('messages.Received') }}</td>
        <td class="num ltr">{{ $stay->paid()->format() }}</td>
    </tr>
    <tr class="grand">
        <td>{{ __('messages.Still to pay') }}</td>
        <td class="num ltr">{{ $stay->outstanding()->format() }}</td>
    </tr>
</table>

<p>
    {{ __('messages.Cancel more than :days days before and the deposit comes back.', ['days' => $policy['free_cancel_days'] ?? 14]) }}
    {{ __('messages.The rest is due :days days before you arrive.', ['days' => $policy['balance_days_before'] ?? 14]) }}
</p>

{{-- The Green Tax frozen on the day: whether it applied to this guest,
     where it is paid, and what it came to — never re-read from config. --}}
@if($greenTax['applies'] ?? false)
    <p>
        @if(($greenTax['mode'] ?? null) === \App\Models\Partner::GREEN_TAX_INCLUDED)
            {{ __('messages.Green tax is already included in this price.') }}
        @else
            {{ __('messages.Green tax is paid at the guesthouse, not here.') }}
            @if(($greenTax['total_minor'] ?? null) !== null && ($greenTax['currency'] ?? null) !== null)
                {{ __('messages.For your dates and party: :amount.', ['amount' => \App\Support\Money::ofMinor((int) $greenTax['total_minor'], (string) $greenTax['currency'])->format()]) }}
            @endif
        @endif
    </p>
@endif

@if($stay->property->check_in_time)
    <p>{{ __('messages.Check-in from :time.', ['time' => substr((string) $stay->property->check_in_time, 0, 5)]) }}</p>
@endif

@if(filled($stay->property->check_in_instructions))
    <p>{{ $stay->property->check_in_instructions }}</p>
@endif

@endcomponent
