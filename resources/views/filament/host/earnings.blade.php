{{-- Earnings — §16.6. Filament components only; no Tailwind utilities in a panel. --}}
<x-filament-panels::page>
    @php($report = $this->report())

    <x-filament::section>
        <x-slot name="heading">Stays that ended in {{ $this->start()->format('F Y') }}</x-slot>
        <x-slot name="afterHeader">
            <x-filament::button color="gray" size="sm" icon="heroicon-o-chevron-left" wire:click="previousMonth">Earlier</x-filament::button>
            <x-filament::button color="gray" size="sm" icon="heroicon-o-chevron-right" icon-position="after" wire:click="nextMonth">Later</x-filament::button>
        </x-slot>

        @if ($report === [])
            <x-filament::callout color="gray" icon="heroicon-o-calendar"
                heading="Nothing this month"
                description="Stays count here in the month they end." />
        @else
            @foreach ($report as $currency => $row)
                <x-filament::section :heading="'In ' . $currency" compact secondary>
                    <x-filament::callout color="info" icon="heroicon-o-globe-alt"
                        :heading="'From Rihla: ' . $row['marketplace_count'] . ' ' . str('stay')->plural($row['marketplace_count']) . ' · ' . $row['marketplace_gross']->format()"
                        :description="'Rihla\'s commission ' . $row['commission']->format() . ' · yours ' . $row['host_net']->format() . '.'" />
                    <x-filament::callout color="gray" icon="heroicon-o-home"
                        :heading="'Your own bookings: ' . $row['direct_count'] . ' ' . str('stay')->plural($row['direct_count']) . ' · ' . $row['direct_gross']->format()"
                        description="No commission on these." />
                    <x-filament::callout color="success" icon="heroicon-o-banknotes"
                        :heading="'Paid to you here: ' . $row['paid_here']->format() . ' · paid online to Rihla: ' . $row['paid_to_rihla']->format()"
                        :description="($row['rihla_holds_for_host']->minor > 0 ? 'Rihla holds ' . $row['rihla_holds_for_host']->format() . ' of it for you. ' : '') . ($row['commission_outstanding']->minor > 0 ? 'Commission still to settle with Rihla: ' . $row['commission_outstanding']->format() . '.' : 'Nothing to settle with Rihla.')" />
                </x-filament::section>
            @endforeach
        @endif
    </x-filament::section>

    @php($statements = $this->statements())
    <x-filament::section heading="Statements" description="Issued on the 2nd for the month before, and never changed afterwards.">
        @forelse ($statements as $statement)
            <x-filament::callout color="gray" icon="heroicon-o-document-text"
                :heading="$statement->label()"
                :description="'Yours ' . $statement->money('net_minor')->format() . ' · Rihla holds ' . $statement->money('rihla_holds_minor')->format() . ' · ' . $statement->reference">
                <x-slot name="footer">
                    <x-filament::button size="sm" color="gray" icon="heroicon-o-arrow-down-tray" wire:click="downloadStatement({{ $statement->id }})">Download PDF</x-filament::button>
                </x-slot>
            </x-filament::callout>
        @empty
            <x-filament::callout color="gray" icon="heroicon-o-document-text" heading="No statements yet" description="Your first arrives on the 2nd of next month." />
        @endforelse
    </x-filament::section>
</x-filament-panels::page>
