<x-filament-panels::page>
    {{-- Filament components only: Tailwind utilities do nothing in /staff. --}}
    @php($alerts = $this->getAlerts())
    @php($withheld = $this->withheldCount())

    @forelse ($alerts as $alert)
        <x-filament::section>
            <x-slot name="heading">{{ $alert->headline }}</x-slot>

            {{-- `afterHeader`, not `headerEnd`: Blade discards a slot the
                 component does not declare, without a word. --}}
            <x-slot name="afterHeader">
                <x-filament::badge :color="$alert->tone()">{{ $alert->severityLabel() }}</x-filament::badge>
            </x-slot>

            <x-filament::callout
                :color="$alert->tone()"
                :icon="$alert->isUrgent() ? 'heroicon-o-exclamation-circle' : 'heroicon-o-clock'"
                :description="$alert->detail"
            >
                <x-slot name="footer">
                    <x-filament::link :href="$alert->url">{{ $alert->action }}</x-filament::link>
                </x-slot>
            </x-filament::callout>
        </x-filament::section>
    @empty
        {{-- Two different empty states, and conflating them was a real
             defect caught by reading the rendered page: a tour leader saw
             "None of those is true right now" printed directly above
             "5 alerts are hidden", which is two sentences contradicting
             each other on one screen. --}}
        <x-filament::section>
            <x-slot name="heading">
                {{ $withheld > 0 ? 'Nothing here is yours to act on' : 'Nothing needs attention' }}
            </x-slot>

            @if ($withheld > 0)
                <x-filament::callout
                    color="gray"
                    icon="heroicon-o-lock-closed"
                    :heading="$withheld . ' ' . ($withheld === 1 ? 'alert is' : 'alerts are') . ' open, and none of them is yours'"
                    description="An alert is only shown to somebody who can act on it. Your role cannot open the screens these are acted on, so they are with the people who can. Nothing is being kept from you that you could do anything about."
                />
            @else
                <x-filament::callout
                    color="success"
                    icon="heroicon-o-check-circle"
                    heading="Nothing is raising its hand"
                    description="This screen watches the things no other screen watches: departures off their selling pace, money waiting to be checked, quotations about to lapse, departures flying soon with a blocker, and religious content a scholar has gone quiet on. None of those is true right now."
                />
            @endif
        </x-filament::section>
    @endforelse

    @if ($alerts->isNotEmpty() && $withheld > 0)
        {{-- Named rather than silently a shorter list. Only when there is a
             list to be shorter than — see the empty state above. --}}
        <x-filament::section>
            <x-slot name="heading">Not shown to your role</x-slot>

            <x-filament::callout
                color="gray"
                icon="heroicon-o-lock-closed"
                :heading="$withheld . ' further ' . ($withheld === 1 ? 'alert is' : 'alerts are') . ' hidden'"
                description="An alert is only shown to somebody who can act on it, so this list is shorter for your role than for an administrator's. Nothing is being kept from you that you could do anything about."
            />
        </x-filament::section>
    @endif

    <x-filament::section :collapsible="true" :collapsed="true">
        <x-slot name="heading">What this screen does not raise</x-slot>

        <x-filament::callout
            color="gray"
            icon="heroicon-o-information-circle"
            heading="Things another screen already watches"
            description="Adrift enquiries and follow-ups due are on Today. Outstanding documents are on the chasing list. Everything blocking a particular departure is on the departure board, and this page only says that nobody has opened it. Restating any of those here would double every number in the office and leave both copies less trusted than one."
        />
    </x-filament::section>
</x-filament-panels::page>
