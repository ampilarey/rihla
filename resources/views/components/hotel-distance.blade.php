@props(['hotel'])

{{--
    "Swissotel Al Maqam · 300 m · 4 min walk to the Haram".

    The distance is the point. It is what a pilgrim is actually choosing
    between, and no operator in this market publishes it — everyone writes
    "5-star hotel" and leaves the walk to be discovered on arrival.

    Each part is omitted when it is not known, rather than guessed.
--}}
<div {{ $attributes->merge(['class' => 'flex flex-wrap items-baseline gap-x-2 gap-y-1']) }}>
    <span class="font-medium text-ink">{{ $hotel->name }}</span>

    @if($hotel->rating)
        <span class="text-sm text-ink-muted">{{ $hotel->rating }}</span>
    @endif

    @if($hotel->distance_label)
        {{-- dir="ltr" on the badge as a whole, not on each part. Marking the
             parts individually left the RTL page free to order the parts
             themselves right-to-left, so "150 m · 2 min walk" came out as
             "2 min walk · 150 m". A distance and the walk it implies read in
             that order in both languages. --}}
        <span dir="ltr" class="inline-flex items-center gap-1 rounded-full bg-cream-deep px-2 py-0.5 text-xs font-medium text-ink">
            <span>{{ $hotel->distance_label }}</span>
            @if($hotel->walk_minutes)
                <span aria-hidden="true">·</span>
                <span>{{ __('messages.:minutes min walk', ['minutes' => $hotel->walk_minutes]) }}</span>
            @endif
        </span>
    @endif

    @if($hotel->nights)
        <span dir="auto" class="text-sm text-ink-muted">
            {{ trans_choice('{1}:count night|[2,*]:count nights', $hotel->nights, ['count' => $hotel->nights]) }}
        </span>
    @endif
</div>
