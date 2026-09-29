{{-- Reports — §16.10. Filament components only; no Tailwind utilities in a panel. --}}
<x-filament-panels::page>
    @php([$from, $until] = $this->range())
    @php($report = $this->report())

    <x-filament::section heading="Dates">
        <form wire:submit.prevent="$refresh">
            <x-filament::input.wrapper>
                <x-filament::input type="date" wire:model.live="from" aria-label="From" />
            </x-filament::input.wrapper>
            <x-filament::input.wrapper>
                <x-filament::input type="date" wire:model.live="until" aria-label="Until" />
            </x-filament::input.wrapper>
        </form>
        <x-slot name="footer">
            <x-filament::badge color="gray">{{ $from->format('j M Y') }} – {{ $until->format('j M Y') }} · {{ $report['nights'] }} nights</x-filament::badge>
        </x-slot>
    </x-filament::section>

    <x-filament::section heading="Occupancy" description="Nights with somebody in a room, over the rooms you have and the nights in the range.">
        @forelse ($report['occupancy'] as $row)
            <x-filament::callout color="gray" icon="heroicon-o-home"
                :heading="$row['listing'] . ': ' . ($row['rate'] === null ? 'no rooms set up' : $row['rate'] . '%')"
                :description="$row['occupied'] . ' of ' . $row['available'] . ' room-nights'" />
        @empty
            <x-filament::callout color="gray" icon="heroicon-o-home" heading="No listings yet" />
        @endforelse
    </x-filament::section>

    @forelse ($report['money'] as $currency => $row)
        <x-filament::section :heading="'Money in ' . $currency" description="A stay that crosses the edge of the range counts for its nights inside it.">
            <x-filament::callout color="info" icon="heroicon-o-banknotes"
                :heading="'Room revenue ' . $row['revenue']->format() . ' over ' . $row['room_nights'] . ' room-nights'"
                :description="'Average nightly rate ' . ($row['average_rate']?->format() ?? '—') . '. Rihla\'s commission ' . $row['commission']->format() . '.'" />
            <x-filament::callout color="gray" icon="heroicon-o-arrow-trending-up"
                heading="By where it came from"
                :description="collect($row['by_source'])->map(fn ($money, $source) => $source . ' ' . $money->format())->implode(' · ')" />
            <x-filament::callout color="gray" icon="heroicon-o-users"
                heading="By who stayed"
                :description="collect($row['by_audience'])->map(fn ($money, $audience) => ($audience === 'local' ? 'Maldivians' : 'Visitors') . ' ' . $money->format())->implode(' · ')" />
            <x-filament::callout color="gray" icon="heroicon-o-receipt-percent"
                heading="Tourism GST and GST in these prices"
                :description="($row['tgst'] ? 'T-GST (' . $report['tax_rates']['tgst'] . '%) ' . $row['tgst']->format() : 'T-GST: nobody has stated the rate') . ' · ' . ($row['gst'] ? 'GST (' . $report['tax_rates']['gst'] . '%) ' . $row['gst']->format() : 'GST: nobody has stated the rate') . '.'" />
        </x-filament::section>
    @empty
        <x-filament::section heading="Money">
            <x-filament::callout color="gray" icon="heroicon-o-calendar" heading="No stays in these dates" />
        </x-filament::section>
    @endforelse

    <x-filament::section heading="Green Tax" description="Tourist stays, guests × nights in the range × the rate in each stay's own record — the figure the MIRA return asks for.">
        @forelse ($report['green_tax'] as $currency => $money)
            <x-filament::callout color="success" icon="heroicon-o-globe-asia-australia" :heading="$money->format()" />
        @empty
            <x-filament::callout color="gray" icon="heroicon-o-globe-asia-australia" heading="None in these dates" description="Either no tourist stays, or no Green Tax rate was set when they were booked." />
        @endforelse
    </x-filament::section>
</x-filament-panels::page>
