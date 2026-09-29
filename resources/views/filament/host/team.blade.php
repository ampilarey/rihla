<x-filament-panels::page>
    {{-- Filament components only: Tailwind utilities do nothing inside a panel. --}}
    {{ $this->table }}

    @php($pending = $this->pendingInvitations())
    @if ($pending->isNotEmpty())
        <x-filament::section heading="Invitations not yet accepted">
            @foreach ($pending as $invitation)
                <x-filament::callout
                    color="gray"
                    icon="heroicon-o-envelope"
                    :heading="$invitation->email . ' — ' . \App\Support\HostRole::label($invitation->role)"
                    :description="'Sent ' . $invitation->created_at->diffForHumans() . ', works until ' . $invitation->expires_at->format('j M Y') . '.'">
                    <x-slot name="footer">
                        <x-filament::button size="sm" color="danger" wire:click="revokeInvitation({{ $invitation->id }})">Cancel invitation</x-filament::button>
                    </x-slot>
                </x-filament::callout>
            @endforeach
        </x-filament::section>
    @endif
</x-filament-panels::page>
