{{--
    Everything wrong with one hotel's rooming.

    No Tailwind utility classes: the panel has no custom theme, so its
    stylesheet carries Filament's own classes and nothing else — the lesson
    written into AGENTS.md after a whole modal rendered as unstyled running
    text. x-filament::callout takes `description`; it ignores its slot.
--}}
<div>
    @if ($problems === [])
        <x-filament::callout
            color="success"
            icon="heroicon-o-check-circle"
            heading="Nothing wrong with this rooming list"
            description="Everybody confirmed on this departure has exactly one bed in this hotel, no room is over capacity, and every room says who it is for."
        />
    @else
        <x-filament::callout
            color="danger"
            icon="heroicon-o-exclamation-triangle"
            :heading="trans_choice('{1}:count thing to fix|[2,*]:count things to fix', count($problems), ['count' => count($problems)])"
            description="None of this is fixed automatically. An allocator that shuffled real pilgrims on rules nobody has written down is how a mother ends up separated from her children."
        />

        @foreach ($problems as $problem)
            <x-filament::section :heading="$labels[$problem['type']] ?? $problem['type']" compact>
                <p>
                    @if ($problem['room'])
                        <strong>Room {{ $problem['room'] }}</strong> —
                    @endif
                    {{ $problem['detail'] }}
                </p>
                @if ($problem['who'])
                    <p>{{ $problem['who'] }}</p>
                @endif
            </x-filament::section>
        @endforeach
    @endif
</div>
