<x-filament-panels::page>
    {{-- Filament components and this page's own styles: Tailwind utilities do nothing in /staff.
         Two columns per row — what, and the money — so a phone reads it without scrolling
         sideways; the CSV keeps every column. --}}
    <style>
        .rihla-books table { width: 100%; border-collapse: collapse; font-size: .875rem; }
        .rihla-books th, .rihla-books td { padding: .55rem .4rem; text-align: start; vertical-align: top; border-top: 1px solid rgba(0,0,0,.07); }
        .rihla-books thead th { border-top: 0; font-weight: 600; }
        .rihla-books .num { text-align: end; white-space: nowrap; font-variant-numeric: tabular-nums; }
        .rihla-books .muted { color: rgb(var(--gray-500)); font-size: .8rem; }
        .rihla-books .num .muted { white-space: normal; }
        .rihla-books td:first-child { min-width: 55%; }
        .rihla-books .in { color: rgb(var(--success-600)); }
        .rihla-books .out { color: rgb(var(--danger-600)); }
        .rihla-books tfoot td { font-weight: 600; }
        .rihla-books .filters { display: flex; flex-wrap: wrap; gap: .5rem .75rem; align-items: center; margin-bottom: .75rem; }
        .rihla-books .filters > * { flex: 1 1 11rem; }
        .rihla-books .filters > .csv { flex: 0 0 auto; }
    </style>

    @php($money = fn (int $minor, string $currency): string => \App\Support\Money::ofMinor($minor, $currency)->format())

    <div class="rihla-books" style="display: grid; gap: 1rem;">
        <x-filament::tabs>
            @foreach ($this->tabs() as $key => $label)
                <x-filament::tabs.item :active="$this->tab === $key" wire:click="$set('tab', '{{ $key }}')">{{ $label }}</x-filament::tabs.item>
            @endforeach
        </x-filament::tabs>

        @if ($this->tab === \App\Services\Finance\Books::CASH)
            @php($departure = $this->getDeparture())
            @php($book = $this->getCashBook())
            @php($profit = $this->getProfit())
            <x-filament::section>
                <x-slot name="heading">Cash book · {{ \App\Services\Finance\Books::subjectLabel($departure) }}</x-slot>
                <x-slot name="description">Money that actually moved: payments Finance verified, refunds, and costs marked paid. What is only agreed or estimated is in the journey's margin, not here.</x-slot>
                <x-slot name="afterHeader">
                    @if ($departure?->isSettled())
                        <x-filament::badge color="gray">Settled — books closed</x-filament::badge>
                    @endif
                </x-slot>
                <div class="filters">
                    <x-filament::input.wrapper>
                        <x-filament::input.select wire:model.live="departureId">
                            @foreach ($this->departureOptions() as $id => $label)
                                <option value="{{ $id }}">{{ $label }}</option>
                            @endforeach
                        </x-filament::input.select>
                    </x-filament::input.wrapper>
                    <x-filament::input.wrapper>
                        <x-filament::input.select wire:model.live="month">
                            @foreach ($this->monthOptions() as $value => $label)
                                <option value="{{ $value }}">{{ $label }}</option>
                            @endforeach
                        </x-filament::input.select>
                    </x-filament::input.wrapper>
                    <span class="csv"><x-filament::button tag="a" color="gray" size="sm" href="{{ $this->csvUrl() }}">CSV</x-filament::button></span>
                </div>

                @if ($profit)
                    <p class="muted" style="margin: 0 0 .75rem;">
                        Booked {{ $profit->revenue->map(fn ($m) => $m->format())->join(' · ') ?: 'nothing' }}
                        · costs {{ $profit->costs->map(fn ($m) => $m->format())->join(' · ') ?: 'none' }} ({{ $profit->countingLabel() }})
                        · margin {{ $profit->margin()?->format() ?? 'kept apart by currency' }}@if ($profit->marginPerTraveller()), {{ $profit->marginPerTraveller()->format() }} a traveller @endif
                    </p>
                @endif

                <table>
                    <thead><tr><th>What</th><th class="num">In / out</th></tr></thead>
                    <tbody>
                        @forelse ($book['lines'] as $line)
                            <tr>
                                <td><span class="muted">{{ $line['on']->format('j M Y') }}</span><br>{{ $line['words'] }}<div class="muted">{{ $line['detail'] }}</div></td>
                                <td class="num {{ $line['in'] > 0 ? 'in' : 'out' }}">{{ $line['in'] > 0 ? '+ '.$money($line['in'], $line['currency']) : '− '.$money($line['out'], $line['currency']) }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="2" class="muted">Nothing has moved yet{{ $this->month ? ' in that month' : '' }}.</td></tr>
                        @endforelse
                    </tbody>
                    <tfoot>
                        @foreach ($book['totals'] as $currency => $total)
                            <tr>
                                <td>{{ $currency }}: in {{ $money($total['in'], $currency) }}, out {{ $money($total['out'], $currency) }}</td>
                                <td class="num">net {{ $money($total['in'] - $total['out'], $currency) }}</td>
                            </tr>
                        @endforeach
                        @if ($book['net'] && count($book['totals']) > 1)
                            <tr><td>In one figure</td><td class="num">{{ $book['net']->format() }}</td></tr>
                        @elseif ($book['unconvertible'] !== [])
                            <tr><td colspan="2" class="muted">No single figure: no rate for {{ implode(', ', $book['unconvertible']) }}.</td></tr>
                        @endif
                    </tfoot>
                </table>
            </x-filament::section>
        @elseif ($this->tab === \App\Services\Finance\Books::MONTHS)
            <x-filament::section>
                <x-slot name="heading">Month by month</x-slot>
                <x-slot name="description">What came in, what went back, what was paid out, and what was left, by the month the money moved. Every departure and the company together.</x-slot>
                <div class="filters">
                    <x-filament::input.wrapper>
                        <x-filament::input.select wire:model.live="months">
                            @foreach ([6, 12, 24] as $n)
                                <option value="{{ $n }}">The last {{ $n }} months</option>
                            @endforeach
                        </x-filament::input.select>
                    </x-filament::input.wrapper>
                    <span class="csv"><x-filament::button tag="a" color="gray" size="sm" href="{{ $this->csvUrl() }}">CSV</x-filament::button></span>
                </div>
                <table>
                    <thead><tr><th>Month</th><th class="num">In</th><th class="num">Out</th><th class="num">Net</th></tr></thead>
                    <tbody>
                        @foreach ($this->getMonths() as $row)
                            <tr>
                                <td style="white-space: nowrap;">{{ $row['label'] }}</td>
                                <td class="num">
                                    @foreach ($row['in'] as $currency => $minor){{ $money($minor, $currency) }}<br>@endforeach
                                    @foreach ($row['refunds'] as $currency => $minor)<span class="muted">− {{ $money($minor, $currency) }} refunded</span><br>@endforeach
                                </td>
                                <td class="num">@foreach ($row['out'] as $currency => $minor){{ $money($minor, $currency) }}<br>@endforeach</td>
                                <td class="num">{{ $row['net']?->format() ?? ($row['unconvertible'] === [] ? '' : 'no rate for '.implode(', ', $row['unconvertible'])) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </x-filament::section>
        @elseif ($this->tab === \App\Services\Finance\Books::OWED)
            @php($owed = $this->getOwed())
            <x-filament::section>
                <x-slot name="heading">Still owed</x-slot>
                <x-slot name="description">Every held, confirmed or completed booking with a balance, soonest departure first.</x-slot>
                <x-slot name="afterHeader">
                    <x-filament::button tag="a" color="gray" size="sm" href="{{ $this->csvUrl() }}">CSV</x-filament::button>
                </x-slot>
                <table>
                    <thead><tr><th>Booking</th><th class="num">Owed</th></tr></thead>
                    <tbody>
                        @forelse ($owed['rows'] as $row)
                            <tr>
                                <td>{{ $row['reference'] }} · {{ $row['customer'] }}<div class="muted">{{ $row['departure'] }} · {{ $row['leaves']?->format('j M Y') ?? '—' }} · {{ $row['status'] }}</div></td>
                                <td class="num">{{ $row['balance']->format() }}<div class="muted">of {{ $row['total']->format() }}, paid {{ $row['paid']->format() }}</div></td>
                            </tr>
                        @empty
                            <tr><td colspan="2" class="muted">Nobody owes anything.</td></tr>
                        @endforelse
                    </tbody>
                    <tfoot>
                        @foreach ($owed['totals'] as $currency => $total)
                            <tr><td>Total {{ $currency }}</td><td class="num">{{ $money($total, $currency) }}</td></tr>
                        @endforeach
                    </tfoot>
                </table>
            </x-filament::section>
        @else
            @php($collections = $this->getCollections())
            <x-filament::section>
                <x-slot name="heading">What came in</x-slot>
                <x-slot name="description">Every payment Finance verified between the two dates, with what arrived when it was not the booking's currency.</x-slot>
                <div class="filters">
                    <x-filament::input.wrapper><x-filament::input type="date" wire:model.live="from" /></x-filament::input.wrapper>
                    <x-filament::input.wrapper><x-filament::input type="date" wire:model.live="to" /></x-filament::input.wrapper>
                    <span class="csv"><x-filament::button tag="a" color="gray" size="sm" href="{{ $this->csvUrl() }}">CSV</x-filament::button></span>
                </div>
                <table>
                    <thead><tr><th>Payment</th><th class="num">Credited</th></tr></thead>
                    <tbody>
                        @forelse ($collections['rows'] as $row)
                            <tr>
                                <td><span class="muted">{{ $row['on']->format('j M Y') }}</span><br>{{ $row['reference'] }} · {{ $row['booking'] }}<div class="muted">{{ $row['customer'] }}{{ $row['verified_by'] ? ' · verified by '.$row['verified_by'] : '' }}</div></td>
                                <td class="num">{{ $row['credited']->format() }}<div class="muted">{{ $row['method'] }}{{ $row['received'] !== $row['credited']->format() ? ' · '.$row['received'] : '' }}</div></td>
                            </tr>
                        @empty
                            <tr><td colspan="2" class="muted">Nothing came in between those dates.</td></tr>
                        @endforelse
                    </tbody>
                    <tfoot>
                        @foreach ($collections['totals'] as $currency => $total)
                            <tr><td>Total {{ $currency }}</td><td class="num">{{ $money($total, $currency) }}</td></tr>
                        @endforeach
                        @foreach ($collections['byMethod'] as $method => $perCurrency)
                            <tr><td class="muted">{{ str_replace('_', ' ', $method) }}</td><td class="num muted">{{ implode(' · ', array_map(fn ($c, $m) => $money($m, $c), array_keys($perCurrency), $perCurrency)) }}</td></tr>
                        @endforeach
                    </tfoot>
                </table>
            </x-filament::section>
        @endif
    </div>
</x-filament-panels::page>
