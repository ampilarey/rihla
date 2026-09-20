{{--
    Who a broadcast reached, and who it did not.

    No Tailwind utility classes: the panel has no custom theme. This is the
    answer to the question that gets asked after an incident, which is why
    every attempt is written down rather than only the successes.
--}}
<div>
    <x-filament::callout
        :color="$broadcast->reached() > 0 ? 'success' : 'danger'"
        :icon="$broadcast->reached() > 0 ? 'heroicon-o-check-circle' : 'heroicon-o-exclamation-triangle'"
        :heading="trans_choice('{0}It reached nobody|{1}It reached :count booking|[2,*]It reached :count bookings', $broadcast->reached(), ['count' => $broadcast->reached()])"
        description="A channel that is not set up is listed below with the reason, so the people it could not reach can be phoned."
    />

    @foreach ($deliveries as $channel => $rows)
        <x-filament::section :heading="$rows->first()->channelLabel()" compact>
            @foreach ($rows as $delivery)
                <p>
                    <strong>{{ $delivery->booking->customer->name ?? $delivery->booking->reference }}</strong>
                    — {{ $delivery->statusLabel() }}@if ($delivery->detail): {{ $delivery->detail }}@endif
                </p>
            @endforeach
        </x-filament::section>
    @endforeach
</div>
