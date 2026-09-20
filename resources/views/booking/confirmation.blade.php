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
