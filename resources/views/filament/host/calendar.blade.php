{{--
    The occupancy calendar — §16.6.

    Plain table cells with inline styles and Filament's own colour variables
    (--success-200 and so on), because Tailwind utilities do nothing inside
    a panel (AGENTS.md). No JavaScript: every cell is a link or nothing.
--}}
<x-filament-panels::page>
    {{-- Scoped here because a style attribute has no dark variant. Filament
         puts `.dark` on <html>; the colours are its own variables. --}}
    <style>
        .rihla-cal .rihla-cal-side { background: var(--gray-50); color: var(--gray-950); }
        .rihla-cal .rihla-cal-detail { color: var(--gray-600); }
        .rihla-cal .rihla-cal-line { border-top: 1px solid var(--gray-200); border-inline-start: 1px solid var(--gray-100); }
        .rihla-cal .rihla-cal-stay { color: var(--gray-950); }
        .rihla-cal .rihla-cal-request { color: var(--gray-950); }
        .dark .rihla-cal .rihla-cal-side { background: var(--gray-900); color: var(--gray-100); }
        .dark .rihla-cal .rihla-cal-detail { color: var(--gray-400); }
        .dark .rihla-cal .rihla-cal-line { border-top-color: var(--gray-700); border-inline-start-color: var(--gray-800); }
        .dark .rihla-cal .rihla-cal-request { color: var(--gray-100); }
    </style>
    @php($nights = $this->nights())
    @php($rows = $this->rows())
    @php($today = now()->toDateString())

    <x-filament::section>
        <x-slot name="heading">{{ $this->start()->format('F Y') }}</x-slot>
        <x-slot name="afterHeader">
            <x-filament::button color="gray" size="sm" icon="heroicon-o-chevron-left" wire:click="previousMonth">Earlier</x-filament::button>
            <x-filament::button color="gray" size="sm" icon="heroicon-o-chevron-right" icon-position="after" wire:click="nextMonth">Later</x-filament::button>
        </x-slot>

        @if ($rows === [])
            <x-filament::callout
                color="gray"
                icon="heroicon-o-home-modern"
                heading="No rooms yet"
                description="Add a listing and its rooms, and each room gets a row here." />
        @else
            <div class="rihla-cal" style="overflow-x: auto;">
                <table style="border-collapse: collapse; font-size: 0.75rem; width: 100%; table-layout: fixed;">
                    <thead>
                        <tr>
                            <th scope="col" style="width: 8rem; text-align: start; padding: 0.25rem 0.5rem; position: sticky; inset-inline-start: 0;" class="rihla-cal-side">Room</th>
                            @foreach ($nights as $night)
                                <th scope="col" style="padding: 0.15rem 0; min-width: 1.6rem; text-align: center; font-weight: {{ $night->toDateString() === $today ? '700' : '400' }}; {{ $night->toDateString() === $today ? 'box-shadow: inset 0 -2px 0 var(--primary-500);' : '' }}">
                                    <div>{{ $night->format('D')[0] }}</div>
                                    <div>{{ $night->day }}</div>
                                </th>
                            @endforeach
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($rows as $row)
                            <tr>
                                <th scope="row" style="text-align: start; padding: 0.25rem 0.5rem; white-space: nowrap; position: sticky; inset-inline-start: 0;" class="rihla-cal-side rihla-cal-line">
                                    <div>{{ $row['label'] }}</div>
                                    <div class="rihla-cal-detail" style="font-weight: 400;">{{ $row['detail'] }}</div>
                                </th>
                                @foreach (\App\Filament\Host\Pages\Calendar::runs($row['cells']) as $run)
                                    @php($here = $run['stays'])
                                    @php($night = \Carbon\CarbonImmutable::parse($run['night']))
                                    <td colspan="{{ $run['span'] }}" class="rihla-cal-line" style="padding: 1px; height: 2.25rem;">
                                        @if ($here !== [])
                                            @php($stay = $here[0])
                                            <a href="{{ $this->stayUrl($stay) }}"
                                               title="{{ $stay->reference }} · {{ $stay->customer?->name }} · {{ $stay->check_in->format('j M') }} – {{ $stay->check_out->format('j M') }} · {{ \App\Filament\Host\Resources\Bookings\BookingResource::statusLabel($stay->status) }}{{ count($here) > 1 ? ' · and '.(count($here) - 1).' more' : '' }}"
                                               class="{{ $stay->status === \App\Models\Stay::REQUESTED ? 'rihla-cal-request' : 'rihla-cal-stay' }}"
                                               style="display: block; height: 100%; min-height: 2.1rem; padding: 0.15rem 0.3rem; border-radius: 0.25rem; text-decoration: none; overflow: hidden; white-space: nowrap; text-overflow: ellipsis; {{ \App\Filament\Host\Pages\Calendar::style($stay) }}">
                                                {{ count($here) > 1 ? count($here).' bookings' : $stay->customer?->name }}
                                            </a>
                                        @elseif ($row['unit'] !== null && $run['night'] >= $today)
                                            <a href="{{ $this->bookUrl($row['unit'], $night) }}"
                                               aria-label="Book {{ $row['label'] }} from {{ $night->format('j M') }}"
                                               title="Book {{ $row['label'] }} from {{ $night->format('j M') }}"
                                               style="display: block; height: 100%; min-height: 2.1rem;"></a>
                                        @endif
                                    </td>
                                @endforeach
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </x-filament::section>

    <x-filament::section heading="Key" compact>
        <x-filament::badge color="warning">Outlined: asked for, not yet yours to give</x-filament::badge>
        <x-filament::badge color="info">Waiting for the deposit</x-filament::badge>
        <x-filament::badge color="success">Confirmed</x-filament::badge>
        <x-filament::badge color="primary">In house</x-filament::badge>
        <x-filament::badge color="gray">Gone home</x-filament::badge>
    </x-filament::section>
</x-filament-panels::page>
