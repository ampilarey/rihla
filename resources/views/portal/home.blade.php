@extends('layouts.app')

@section('title', __('messages.Your booking'))

@section('content')
    @php($balance = $booking->balance())

    <div class="container mx-auto max-w-3xl px-4 section-y-tight">

        <x-portal-nav current="home" />

        {{-- The booking itself. --}}
        <section class="card mb-6">
            <h1 dir="auto" class="mb-1 text-2xl font-bold text-ink">{{ $package->title }}</h1>

            <p class="mb-4 text-ink-muted" dir="ltr">
                <x-local-date :date="$departure->date_start" />
                &ndash;
                <x-local-date :date="$departure->date_end" />
            </p>

            <p class="mb-4 inline-block rounded-xl bg-cream px-4 py-2 font-bold tracking-wide text-ink" dir="ltr">
                {{ $booking->reference }}
            </p>

            <dl class="space-y-2">
                <div class="flex justify-between gap-4 border-b border-cream-deep pb-2">
                    <dt dir="auto" class="text-ink-muted">{{ __('messages.Travellers') }}</dt>
                    <dd class="text-end font-medium text-ink" dir="ltr">{{ $booking->seats }}</dd>
                </div>
                @if($departure->airline)
                    <div class="flex justify-between gap-4 border-b border-cream-deep pb-2">
                        <dt dir="auto" class="text-ink-muted">{{ __('messages.Airline') }}</dt>
                        <dd dir="auto" class="text-end font-medium text-ink">{{ $departure->airline }}</dd>
                    </div>
                @endif
                <div class="flex justify-between gap-4">
                    <dt dir="auto" class="text-ink-muted">{{ __('messages.Total') }}</dt>
                    <dd class="text-end font-bold text-ink" dir="ltr">{{ $booking->total()->format() }}</dd>
                </div>
            </dl>
        </section>

        {{--
            What each traveller still needs. Three separate answers rather
            than one score, because "not ready" is useless to the person who
            has to fix it and "we still need your passport" is something
            they can act on this afternoon.
        --}}
        @if(config('portal.sections.readiness'))
            <section class="card mb-6">
                <h2 dir="auto" class="mb-4 text-lg font-semibold text-ink">{{ __('messages.Before you travel') }}</h2>

                <ul class="divide-y divide-cream-deep">
                    @foreach($readiness as $name => $requirements)
                        @php($missing = array_keys(array_filter($requirements, fn (bool $met): bool => ! $met)))
                        <li class="py-3">
                            <span dir="auto" class="block font-medium text-ink">{{ $name }}</span>
                            @if($missing === [])
                                <span dir="auto" class="block text-sm text-green-700">
                                    {{ __('messages.Everything is in place.') }}
                                </span>
                            @else
                                <span dir="auto" class="block text-sm text-ink-muted">
                                    {{ __('messages.Still needed:') }}
                                    {{ implode(', ', \App\Support\PortalWords::requirements($missing)) }}
                                </span>
                            @endif
                        </li>
                    @endforeach
                </ul>

                <p dir="auto" class="mt-3 text-sm text-ink-muted">
                    {{ __('messages.A visa and an Umrah permit are two separate approvals. Having one does not mean you have the other.') }}
                </p>
            </section>
        @endif

        {{-- Money. --}}
        @if(config('portal.sections.payments'))
            <section class="card mb-6">
                <h2 dir="auto" class="mb-4 text-lg font-semibold text-ink">{{ __('messages.Payments') }}</h2>

                <dl class="mb-4 space-y-2">
                    <div class="flex justify-between gap-4 border-b border-cream-deep pb-2">
                        <dt dir="auto" class="text-ink-muted">{{ __('messages.Paid') }}</dt>
                        <dd class="text-end font-medium text-ink" dir="ltr">{{ $booking->paid()->format() }}</dd>
                    </div>
                    <div class="flex justify-between gap-4">
                        <dt dir="auto" class="text-ink-muted">{{ __('messages.Balance') }}</dt>
                        <dd class="text-end font-bold text-ink" dir="ltr">{{ $balance->format() }}</dd>
                    </div>
                </dl>

                {{--
                    A slip we have not checked yet is named, rather than left
                    to make the balance look wrong to somebody who knows they
                    sent the money yesterday.
                --}}
                @if($claimed > 0)
                    <p dir="auto" class="mb-4 rounded-xl border border-cream-deep bg-cream p-3 text-sm text-ink">
                        {{ __('messages.We have :amount waiting to be checked. It is not in the figures above yet.', [
                            'amount' => \App\Support\Money::ofMinor($claimed, $booking->currency)->format(),
                        ]) }}
                    </p>
                @endif

                @if($payments->isNotEmpty())
                    <ul class="divide-y divide-cream-deep">
                        @foreach($payments as $payment)
                            <li class="flex items-baseline justify-between gap-4 py-3">
                                <span class="min-w-0">
                                    <span dir="auto" class="block font-medium text-ink">
                                        {{ \App\Support\PortalWords::paymentStatus($payment->status) }}
                                    </span>
                                    @if($payment->paid_at)
                                        <span class="block text-sm text-ink-muted" dir="ltr">
                                            <x-local-date :date="$payment->paid_at" />
                                        </span>
                                    @endif
                                    {{-- A receipt only for money somebody has
                                         checked. Offering one for a claim
                                         would hand the customer a document
                                         saying it arrived before anybody
                                         looked. --}}
                                    @if($payment->status === \App\Models\Payment::SUCCEEDED)
                                        <a dir="auto"
                                           class="block text-sm font-medium text-wine-700 underline"
                                           href="{{ route('portal.receipt', ['payment' => $payment]) }}">
                                            {{ __('messages.Receipt') }}
                                        </a>
                                    @endif
                                </span>
                                <span class="shrink-0 font-medium text-ink" dir="ltr">{{ $payment->money()->format() }}</span>
                            </li>
                        @endforeach
                    </ul>
                @endif

                <a dir="auto"
                   class="mt-4 inline-block text-sm font-medium text-wine-700 underline"
                   href="{{ route('portal.invoice') }}">
                    {{ __('messages.Download the invoice') }}
                </a>

                {{--
                    Where to send it — only when somebody has actually said.
                    While no account is configured this block does not render
                    at all: an invented account number is an instruction to
                    send money somewhere.
                --}}
                @if($transfer !== null && $balance->minor > 0)
                    <div dir="auto" class="mt-4 rounded-xl border border-cream-deep bg-cream p-4">
                        <h3 class="mb-2 font-semibold text-ink">{{ __('messages.Paying by bank transfer') }}</h3>
                        <dl class="space-y-1 text-sm text-ink">
                            @if($transfer['bank'])
                                <div class="flex justify-between gap-4">
                                    <dt class="text-ink-muted">{{ __('messages.Bank') }}</dt>
                                    <dd class="text-end font-medium">{{ $transfer['bank'] }}</dd>
                                </div>
                            @endif
                            @if($transfer['account_name'])
                                <div class="flex justify-between gap-4">
                                    <dt class="text-ink-muted">{{ __('messages.Account name') }}</dt>
                                    <dd class="text-end font-medium">{{ $transfer['account_name'] }}</dd>
                                </div>
                            @endif
                            <div class="flex justify-between gap-4">
                                <dt class="text-ink-muted">{{ __('messages.Account number') }}</dt>
                                <dd class="text-end font-medium" dir="ltr">{{ $transfer['account'] }}</dd>
                            </div>
                            <div class="flex justify-between gap-4">
                                <dt class="text-ink-muted">{{ __('messages.Reference') }}</dt>
                                <dd class="text-end font-medium" dir="ltr">{{ $booking->reference }}</dd>
                            </div>
                        </dl>
                    </div>
                @endif

                @if(config('portal.uploads.enabled') && $balance->minor > 0)
                    <x-portal-slip-form :booking="$booking" :balance="$balance" />
                @endif
            </section>
        @endif

        {{-- Where you are staying. Real rows or nothing. --}}
        @if(config('portal.sections.hotels') && $departure->hotels->isNotEmpty())
            <section class="card mb-6">
                <h2 dir="auto" class="mb-4 text-lg font-semibold text-ink">{{ __('messages.Hotels') }}</h2>
                <ul class="space-y-2">
                    @foreach($departure->hotels as $hotel)
                        {{-- The name is printed by x-hotel-distance, not
                             here: the first draft printed it as well and the
                             hotel appeared twice on the page. --}}
                        <li dir="auto" class="text-ink">
                            <span class="text-sm text-ink-muted">{{ $hotel->cityLabel() }}</span>
                            <x-hotel-distance :hotel="$hotel" />
                        </li>
                    @endforeach
                </ul>
            </section>
        @endif

        {{-- Day by day, when the departure has one entered. --}}
        @if(config('portal.sections.itinerary') && $departure->itinerary->isNotEmpty())
            <section class="card mb-6">
                <h2 dir="auto" class="mb-4 text-lg font-semibold text-ink">{{ __('messages.Your days') }}</h2>
                <ol class="divide-y divide-cream-deep">
                    @foreach($departure->itinerary as $day)
                        <li class="py-3">
                            <span dir="auto" class="block font-medium text-ink">
                                {{ __('messages.Day :number', ['number' => $day->day_number]) }} &middot; {{ $day->title }}
                            </span>
                            @if($day->description)
                                <span dir="auto" class="block text-sm text-ink-muted">{{ $day->description }}</span>
                            @endif
                        </li>
                    @endforeach
                </ol>
            </section>
        @endif

        <x-portal-footer />
    </div>
@endsection
