<x-filament-panels::page>
    {{-- Filament components only: Tailwind utilities do nothing inside a
         Filament panel (AGENTS.md), and x-filament::callout ignores its
         slot, so every word goes in heading or description. --}}
    @switch($this->standing())
        @case('checking')
            <x-filament::callout
                icon="heroicon-o-clock"
                color="warning"
                heading="Your account is being checked"
                description="A person at Rihla is checking your tourism registration. You can build your listings meanwhile; none of them is shown to guests until the check is done." />
            @break
        @case('refused')
            <x-filament::callout
                icon="heroicon-o-x-circle"
                color="danger"
                heading="We could not verify your registration"
                :description="$this->host()->verification_note ?: 'Message us and we will tell you what we need.'" />
            @break
        @case('suspended')
            <x-filament::callout
                icon="heroicon-o-pause-circle"
                color="danger"
                heading="Your listings are paused"
                :description="$this->host()->suspended_reason ?: 'Message us to find out why and what happens next.'" />
            @break
        @default
            <x-filament::callout
                icon="heroicon-o-check-circle"
                color="success"
                heading="You are live"
                description="Your approved listings can be found and booked on Rihla." />
    @endswitch
</x-filament-panels::page>
