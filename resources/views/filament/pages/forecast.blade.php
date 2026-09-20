<x-filament-panels::page>
    {{-- Filament components only: Tailwind utilities do nothing in /staff. --}}
    @php($forecasts = $this->getForecasts())

    <x-filament::section>
        <x-slot name="heading">How this is worked out</x-slot>

        <x-slot name="description">
            At any point before a departure, some fraction of its eventual seats has been sold. Past
            journeys say what that fraction usually is this far out; dividing what is sold today by
            that fraction projects where this one lands. The range is the most and least front-loaded of
            those past journeys. Note which way round that goes: a journey that had sold most of
            its seats by this point front-loaded, so this one — at the same seat count — projects
            <em>low</em> against it. The range is not a statistical band; it is journeys that
            actually happened.
        </x-slot>

        <x-filament::callout
            color="gray"
            icon="heroicon-o-hand-raised"
            heading="It refuses more often than it answers, on purpose"
            description="A projection needs at least {{ $this->minimumJourneys() }} comparable journeys behind it. Below that it says so rather than giving a number: a seat forecast is something somebody charters an aircraft on, and bookings brought in from the old spreadsheets carry the import's dates rather than the day anybody actually booked."
        />
    </x-filament::section>

    @forelse ($forecasts as $forecast)
        <x-filament::section>
            <x-slot name="heading">
                {{ $forecast->departure->package->title ?? 'Departure' }} —
                {{ $forecast->departure->date_start->format('j M Y') }}
            </x-slot>

            {{-- `afterHeader`, not `headerEnd`: Blade discards a slot the
                 component does not declare, without a word. --}}
            <x-slot name="afterHeader">
                <x-filament::badge :color="$forecast->tone()">
                    {{ $forecast->soldNow }} of {{ $forecast->capacity }} sold
                </x-filament::badge>
            </x-slot>

            <x-slot name="description">
                {{ $forecast->daysToGo }} {{ $forecast->daysToGo === 1 ? 'day' : 'days' }} to go ·
                {{ $forecast->seatsLeft() }} {{ $forecast->seatsLeft() === 1 ? 'seat' : 'seats' }} left
            </x-slot>

            @if ($forecast->hasProjection())
                <x-filament::callout
                    :color="$forecast->tone()"
                    icon="heroicon-o-arrow-trending-up"
                    :heading="$forecast->low === $forecast->high
                        ? $forecast->low . ' seats'
                        : $forecast->low . '–' . $forecast->high . ' seats'"
                    :description="$forecast->spoken() . ' ' . $forecast->advice()"
                />
            @else
                {{-- Named rather than silent. A departure with no row here
                     would read as one nobody has looked at. --}}
                <x-filament::callout
                    color="gray"
                    icon="heroicon-o-question-mark-circle"
                    heading="No projection for this one"
                    :description="$forecast->because"
                />
            @endif
        </x-filament::section>
    @empty
        <x-filament::section>
            <x-slot name="heading">Nothing is selling</x-slot>

            <x-filament::callout
                color="gray"
                icon="heroicon-o-calendar"
                heading="No departure is still ahead of us"
                description="This screen forecasts departures that have not flown. Add one, and it appears here."
            />
        </x-filament::section>
    @endforelse
</x-filament-panels::page>
