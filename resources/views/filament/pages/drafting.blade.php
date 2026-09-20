<x-filament-panels::page>
    {{-- Filament components only: Tailwind utilities do nothing in /staff. --}}
    @php($why = $this->whyNotAvailable())

    @if ($why)
        <x-filament::section>
            <x-slot name="heading">Not set up yet</x-slot>

            <x-filament::callout
                color="warning"
                icon="heroicon-o-exclamation-triangle"
                heading="There is no assistant behind this page"
                :description="$why . ' Nothing below will produce anything until there is. The page is here so that the day somebody sets one up, it works — and so that nobody wonders whether it quietly already does.'"
            />
        </x-filament::section>
    @endif

    <x-filament::section>
        <x-slot name="heading">What do you need written?</x-slot>

        <x-slot name="description">
            Everything this produces is a first draft. Nothing is sent, saved, published or quoted —
            you copy it, edit it, and decide whether to use it.
        </x-slot>

        <x-filament::tabs>
            @foreach ($this->kindOptions() as $value => $label)
                <x-filament::tabs.item
                    :active="$kind === $value"
                    wire:click="$set('kind', '{{ $value }}')"
                >
                    {{ $label }}
                </x-filament::tabs.item>
            @endforeach
        </x-filament::tabs>

        <x-filament::callout
            color="gray"
            icon="heroicon-o-shield-exclamation"
            heading="No customer details in the notes"
            description="§9.6 prohibits personal data in prompts. Write the facts — dates, what is included, what is outstanding — and not the person's name, passport number or telephone. The draft comes back with gaps like [name] for you to fill in."
        />

        {{-- Filament's own wrapper, not Tailwind utilities: a `border`
             class does nothing in /staff, which is why the first version
             of this rendered as a textarea with no edges at all. --}}
        <x-filament::input.wrapper>
            <textarea
                wire:model="notes"
                rows="7"
                placeholder="The facts to write from — dates, what is included, what is outstanding."
                class="fi-input border-none bg-transparent focus:ring-0"
                {{-- An inline width, because `w-full` is a Tailwind utility
                     and there are none in /staff: without this the textarea
                     falls back to its default column count and the
                     placeholder wraps after forty characters inside a box
                     three times that wide. --}}
                style="width: 100%; padding: 0.5rem 0.75rem;"
            ></textarea>
        </x-filament::input.wrapper>

        <x-filament::button wire:click="draft" :disabled="$why !== null">
            Write a first draft
        </x-filament::button>
    </x-filament::section>

    @if ($result)
        <x-filament::section>
            <x-slot name="heading">A first draft</x-slot>

            {{-- The §9.6 label, above the text rather than beneath it: a
                 disclosure under a draft is read after the draft has been
                 believed. --}}
            <x-slot name="afterHeader">
                <x-filament::badge color="warning">AI-assisted — read it before you use it</x-filament::badge>
            </x-slot>

            <x-filament::callout color="gray" icon="heroicon-o-document-text" :description="$result" />
        </x-filament::section>
    @endif

    <x-filament::section :collapsible="true" :collapsed="true">
        <x-slot name="heading">Why there is no Dhivehi here</x-slot>

        <x-filament::callout
            color="gray"
            icon="heroicon-o-language"
            heading="This will not translate"
            :description="$this->whyNoTranslation()"
        />
    </x-filament::section>
</x-filament-panels::page>
