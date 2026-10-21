<x-filament-panels::page>
    {{-- Filament components only: Tailwind utilities do nothing in /staff. --}}
    <x-filament::section>
        <x-slot name="heading">Which departure, which files</x-slot>
        <x-slot name="description">Every page is read and offered to the traveller whose passport number or name it carries. Nothing is filed until you say so on the next screen.</x-slot>
        <form wire:submit="read">
            {{ $this->form }}
            <div style="margin-top:1rem; display:flex; gap:.5rem; flex-wrap:wrap;">
                <x-filament::button type="submit" wire:loading.attr="disabled">Read the visas</x-filament::button>
                <span wire:loading wire:target="read" class="fi-color-gray" style="align-self:center;">Reading…</span>
            </div>
        </form>
    </x-filament::section>

    @if ($this->pages !== [])
        <x-filament::section>
            <x-slot name="heading">{{ count($this->pages) }} {{ \Illuminate\Support\Str::plural('page', count($this->pages)) }} · {{ $matched }} matched</x-slot>
            <x-slot name="description">A match by passport number is sure. A match by name only is a guess: look at it. A page with nobody chosen is left out.</x-slot>
            <div style="display:grid; gap:.25rem;">
                @foreach ($this->pages as $i => $page)
                    @php([$badge, $colour] = $this->matchBadge($page))
                    <div style="display:flex; flex-wrap:wrap; gap:.5rem .75rem; align-items:center; justify-content:space-between; padding:.6rem 0; border-top:1px solid rgba(0,0,0,.07);">
                        <div style="flex:1 1 14rem; min-width:0;">
                            <div><strong>{{ $page['file'] }}</strong>@if (($page['of'] ?? 1) > 1) · page {{ $page['page'] }} of {{ $page['of'] }}@endif</div>
                            <div class="fi-color-gray" style="font-size:.875rem;">{{ $this->describe($page) }}</div>
                        </div>
                        <div style="flex:1 1 16rem; display:flex; gap:.5rem; align-items:center; flex-wrap:wrap;">
                            <x-filament::badge :color="$colour">{{ $badge }}</x-filament::badge>
                            <x-filament::input.wrapper style="flex:1 1 10rem;">
                                <x-filament::input.select wire:model="pages.{{ $i }}.traveller_id">
                                    <option value="">Leave out</option>
                                    @foreach ($this->candidates as $id => $label)
                                        <option value="{{ $id }}">{{ $label }}</option>
                                    @endforeach
                                </x-filament::input.select>
                            </x-filament::input.wrapper>
                        </div>
                    </div>
                @endforeach
            </div>
            <div style="margin-top:1rem; display:flex; gap:.5rem; flex-wrap:wrap;">
                <x-filament::button wire:click="file" wire:confirm="File the matched pages? Each goes into its traveller's documents as their visa, verified, and the visa application is marked issued." wire:loading.attr="disabled">File the visas</x-filament::button>
                <x-filament::button wire:click="discard" color="gray">Start again</x-filament::button>
            </div>
        </x-filament::section>
    @endif
</x-filament-panels::page>
