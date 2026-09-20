<x-filament-panels::page>
    {{-- Filament components only: Tailwind utilities do nothing in /staff. --}}
    @php($journeys = $this->getJourneys())

    <x-filament::section>
        <x-slot name="heading">Which costs to count</x-slot>

        <x-slot name="description">
            An estimate is a plan, an agreed cost is a contract, a paid one is money that has
            left. The three give three different numbers, and only one of them is history.
        </x-slot>

        <x-filament::tabs>
            @foreach ($this->countingOptions() as $value => $label)
                <x-filament::tabs.item
                    :active="$this->countingUpTo === $value"
                    wire:click="$set('countingUpTo', '{{ $value }}')"
                >
                    {{ $label }}
                </x-filament::tabs.item>
            @endforeach
        </x-filament::tabs>
    </x-filament::section>

    @forelse ($journeys as $journey)
        <x-filament::section :collapsed="$journey->travellers === 0">
            <x-slot name="heading">
                {{ $journey->departure->package->title ?? 'Departure' }} —
                {{ $journey->departure->date_start->format('j M Y') }}
            </x-slot>

            {{-- `afterHeader`, not `headerEnd`: Blade discards a slot the
                 component does not declare, without a word. --}}
            <x-slot name="afterHeader">
                @php($margin = $journey->margin())
                {{-- Nothing for a journey nobody was on. A zero here reads
                     as "this one broke even", directly above a sentence
                     saying nobody travelled — the same contradiction the
                     customer page had. --}}
                @if ($margin !== null && $journey->travellers > 0)
                    <x-filament::badge :color="$margin->minor >= 0 ? 'success' : 'danger'">
                        {{ $margin }}
                    </x-filament::badge>
                @endif
            </x-slot>

            <x-slot name="description">
                {{ $journey->travellers }} {{ $journey->travellers === 1 ? 'traveller' : 'travellers' }} ·
                counting {{ $journey->countingLabel() }}
            </x-slot>

            @if ($journey->travellers === 0)
                <x-filament::callout
                    color="gray"
                    icon="heroicon-o-user-minus"
                    heading="Nobody travelled"
                    description="No confirmed booking on this departure, so there is no revenue to set costs against."
                />
            @else
                @foreach ($journey->currencies() as $currency)
                    @php($revenue = $journey->revenue->get($currency))
                    @php($cost = $journey->costs->get($currency))
                    @php($line = $journey->marginByCurrency()->get($currency))

                    <x-filament::callout
                        :color="$line->minor >= 0 ? 'success' : 'danger'"
                        icon="heroicon-o-banknotes"
                        :heading="$currency . ': ' . $line"
                        :description="'Took ' . ($revenue ?? 'nothing')
                            . ' · paid out ' . ($cost ?? 'nothing')"
                    />
                @endforeach

                @php($why = $journey->whyNoSingleFigure())

                @if ($why !== null)
                    {{-- Named rather than silent: a report that quietly
                         declines to total is one somebody assumes has
                         nothing to total. --}}
                    <x-filament::callout
                        color="warning"
                        icon="heroicon-o-exclamation-triangle"
                        heading="No single figure for this journey"
                        :description="$why"
                    />
                @else
                    @php($perHead = $journey->marginPerTraveller())
                    @if ($perHead !== null)
                        <x-filament::callout
                            color="gray"
                            icon="heroicon-o-user"
                            heading="{{ $perHead }} per traveller"
                            :description="$journey->rateNote() ?? 'Everything on this journey was in ' . $journey->baseCurrency() . ', so nothing needed converting.'"
                        />
                    @endif
                @endif

                @if ($journey->costsByCategory->isNotEmpty())
                    <x-filament::callout
                        color="gray"
                        icon="heroicon-o-list-bullet"
                        heading="Where the money went"
                        :description="$journey->costsByCategory
                            ->map(fn ($byCurrency, $category) => (new \App\Models\DepartureCost(['category' => $category]))->categoryLabel()
                                . ' ' . $byCurrency->map(fn ($m) => (string) $m)->implode(' + '))
                            ->implode(' · ')"
                    />
                @else
                    <x-filament::callout
                        color="warning"
                        icon="heroicon-o-question-mark-circle"
                        heading="No costs recorded"
                        description="The whole of the revenue is showing as margin, which it is not. Add what this departure cost on the Journey costs screen."
                    />
                @endif
            @endif
        </x-filament::section>
    @empty
        <x-filament::section>
            <x-slot name="heading">Nothing has departed yet</x-slot>

            <x-filament::callout
                color="gray"
                icon="heroicon-o-calendar"
                heading="No completed journeys"
                description="This report answers what a journey made, which needs the journey to have happened. A departure still selling has a forecast, not a margin."
            />
        </x-filament::section>
    @endforelse
</x-filament-panels::page>
