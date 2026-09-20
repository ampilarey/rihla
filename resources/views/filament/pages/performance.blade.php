<x-filament-panels::page>
    {{-- Filament components only: Tailwind utilities do nothing in /staff. --}}
    @php($board = $this->getBoard())

    <x-filament::section>
        <x-slot name="heading">How far back to look</x-slot>

        <x-slot name="description">
            {{ $board->windowSpoken() }}. Each measure says which rows it counted, because they
            are not the same rows — a conversion rate is about enquiries that arrived, a seat-fill
            rate is about departures that flew.
        </x-slot>

        <x-filament::tabs>
            @foreach ($this->windowOptions() as $value => $label)
                <x-filament::tabs.item
                    :active="$board->days === $value"
                    wire:click="$set('days', {{ $value }})"
                >
                    {{ $label }}
                </x-filament::tabs.item>
            @endforeach
        </x-filament::tabs>
    </x-filament::section>

    @php($measured = $board->measured())

    <x-filament::section>
        <x-slot name="heading">The numbers</x-slot>

        <x-slot name="afterHeader">
            {{-- `afterHeader`, not `headerEnd`: Blade discards a slot the
                 component does not declare, without a word. --}}
            {{-- "8 of 12" on its own reads as a score. It is a count of
                 how many of the plan's measures currently have a figure. --}}
            <x-filament::badge color="gray">
                {{ $measured->count() }} of {{ $board->kpis->count() }} have a figure
            </x-filament::badge>
        </x-slot>

        @forelse ($measured as $kpi)
            <x-filament::callout
                :color="$kpi->tone"
                icon="heroicon-o-chart-bar-square"
                :heading="$kpi->figure . '  ·  ' . $kpi->name"
                :description="$kpi->question . ' ' . $kpi->detail
                    . ($kpi->target ? ' Target: ' . $kpi->target : '')"
            />
        @empty
            <x-filament::callout
                color="gray"
                icon="heroicon-o-inbox"
                heading="Nothing has a figure in this window"
                description="Every measure below says why. Widen the window, or check that the work being measured has actually been recorded."
            />
        @endforelse

        @unless ($this->mayReadMargin())
            {{-- Named rather than silently one row shorter: two people
                 comparing this screen in a meeting must not find different
                 lists and no explanation for it. --}}
            <x-filament::callout
                color="gray"
                icon="heroicon-o-lock-closed"
                heading="Margin per traveller is not shown to your role"
                description="§10.5 lists it, and it is computed — it needs the profit.view permission, which your role does not hold. Ask an administrator if you need it."
            />
        @endunless
    </x-filament::section>

    @php($waiting = $board->waiting())

    @if ($waiting->isNotEmpty())
        <x-filament::section>
            <x-slot name="heading">Nothing to measure yet</x-slot>

            <x-slot name="description">
                These measures are real and the arithmetic works. There are simply no rows to do it
                over in this window — which is not the same as a result of zero, and is not shown as one.
            </x-slot>

            @foreach ($waiting as $kpi)
                <x-filament::callout
                    color="gray"
                    icon="heroicon-o-clock"
                    :heading="$kpi->name"
                    :description="$kpi->question . ' ' . $kpi->because"
                />
            @endforeach
        </x-filament::section>
    @endif

    @php($absent = $board->notInstrumented())

    @if ($absent->isNotEmpty())
        <x-filament::section>
            <x-slot name="heading">Not measured — nothing records these</x-slot>

            <x-slot name="description">
                The upgrade plan asks for {{ $absent->count() }} measures this application cannot produce.
                They are listed here as themselves rather than replaced with something that looks similar:
                a last-seen stamp reported as "weekly actives" is a number somebody quotes to a bank.
            </x-slot>

            @foreach ($absent as $kpi)
                <x-filament::callout
                    color="warning"
                    icon="heroicon-o-exclamation-triangle"
                    :heading="$kpi->name"
                    :description="$kpi->question . ' ' . $kpi->because"
                />
            @endforeach
        </x-filament::section>
    @endif
</x-filament-panels::page>
