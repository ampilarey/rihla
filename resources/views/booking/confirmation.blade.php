@extends('layouts.app')

@section('title', __('messages.Booking received'))

@section('content')
    @php($reference = $booking->reference)

    <div class="container mx-auto max-w-2xl px-4 section-y-tight">
        <div class="card text-center">
            <div class="mx-auto mb-4 flex h-14 w-14 items-center justify-center rounded-full bg-wine-50">
                <svg class="h-7 w-7 text-wine-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" d="m5 13 4 4L19 7" />
                </svg>
            </div>

            <h1 dir="auto" class="mb-2 text-3xl font-bold text-ink">{{ __('messages.Booking received') }}</h1>

            <p dir="auto" class="mb-6 text-brand-body">
                {{ __('messages.Quote this reference when you message us.') }}
            </p>

            <p class="mb-6 inline-block rounded-xl bg-cream px-6 py-3 text-2xl font-bold tracking-wide text-ink" dir="ltr">
                {{ $reference }}
            </p>

            <dl class="mb-6 space-y-2 text-start">
                <div class="flex justify-between gap-4 border-b border-cream-deep pb-2">
                    <dt dir="auto" class="text-ink-muted">{{ __('messages.Package') }}</dt>
                    <dd dir="auto" class="text-end font-medium text-ink">{{ $package->title }}</dd>
                </div>
                <div class="flex justify-between gap-4 border-b border-cream-deep pb-2">
                    <dt dir="auto" class="text-ink-muted">{{ __('messages.Departure') }}</dt>
                    <dd class="text-end font-medium text-ink" dir="ltr">
                        <x-local-date :date="$departure->date_start" />
                    </dd>
                </div>
                <div class="flex justify-between gap-4 border-b border-cream-deep pb-2">
                    <dt dir="auto" class="text-ink-muted">{{ __('messages.Travellers') }}</dt>
                    <dd class="text-end font-medium text-ink" dir="ltr">{{ $booking->seats }}</dd>
                </div>
                <div class="flex justify-between gap-4">
                    <dt dir="auto" class="text-ink-muted">{{ __('messages.Total') }}</dt>
                    <dd class="text-end font-bold text-ink" dir="ltr">{{ $booking->total()->format() }}</dd>
                </div>
            </dl>

            {{--
                Honest about the clock rather than implying a deadline nobody
                set. The seats are held for the configured window and no
                longer; how long a booking may sit unpaid is a policy decision
                the owner has not made, so this page does not invent one. If
                the hold has already lapsed the page still shows the reference
                — somebody returning to the tab an hour later needs it.
            --}}
            @if($hold !== null)
                <x-hold-timer :hold="$hold" class="mb-6 text-start" />
            @else
                <p dir="auto" class="mb-6 rounded-xl border border-cream-deep bg-cream p-4 text-start text-sm text-ink">
                    {{ __('messages.Your seats are no longer held, but your booking details are saved. Message us with the reference above and we will pick it up.') }}
                </p>
            @endif

            {{--
                Where to send the money, **only when somebody has actually
                said**. `$transfer` is null while no account is configured,
                and then this block does not render at all: an invented
                account number is not a placeholder, it is an instruction to
                a customer to send money somewhere. The WhatsApp button below
                is the route in the meantime, which is what happens today.
            --}}
            @if($transfer !== null)
                <div dir="auto" class="mb-6 rounded-xl border border-cream-deep bg-cream p-4 text-start">
                    <h2 class="mb-2 font-semibold text-ink">{{ __('messages.Paying by bank transfer') }}</h2>

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
                            {{-- Latin digits, left to right, whatever the page language. --}}
                            <dd class="text-end font-medium" dir="ltr">{{ $transfer['account'] }}</dd>
                        </div>
                        <div class="flex justify-between gap-4">
                            <dt class="text-ink-muted">{{ __('messages.Amount') }}</dt>
                            <dd class="text-end font-bold" dir="ltr">{{ $transfer['amount'] }}</dd>
                        </div>
                        <div class="flex justify-between gap-4">
                            <dt class="text-ink-muted">{{ __('messages.Reference') }}</dt>
                            <dd class="text-end font-medium" dir="ltr">{{ $reference }}</dd>
                        </div>
                    </dl>

                    <p class="mt-3 text-sm text-ink-muted">
                        {{ __('messages.Send us the slip and we will confirm once the money is in.') }}
                    </p>
                </div>
            @endif

            <a href="{{ \App\Support\Contact::whatsappUrl(__('messages.Hello Rihla, I would like to complete booking :reference.', ['reference' => $reference])) }}"
               class="btn-primary w-full"
               target="_blank" rel="noopener noreferrer">
                {{ __('messages.Complete on WhatsApp') }}
            </a>

            <p dir="auto" class="mt-4 text-sm text-ink-muted">
                {{ __('messages.Or call us on :number.', ['number' => \App\Support\Contact::displayNumber()]) }}
            </p>
        </div>
    </div>
@endsection
