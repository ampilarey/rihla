@props(['departure'])

{{--
    One departure, compact enough for a three-across row on the homepage.

    Everything on it is real or absent: the seats bar draws only when a seat
    count has been recorded, the price only when a tier exists, the hotels
    only when someone has entered them. A card that invents a number to look
    complete is worse than one with a gap.
--}}
<article class="card flex h-full flex-col p-5">
    <h3 class="text-lg font-bold text-ink">
        <a href="{{ route('packages.show', $departure->package->slug) }}"
           class="hover:text-wine-600 focus:outline-none focus:ring-2 focus:ring-wine-500 focus:ring-offset-2 rounded">
            <span dir="auto">{{ $departure->package->title }}</span>
        </a>
    </h3>

    <p class="mt-1 font-medium text-ink-muted" dir="auto">
        {{ $departure->date_start->translatedFormat('j M Y') }}
    </p>

    <div class="mt-2 flex flex-wrap items-center gap-x-4 gap-y-1">
        <x-departure-countdown :departure="$departure" />
        <span dir="auto" class="text-sm text-ink-muted">
            {{ trans_choice('{1}:count night|[2,*]:count nights', $departure->nights, ['count' => $departure->nights]) }}
        </span>
    </div>

    @if($departure->hotels->isNotEmpty())
        <ul class="mt-3 space-y-1">
            @foreach($departure->hotels->take(2) as $hotel)
                <li><x-hotel-distance :hotel="$hotel" /></li>
            @endforeach
        </ul>
    @endif

    <div class="mt-auto space-y-3 pt-4">
        @if($departure->lead_price)
            <div>
                <p class="text-sm text-ink-muted">{{ __('messages.From') }}</p>
                <p class="text-xl font-bold text-wine-600" dir="ltr">{{ $departure->lead_price->format() }}</p>
            </div>
        @endif

        <x-seats-bar :departure="$departure" />

        <a href="{{ route('packages.show', $departure->package->slug) }}" class="btn-primary w-full">
            {{ __('messages.View package') }}
        </a>
    </div>
</article>
