<x-filament-panels::page>
    {{-- Filament components only: Tailwind utilities do nothing in /staff. --}}
    @php($credits = $this->getCredits())
    @php($owed = $credits->filter(fn ($credit) => $credit->isUnacknowledged()))

    <x-filament::section>
        <x-slot name="heading">What this counts, and what it does not</x-slot>

        <x-slot name="afterHeader">
            {{-- `afterHeader`, not `headerEnd`: Blade discards a slot the
                 component does not declare, without a word. --}}
            @if ($credits->isNotEmpty())
                <x-filament::badge :color="$owed->isEmpty() ? 'success' : 'warning'">
                    {{ $owed->count() }} of {{ $credits->count() }} with nothing written down
                </x-filament::badge>
            @endif
        </x-slot>

        <x-filament::callout
            color="gray"
            icon="heroicon-o-information-circle"
            heading="It cannot tell you who has been thanked"
            description="A thank-you is a telephone call, and nothing here can see one. What it can see is whether anybody wrote a follow-up down about this person since their last referral flew. That is what the dates below mean — not that somebody was thanked, and not that somebody was not."
        />
    </x-filament::section>

    @forelse ($credits as $credit)
        <x-filament::section>
            <x-slot name="heading">{{ $credit->referrer->name }}</x-slot>

            <x-slot name="afterHeader">
                <x-filament::badge :color="$credit->tone()">
                    {{ $credit->seats }} {{ $credit->seats === 1 ? 'seat' : 'seats' }}
                </x-filament::badge>
            </x-slot>

            <x-slot name="description">Sent {{ $credit->spoken() }}.</x-slot>

            @if ($credit->isUnacknowledged())
                <x-filament::callout
                    color="warning"
                    icon="heroicon-o-exclamation-circle"
                    heading="Nothing written down since their last referral flew"
                    :description="'The most recent person they sent travelled on '
                        . $credit->lastArrival->format('j M Y') . ', and '
                        . ($credit->lastNoted === null
                            ? 'there is no follow-up recorded against them at all.'
                            : 'the last follow-up recorded against them was ' . $credit->lastNoted->format('j M Y') . ', before that.')"
                >
                    <x-slot name="footer">
                        <x-filament::link :href="route('filament.staff.resources.customers.view', $credit->referrer)">
                            Open their record and add a follow-up
                        </x-filament::link>
                    </x-slot>
                </x-filament::callout>
            @elseif ($credit->lastArrival === null)
                <x-filament::callout
                    color="gray"
                    icon="heroicon-o-clock"
                    heading="Nobody they sent has travelled yet"
                    description="A referral that has not flown is a favour in progress. It is counted here so it is not forgotten, and it is not owed anything yet."
                />
            @else
                <x-filament::callout
                    color="success"
                    icon="heroicon-o-check-circle"
                    :heading="'Followed up on ' . $credit->lastNoted->format('j M Y')"
                    :description="'Their most recent referral travelled on ' . $credit->lastArrival->format('j M Y')
                        . ', and something was written down after that.'"
                />
            @endif
        </x-filament::section>
    @empty
        <x-filament::section>
            <x-slot name="heading">Nobody has been recorded as referred</x-slot>

            <x-filament::callout
                color="gray"
                icon="heroicon-o-user-plus"
                heading="No customer names another as their referrer"
                description="The field is on the customer form — 'Referred by' — and it points at somebody already on file rather than a name in a box, so that the person who made the referral can actually be found afterwards. Nothing appears here until somebody fills it in."
            />
        </x-filament::section>
    @endforelse
</x-filament-panels::page>
