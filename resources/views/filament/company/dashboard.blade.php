<x-filament-panels::page>
    <x-filament::section :heading="$this->company()->name" :description="'You are signed in as '.$this->role().'.'">
        {{ __('Everything your team works on is in the menu: bookings, payments, documents and the journey.') }}
    </x-filament::section>
</x-filament-panels::page>
