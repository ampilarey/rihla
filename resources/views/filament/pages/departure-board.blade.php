{{--
    The departure board — what is not ready, per upcoming departure.

    No Tailwind utility classes: the panel has no custom theme, so its
    stylesheet carries Filament's own classes and nothing else. Everything
    here is an x-filament:: component for that reason. x-filament::callout
    takes `description` and ignores its slot.
--}}
<x-filament-panels::page>
    @php($board = $this->getBoard())

    @if ($board->isEmpty())
        <x-filament::callout
            color="gray"
            icon="heroicon-o-calendar"
            heading="No upcoming departures"
            description="This board only looks forward. A departure that has already come home is history, not work."
        />
    @else
        @foreach ($board as $row)
            @php($departure = $row['departure'])

            <x-filament::section
                :heading="$departure->package?->title ?? 'Departure'"
                :description="$departure->date_start->format('j M Y') . ' — ' . $departure->date_start->diffForHumans()"
                collapsible
                :collapsed="$row['concerns'] === []"
            >
                {{--
                    `afterHeader`, not `headerEnd`. Blade discards an
                    unknown named slot without a word, so the whole badge
                    row rendered as nothing and every section header showed
                    only a chevron. Found by opening the page — nothing was
                    failing — and now guarded by
                    DepartureBoardTest::test_the_severity_badges_render.
                --}}
                <x-slot name="afterHeader">
                    @if ($row['blocking'] > 0)
                        <x-filament::badge color="danger">
                            {{ trans_choice('{1}:count blocker|[2,*]:count blockers', $row['blocking'], ['count' => $row['blocking']]) }}
                        </x-filament::badge>
                    @endif

                    @if ($row['attention'] > 0)
                        <x-filament::badge color="warning">
                            {{ trans_choice('{1}:count to look at|[2,*]:count to look at', $row['attention'], ['count' => $row['attention']]) }}
                        </x-filament::badge>
                    @endif

                    @if ($row['concerns'] === [])
                        <x-filament::badge color="success">Ready</x-filament::badge>
                    @endif
                </x-slot>

                @if ($row['concerns'] === [])
                    <x-filament::callout
                        color="success"
                        icon="heroicon-o-check-circle"
                        heading="Nothing outstanding"
                        description="Everybody confirmed can travel, the money is in, the rooming lists are settled and the group has a leader."
                    />
                @else
                    @foreach ($row['concerns'] as $concern)
                        <x-filament::callout
                            :color="$concern['severity'] === \App\Support\DepartureReadiness::BLOCKING ? 'danger' : 'warning'"
                            :icon="$concern['severity'] === \App\Support\DepartureReadiness::BLOCKING ? 'heroicon-o-no-symbol' : 'heroicon-o-exclamation-triangle'"
                            :heading="$concern['headline']"
                            :description="$concern['detail']"
                        />
                    @endforeach
                @endif

                @if ($row['unmatched_places'] !== [])
                    {{-- Not a concern: nothing is wrong with the departure.
                         It is the one thing only this office can fix — the
                         place is spelled differently in the itinerary than
                         in the guide, so the reading never reaches the
                         pilgrims going there. Said out loud rather than
                         letting the module disappear. --}}
                    <x-filament::callout
                        color="gray"
                        icon="heroicon-o-map-pin"
                        heading="Reading about a place this itinerary does not name"
                        :description="'Modules exist for ' . implode(', ', $row['unmatched_places']) . ', and nothing in this itinerary mentions them by that name. Pilgrims on this departure will not see them. Check the spelling in the itinerary, or leave it if the trip really does not go there.'"
                    />
                @endif
            </x-filament::section>
        @endforeach

        <x-filament::callout
            color="gray"
            icon="heroicon-o-information-circle"
            heading="Supplier contracts are not on this board"
            description="Flights and ground transport are checked above, from what is recorded under Travel → Flights & transport. Hotel contracts and other supplier arrangements are not recorded anywhere yet, and reporting them as fine from the absence of data would be a lie."
        />
    @endif
</x-filament-panels::page>
