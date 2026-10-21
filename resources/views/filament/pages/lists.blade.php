<x-filament-panels::page>
    {{-- Filament components only: Tailwind utilities do nothing in /staff.
         Each list is a label and a pair of buttons; the pair wraps as one,
         under the label on a phone and beside it on a desk. --}}
    @php($row = 'display:flex; flex-wrap:wrap; gap:.5rem .75rem; align-items:center; justify-content:space-between;')
    @php($pair = 'display:inline-flex; flex-wrap:wrap; gap:.5rem; flex:0 0 auto;')

    <x-filament::section>
        <x-slot name="heading">Which departure</x-slot>
        <x-slot name="description">PDF prints; CSV opens in a spreadsheet. Every print is recorded in the audit trail.</x-slot>
        <x-filament::input.wrapper>
            <x-filament::input.select wire:model.live="departureId">
                @if ($departure === null)
                    <option value="">Choose a departure</option>
                @endif
                @foreach ($options as $id => $label)
                    <option value="{{ $id }}">{{ $label }}</option>
                @endforeach
            </x-filament::input.select>
        </x-filament::input.wrapper>
    </x-filament::section>

    @if ($departure)
        <x-filament::section>
            <x-slot name="heading">The manifest · {{ $travellers }} {{ \Illuminate\Support\Str::plural('traveller', $travellers) }}</x-slot>
            <x-slot name="description">The same people, in the shape each reader wants.</x-slot>
            <div style="display:grid; gap:.75rem;">
                @foreach ($presets as $preset => $label)
                    <div style="{{ $row }}">
                        <span style="flex:1 1 12rem;">{{ $label }}</span>
                        <span style="{{ $pair }}">
                            <x-filament::button tag="a" size="sm" href="{{ route('lists.manifest', ['departure' => $departure, 'preset' => $preset]) }}" target="_blank" rel="noopener">PDF</x-filament::button>
                            <x-filament::button tag="a" size="sm" color="gray" href="{{ route('lists.manifest', ['departure' => $departure, 'preset' => $preset, 'format' => 'csv']) }}">CSV</x-filament::button>
                        </span>
                    </div>
                @endforeach
                @if ($canSeeMoney)
                    <div style="{{ $row }}">
                        <span style="flex:1 1 12rem;">Payment status (who still owes what)</span>
                        <span style="{{ $pair }}">
                            <x-filament::button tag="a" size="sm" href="{{ route('lists.payments', ['departure' => $departure]) }}" target="_blank" rel="noopener">PDF</x-filament::button>
                            <x-filament::button tag="a" size="sm" color="gray" href="{{ route('lists.payments', ['departure' => $departure, 'format' => 'csv']) }}">CSV</x-filament::button>
                        </span>
                    </div>
                @endif
                @if ($canSeeDocuments)
                    <div style="{{ $row }}">
                        <span style="flex:1 1 12rem;">Documents still owed</span>
                        <span style="{{ $pair }}">
                            <x-filament::button tag="a" size="sm" href="{{ route('lists.documents', ['departure' => $departure]) }}" target="_blank" rel="noopener">PDF</x-filament::button>
                            <x-filament::button tag="a" size="sm" color="gray" href="{{ route('lists.documents', ['departure' => $departure, 'format' => 'csv']) }}">CSV</x-filament::button>
                        </span>
                    </div>
                @endif
            </div>
        </x-filament::section>

        <x-filament::section>
            <x-slot name="heading">Bus lists</x-slot>
            <x-slot name="description">A box to tick as each person boards, and what the leader needs to know.</x-slot>
            <div style="display:grid; gap:.75rem;">
                @forelse ($buses as $bus)
                    <div style="{{ $row }}">
                        <span style="flex:1 1 12rem;">{{ $bus->name }} · {{ $bus->members()->count() }} on board{{ $bus->leaderLabel() ? ' · '.$bus->leaderLabel() : '' }}</span>
                        <span style="{{ $pair }}">
                            <x-filament::button tag="a" size="sm" href="{{ route('lists.bus', ['busGroup' => $bus]) }}" target="_blank" rel="noopener">Bus list</x-filament::button>
                            @if ($canPrintCards && $bus->members()->exists())
                                <x-filament::button tag="a" size="sm" color="gray" href="{{ route('lists.cards', ['kind' => 'luggage', 'bus' => $bus->getKey()]) }}" target="_blank" rel="noopener">Luggage tags</x-filament::button>
                            @endif
                        </span>
                    </div>
                @empty
                    <p class="fi-color-gray" style="margin:0;">No buses yet. Add them under Travel → Buses.</p>
                @endforelse
            </div>
        </x-filament::section>

        <x-filament::section>
            <x-slot name="heading">Rooming lists</x-slot>
            <x-slot name="description">The hotel copy leaves out phone numbers and who booked.</x-slot>
            <div style="display:grid; gap:.75rem;">
                @forelse ($hotels as $hotel)
                    <div style="{{ $row }}">
                        <span style="flex:1 1 12rem;">{{ $hotel->cityLabel() }} · {{ $hotel->name }} · {{ $hotel->rooms()->count() }} {{ \Illuminate\Support\Str::plural('room', $hotel->rooms()->count()) }}</span>
                        <span style="{{ $pair }}">
                            <x-filament::button tag="a" size="sm" href="{{ route('lists.rooms', ['departureHotel' => $hotel]) }}" target="_blank" rel="noopener">Office copy</x-filament::button>
                            <x-filament::button tag="a" size="sm" color="gray" href="{{ route('lists.rooms', ['departureHotel' => $hotel, 'hotel' => 1]) }}" target="_blank" rel="noopener">Hotel copy</x-filament::button>
                            <x-filament::button tag="a" size="sm" color="gray" href="{{ route('lists.rooms', ['departureHotel' => $hotel, 'format' => 'csv']) }}">CSV</x-filament::button>
                        </span>
                    </div>
                @empty
                    <p class="fi-color-gray" style="margin:0;">No hotels on this departure yet.</p>
                @endforelse
            </div>
        </x-filament::section>

        @if ($canPrintCards)
            <x-filament::section>
                <x-slot name="heading">Cards and tags for the whole departure</x-slot>
                <x-slot name="description">Each traveller gets one card and one tag; printing again reuses the same QR. Print a bus's own from Travel → Buses.</x-slot>
                <div style="{{ $pair }}">
                    <x-filament::button tag="a" size="sm" href="{{ route('lists.cards', ['kind' => 'customer', 'departure' => $departure->getKey()]) }}" target="_blank" rel="noopener">Customer cards</x-filament::button>
                    <x-filament::button tag="a" size="sm" href="{{ route('lists.cards', ['kind' => 'luggage', 'departure' => $departure->getKey()]) }}" target="_blank" rel="noopener">Luggage tags</x-filament::button>
                </div>
            </x-filament::section>
        @endif
    @else
        <x-filament::section>
            <x-slot name="heading">Nothing to print yet</x-slot>
            There is no departure still to leave. Add one under Packages, and its lists appear here.
        </x-filament::section>
    @endif
</x-filament-panels::page>
