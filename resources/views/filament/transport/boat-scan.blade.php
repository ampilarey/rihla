<x-filament-panels::page>
    {{-- Filament components only: Tailwind utilities do nothing in /staff. --}}
    <x-filament::section>
        <x-slot name="heading">Find the passenger</x-slot>
        <x-slot name="description">Point the phone's camera at the QR on their ticket page and it opens here.</x-slot>
        <form wire:submit="lookup" style="display:flex; gap:.5rem; flex-wrap:wrap; align-items:center;">
            <x-filament::input.wrapper style="flex:1 1 12rem;">
                <x-filament::input type="text" wire:model="code" placeholder="QR link or code" autocomplete="off" aria-label="QR link or code" />
            </x-filament::input.wrapper>
            <x-filament::button type="submit">Find</x-filament::button>
        </form>
    </x-filament::section>

    @if ($ticket)
        <x-filament::section>
            <x-slot name="heading">{{ $ticket->full_name }}</x-slot>
            <x-slot name="description">{{ $sailing->route->label() }} · {{ $sailing->departs_at->format('D j M, H:i') }} · {{ $sailing->vessel->name }}</x-slot>
            <x-slot name="afterHeader">
                <x-filament::badge :color="$ticket->boarded_at ? 'success' : ($booking->status === \Modules\Transport\Models\BoatBooking::CONFIRMED ? 'info' : 'danger')">
                    {{ $ticket->boarded_at ? 'On board '.$ticket->boarded_at->format('H:i') : $statusLabel }}
                </x-filament::badge>
            </x-slot>
            <dl style="display:grid; grid-template-columns:1fr 1fr; gap:.75rem; margin:0;">
                <div><dt class="fi-color-gray" style="font-size:.75rem;">Booking</dt><dd style="margin:0; font-weight:600;">{{ $booking->reference }} · {{ $statusLabel }}</dd></div>
                <div><dt class="fi-color-gray" style="font-size:.75rem;">Fare</dt><dd style="margin:0;">{{ $ticket->is_child ? 'Child' : 'Adult' }}</dd></div>
                <div><dt class="fi-color-gray" style="font-size:.75rem;">Booked by</dt><dd style="margin:0;">{{ $booking->contact_name }} · <a href="{{ \App\Support\Contact::telUrlFor($booking->contact_phone) }}">{{ $booking->contact_phone }}</a></dd></div>
                <div><dt class="fi-color-gray" style="font-size:.75rem;">Party on board</dt><dd style="margin:0;">{{ $booking->tickets->whereNotNull('boarded_at')->count() }} of {{ $booking->tickets->count() }}</dd></div>
            </dl>
            <div style="display:flex; flex-wrap:wrap; gap:.5rem; margin-top:1rem;">
                @if ($ticket->boarded_at)
                    <x-filament::button wire:click="unboard" color="gray">Undo boarding</x-filament::button>
                @else
                    <x-filament::button wire:click="board" color="success">Board {{ $ticket->full_name }}</x-filament::button>
                @endif
                <x-filament::button wire:click="clear" color="gray">Next</x-filament::button>
            </div>
        </x-filament::section>
    @endif
</x-filament-panels::page>
