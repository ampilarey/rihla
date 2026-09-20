<x-filament-panels::page>
    {{--
        Filament components, never Tailwind utilities: the panel has no
        custom theme, so `text-sm` and `space-y-4` do nothing here and the
        page would render as unstyled running text with every test still
        passing. AGENTS.md records what that cost once already.
    --}}
    @php($queue = $this->getQueue())

    @if ($queue->isEmpty())
        <x-filament::section>
            <x-slot name="heading">Nothing is waiting</x-slot>

            <x-filament::callout
                color="success"
                icon="heroicon-o-check-circle"
                heading="Nobody is waiting on a scholar"
                description="Articles, Ziyarah locations, learning modules and questions from pilgrims all appear here the moment somebody sends one for review."
            />
        </x-filament::section>
    @else
        <x-filament::section>
            <x-slot name="heading">
                {{ $queue->count() === 1 ? 'One thing is waiting on you' : $queue->count() . ' things are waiting on you' }}
            </x-slot>

            {{-- `afterHeader`, not `headerEnd`: Blade discards a slot the
                 component does not declare, without a word. --}}
            <x-slot name="afterHeader">
                @if ($queue->contains(fn ($item) => $item->isStale()))
                    <x-filament::badge color="danger">
                        {{ $queue->filter(fn ($item) => $item->isStale())->count() }} over a fortnight
                    </x-filament::badge>
                @endif
            </x-slot>

            <x-slot name="description">
                Oldest first. Signing off happens on the page itself, where the sources and the
                text are — a list you can approve from is a list of titles somebody presses a
                button on.
            </x-slot>

            <div>
                @foreach ($queue as $item)
                    <x-filament::callout
                        :color="$item->isStale() ? 'danger' : 'warning'"
                        :icon="$item->isStale() ? 'heroicon-o-exclamation-triangle' : 'heroicon-o-clock'"
                        :heading="$item->title"
                        :description="$item->kindLabel
                            . ' · waiting ' . $item->waitingFor()
                            . ($item->sourceCount === null
                                ? ''
                                : ' · ' . ($item->sourceCount === 1 ? '1 source' : $item->sourceCount . ' sources'))"
                    >
                        {{-- `footer` is declared; the slot is not, and body text
                             written between the tags disappears silently. --}}
                        <x-slot name="footer">
                            <x-filament::link :href="$item->url">Open it</x-filament::link>
                        </x-slot>
                    </x-filament::callout>
                @endforeach
            </div>
        </x-filament::section>
    @endif
</x-filament-panels::page>
