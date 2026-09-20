<x-filament-panels::page>
    {{-- Filament components only: Tailwind utilities do nothing in /staff. --}}
    @php($mine = $this->getMine())
    @php($others = $this->getEverybodyElse())
    @php($soon = $this->getSoon())
    @php($quiet = $this->getQuietCustomers())

    <x-filament::section>
        <x-slot name="heading">
            {{ $mine->isEmpty() ? 'Nothing of yours is due' : ($mine->count() === 1 ? 'One thing of yours is due' : $mine->count() . ' things of yours are due') }}
        </x-slot>

        <x-slot name="afterHeader">
            @php($late = $mine->filter->isOverdue())
            @if ($late->isNotEmpty())
                <x-filament::badge color="danger">{{ $late->count() }} overdue</x-filament::badge>
            @endif
        </x-slot>

        @forelse ($mine as $task)
            <x-filament::callout
                :color="$task->isOverdue() ? 'danger' : 'warning'"
                :icon="$task->isOverdue() ? 'heroicon-o-exclamation-triangle' : 'heroicon-o-clock'"
                :heading="$task->subject"
                :description="$task->whenLabel() . ' · ' . $task->aboutLabel() . ($task->detail ? ' · ' . $task->detail : '')"
            />
        @empty
            <x-filament::callout
                color="success"
                icon="heroicon-o-check-circle"
                heading="Nothing due"
                description="Nothing on your list is due today or overdue."
            />
        @endforelse
    </x-filament::section>

    @if ($soon->isNotEmpty())
        <x-filament::section collapsed>
            <x-slot name="heading">Yours, in the next week</x-slot>

            @foreach ($soon as $task)
                <x-filament::callout
                    color="gray"
                    icon="heroicon-o-calendar"
                    :heading="$task->subject"
                    :description="$task->whenLabel() . ' · ' . $task->aboutLabel()"
                />
            @endforeach
        </x-filament::section>
    @endif

    @if ($others->isNotEmpty())
        <x-filament::section collapsed>
            <x-slot name="heading">{{ $others->count() }} due elsewhere in the office</x-slot>

            <x-slot name="description">
                Collapsed on purpose. A shared list where nothing is yours is a list nobody works.
            </x-slot>

            @foreach ($others as $task)
                <x-filament::callout
                    :color="$task->isOverdue() ? 'danger' : 'gray'"
                    icon="heroicon-o-user"
                    :heading="$task->subject"
                    :description="$task->whenLabel() . ' · ' . ($task->owner?->name ?? 'nobody owns this') . ' · ' . $task->aboutLabel()"
                />
            @endforeach
        </x-filament::section>
    @endif

    @if ($quiet->isNotEmpty())
        <x-filament::section collapsed>
            <x-slot name="heading">{{ $quiet->count() }} who have gone quiet</x-slot>

            <x-slot name="description">
                Travelled with us, nothing since, and not already in a conversation or about to fly.
                Nothing is sent from here — this is a list to work through.
            </x-slot>

            @foreach ($quiet as $row)
                <x-filament::callout
                    color="gray"
                    icon="heroicon-o-phone"
                    :heading="$row['customer']->name"
                    :description="'Last travelled ' . $row['months'] . ' months ago'
                        . ($row['customer']->phone ? ' · ' . $row['customer']->phone : ' · no phone on file')
                        . ($row['customer']->tags->isNotEmpty() ? ' · ' . $row['customer']->tags->pluck('tag')->implode(', ') : '')"
                />
            @endforeach
        </x-filament::section>
    @endif
</x-filament-panels::page>
