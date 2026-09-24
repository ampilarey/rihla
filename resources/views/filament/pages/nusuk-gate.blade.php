<x-filament-panels::page>
    {{-- Filament components only: Tailwind utilities do nothing in /staff. --}}
    <x-filament::callout
        color="gray"
        icon="heroicon-o-information-circle"
        heading="What this records"
        description="When Rihla entered each departure's accommodation and transport in Nusuk. Whether what was entered is compliant is Nusuk's judgement; a permit cannot be requested here until both are recorded."
    />

    {{ $this->table }}
</x-filament-panels::page>
