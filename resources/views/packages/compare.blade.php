@extends('layouts.app')

@section('title', __('messages.Compare departures'))

@section('content')
    <div class="container mx-auto px-4 section-y-tight">
        <header class="mb-8">
            <h1 class="section-title">{{ __('messages.Compare departures') }}</h1>
        </header>

        @if($departures->isEmpty())
            <div class="card p-8 text-center">
                <p class="mb-4 text-brand-body">
                    {{ __('messages.Choose two or three departures on the packages page to compare them here.') }}
                </p>
                <a href="{{ route('packages.index') }}" class="btn-primary">
                    {{ __('messages.Browse packages') }}
                </a>
            </div>
        @else
            @if($departures->count() === 1)
                <p class="mb-6 text-brand-body">
                    {{ __('messages.Pick at least one more departure to see them side by side.') }}
                </p>
            @endif

            {{--
                Scrolls sideways on a phone rather than shrinking the columns
                to nothing. Three columns plus a label column does not fit a
                375px screen, and a table squeezed to fit is unreadable —
                which is the problem this page exists to solve.
            --}}
            <div class="overflow-x-auto">
                <table class="w-full min-w-[40rem] border-collapse text-sm">
                    <caption class="sr-only">
                        {{ __('messages.Departures compared side by side') }}
                    </caption>

                    <thead>
                        <tr>
                            <th scope="col" class="w-40 p-3 text-start align-bottom">
                                <span class="sr-only">{{ __('messages.Detail') }}</span>
                            </th>
                            @foreach($departures as $departure)
                                <th scope="col" class="border-b-2 border-wine-500 p-3 text-start align-bottom">
                                    <a href="{{ route('packages.show', $departure->package->slug) }}"
                                       class="text-base font-bold text-ink hover:text-wine-600">
                                        <span dir="auto">{{ $departure->package->title }}</span>
                                    </a>
                                </th>
                            @endforeach
                        </tr>
                    </thead>

                    <tbody class="divide-y divide-cream-deep">
                        <tr>
                            <th scope="row" class="p-3 text-start font-semibold text-ink-muted">{{ __('messages.Dates') }}</th>
                            @foreach($departures as $departure)
                                <td class="p-3 align-top" dir="auto">
                                    <x-local-date :date="$departure->date_start" />
                                    <span aria-hidden="true">–</span>
                                    <x-local-date :date="$departure->date_end" />
                                    <div class="mt-1"><x-departure-countdown :departure="$departure" /></div>
                                </td>
                            @endforeach
                        </tr>

                        <tr>
                            <th scope="row" class="p-3 text-start font-semibold text-ink-muted">{{ __('messages.Duration') }}</th>
                            @foreach($departures as $departure)
                                <td class="p-3 align-top" dir="auto">
                                    {{ trans_choice('{1}:count night|[2,*]:count nights', $departure->nights, ['count' => $departure->nights]) }}
                                </td>
                            @endforeach
                        </tr>

                        <tr>
                            <th scope="row" class="p-3 text-start font-semibold text-ink-muted">{{ __('messages.Airline') }}</th>
                            @foreach($departures as $departure)
                                <td class="p-3 align-top">{{ $departure->airline ?: '—' }}</td>
                            @endforeach
                        </tr>

                        {{-- One row per occupancy any of them prices. A blank
                             cell means that departure does not offer that
                             room, which is worth seeing. --}}
                        @foreach($occupancies as $occupancy)
                            <tr>
                                <th scope="row" class="p-3 text-start font-semibold text-ink-muted">
                                    {{ __('messages.'.ucfirst($occupancy).' room') }}
                                </th>
                                @foreach($departures as $departure)
                                    @php($tier = $departure->priceTiers->firstWhere('occupancy', $occupancy))
                                    <td class="p-3 align-top font-semibold text-ink" dir="ltr">
                                        {{ $tier?->formatted ?? '—' }}
                                    </td>
                                @endforeach
                            </tr>
                        @endforeach

                        <tr>
                            <th scope="row" class="p-3 text-start font-semibold text-ink-muted">{{ __('messages.Hotels') }}</th>
                            @foreach($departures as $departure)
                                <td class="p-3 align-top">
                                    @forelse($departure->hotels as $hotel)
                                        <div class="mb-1.5"><x-hotel-distance :hotel="$hotel" /></div>
                                    @empty
                                        <span class="text-ink-muted">—</span>
                                    @endforelse
                                </td>
                            @endforeach
                        </tr>

                        <tr>
                            <th scope="row" class="p-3 text-start font-semibold text-ink-muted">{{ __('messages.Seats') }}</th>
                            @foreach($departures as $departure)
                                <td class="p-3 align-top">
                                    @if($departure->has_capacity)
                                        <x-seats-bar :departure="$departure" />
                                    @else
                                        <span class="text-ink-muted">—</span>
                                    @endif
                                </td>
                            @endforeach
                        </tr>

                        <tr>
                            <th scope="row" class="p-3 text-start font-semibold text-ink-muted">{{ __('messages.Included') }}</th>
                            @foreach($departures as $departure)
                                <td class="p-3 align-top">
                                    @forelse($departure->package->inclusion_list as $item)
                                        <div class="flex gap-1.5" dir="auto">
                                            <span class="text-wine-500" aria-hidden="true">✓</span>
                                            <span>{{ $item }}</span>
                                        </div>
                                    @empty
                                        <span class="text-ink-muted">—</span>
                                    @endforelse
                                </td>
                            @endforeach
                        </tr>

                        <tr>
                            <th scope="row" class="p-3"><span class="sr-only">{{ __('messages.Ask about it') }}</span></th>
                            @foreach($departures as $departure)
                                <td class="p-3 align-top">
                                    <a href="{{ route('packages.show', $departure->package->slug) }}" class="btn-secondary w-full">
                                        {{ __('messages.View package') }}
                                    </a>
                                </td>
                            @endforeach
                        </tr>
                    </tbody>
                </table>
            </div>

            <p class="mt-6">
                <a href="{{ route('packages.index') }}" class="text-wine-600 hover:underline">
                    {{ __('messages.Choose different departures') }}
                </a>
            </p>
        @endif
    </div>
@endsection
