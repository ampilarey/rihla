{{--
    Who is not accounted for at this count.

    No Tailwind utility classes: the panel has no custom theme, so its
    stylesheet carries Filament's own classes and nothing else.
    x-filament::callout takes `description` and ignores its slot.
--}}
<div>
    @if ($rollCall->isSettled())
        <x-filament::callout
            color="success"
            icon="heroicon-o-check-circle"
            heading="Everybody is accounted for"
            description="Every traveller confirmed on this departure has been marked, and nobody is missing."
        />
    @else
        <x-filament::callout
            color="danger"
            icon="heroicon-o-exclamation-triangle"
            :heading="trans_choice('{1}:count person unaccounted for|[2,*]:count people unaccounted for', $rollCall->unaccountedFor()->count(), ['count' => $rollCall->unaccountedFor()->count()])"
            description="Nobody has said anything about the unmarked. An unmarked traveller is not present — they are the person to go and look for."
        />
    @endif

    @if ($unmarked->isNotEmpty())
        <x-filament::section heading="Nobody has marked these" compact>
            @foreach ($unmarked as $traveller)
                <p>{{ $traveller->full_name }}</p>
            @endforeach
        </x-filament::section>
    @endif

    @if ($absent->isNotEmpty())
        <x-filament::section heading="Marked not here" compact>
            @foreach ($absent as $mark)
                <p>
                    {{ $mark->traveller->full_name ?? 'Unknown' }}@if ($mark->note) — {{ $mark->note }}@endif
                </p>
            @endforeach
        </x-filament::section>
    @endif

    @if ($excused->isNotEmpty())
        <x-filament::section heading="Not here, and that is known and fine" compact>
            @foreach ($excused as $mark)
                <p>
                    {{ $mark->traveller->full_name ?? 'Unknown' }}@if ($mark->note) — {{ $mark->note }}@endif
                </p>
            @endforeach
        </x-filament::section>
    @endif
</div>
