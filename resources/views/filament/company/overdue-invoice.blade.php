{{-- Billing automation: see Modules\Companies\Filament\OverdueInvoiceBanner.
     x-filament::callout renders heading, description and footer and
     ignores its slot (AGENTS.md), so everything said is in those. --}}
<div style="margin-bottom: 1rem;">
    <x-filament::callout
        color="danger"
        icon="heroicon-o-exclamation-triangle"
        :heading="$heading"
        :description="$description"
    >
        <x-slot name="footer">
            <x-filament::link :href="$url">Open Company details</x-filament::link>
        </x-slot>
    </x-filament::callout>
</div>
