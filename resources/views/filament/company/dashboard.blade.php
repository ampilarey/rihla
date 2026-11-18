<x-filament-panels::page>
    <x-filament::section :heading="$this->company()->name" :description="'You are signed in as '.$this->role().'.'">
        {{ __('The screens your team runs its trips on arrive here next.') }}
    </x-filament::section>
</x-filament-panels::page>
