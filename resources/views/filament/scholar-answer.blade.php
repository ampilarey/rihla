{{--
    Filament components, not Tailwind utilities: this renders inside a modal
    in the `/staff` panel, where `text-sm` and `space-y-4` do nothing and
    the result is unstyled running text no test can see.
--}}
<x-filament::section>
    <x-slot name="heading">The answer</x-slot>

    <x-slot name="description">
        @if($question->scholar)
            Answered by {{ $question->scholar->name }}
        @endif
    </x-slot>

    <x-filament::callout
        color="info"
        icon="heroicon-o-chat-bubble-bottom-center-text"
        heading="What was said back"
        :description="$question->answer"
    />
</x-filament::section>
