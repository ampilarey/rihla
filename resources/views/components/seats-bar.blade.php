@props(['departure'])

{{--
    Honest urgency: "18 of 24 booked", with a bar.

    Drawn only when a seat count has actually been recorded. A departure with
    capacity_total of 0 is not "sold out" and not "empty" — nobody has said
    yet — and a full or empty bar would be a claim the data does not support.
    Backfilled departures all start that way, because the old trips table had
    nowhere to put a seat count.
--}}
@if($departure->has_capacity)
    @php($remaining = $departure->seats_remaining)
    @php($percent = $departure->percent_sold)

    <div {{ $attributes->merge(['class' => 'space-y-1.5']) }}>
        <div class="flex items-baseline justify-between text-sm">
            <span class="font-medium text-ink">
                @if($departure->is_sold_out)
                    {{ __('messages.Fully booked') }}
                @else
                    {{ trans_choice('{1}:count seat left|[2,*]:count seats left', $remaining, ['count' => $remaining]) }}
                @endif
            </span>
            <span class="text-ink-muted" dir="ltr">
                {{ $departure->seats_taken }} / {{ $departure->capacity_total }}
            </span>
        </div>

        {{--
            role="img" with a label, rather than a bare progress bar: the
            number above already says it, and a screen reader announcing a
            percentage adds nothing a pilgrim can act on.
        --}}
        <div class="h-2 w-full overflow-hidden rounded-full bg-cream-deep"
             role="img"
             aria-label="{{ __('messages.:taken of :total seats booked', ['taken' => $departure->seats_taken, 'total' => $departure->capacity_total]) }}">
            <div class="h-full rounded-full transition-all {{ $percent >= 85 ? 'bg-wine-600' : 'bg-wine-500' }}"
                 style="width: {{ max($percent, 2) }}%"></div>
        </div>
    </div>
@endif
