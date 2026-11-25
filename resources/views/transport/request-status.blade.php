@extends('layouts.app')

@section('title', __('messages.Your request :reference', ['reference' => $made->reference]))

{{-- A cargo or charter request, followed by the asker — docs/TRANSPORT_PLAN.md (T4). Found by a random key. --}}
@section('content')
<div class="container mx-auto max-w-3xl px-4 section-y-tight">
    <header class="mb-6">
        <h1 dir="auto" class="section-title text-start">{{ __('messages.Your request :reference', ['reference' => $made->reference]) }}</h1>
        <p dir="auto" class="text-brand-body">
            {{ $made->kind === 'cargo' ? __('messages.Send cargo') : __('messages.Charter a boat') }}
            · {{ $made->origin?->name }} → {{ $made->destination?->name }} · {{ $made->wanted_on->isoFormat('dddd D MMMM YYYY') }}
        </p>
        <p dir="auto" class="mt-1 text-sm text-ink-muted">{{ __('messages.Keep this page: its address is how you follow your request.') }}</p>
    </header>

    <section class="card mb-6" aria-labelledby="req-status-heading">
        <h2 id="req-status-heading" dir="auto" class="mb-3 text-lg font-bold text-ink">{{ __('messages.Where it is') }}</h2>
        <ol dir="auto" class="space-y-2 text-sm text-ink">
            @foreach($made->events as $event)
                <li class="flex justify-between gap-4">
                    <span class="font-medium">{{ __('messages.'.(\Modules\Transport\Models\TransportRequest::STATUSES[$event->status] ?? $event->status)) }}</span>
                    <span class="text-ink-muted">{{ $event->created_at?->isoFormat('D MMM, HH:mm') }}</span>
                </li>
            @endforeach
        </ol>

        @if($made->status === \Modules\Transport\Models\TransportRequest::REQUESTED)
            <p dir="auto" class="mt-4 text-brand-body">{{ __('messages.We are working out a price and will message you.') }}</p>
        @elseif($made->status === \Modules\Transport\Models\TransportRequest::CANCELLED)
            <p dir="auto" class="mt-4 text-brand-body">{{ __('messages.This request is closed.') }} {{ $made->cancelled_reason }}</p>
        @endif
    </section>

    @if($made->quote())
        <section class="card mb-6" aria-labelledby="req-quote-heading">
            <h2 id="req-quote-heading" dir="auto" class="mb-3 text-lg font-bold text-ink">{{ __('messages.Price') }}</h2>
            <dl dir="auto" class="space-y-2 text-sm text-ink">
                <div class="flex justify-between gap-4"><dt class="text-ink-muted">{{ __('messages.Total') }}</dt><dd class="text-end font-medium">{{ $made->quote()->format() }}</dd></div>
                <div class="flex justify-between gap-4"><dt class="text-ink-muted">{{ __('messages.Reference') }}</dt><dd class="text-end font-medium" dir="ltr">{{ $made->reference }}</dd></div>
            </dl>
            @if($made->quote_note)
                <p dir="auto" class="mt-3 text-brand-body">{{ $made->quote_note }}</p>
            @endif

            @if($made->status === \Modules\Transport\Models\TransportRequest::QUOTED)
                <p dir="auto" class="mt-4 font-medium text-ink">{{ __('messages.To pay now: :amount', ['amount' => $made->owed()->format()]) }}</p>
                @if($transfer !== null)
                    <div dir="auto" class="mt-4 rounded-xl border border-gray-300 bg-cream p-4">
                        <h3 class="mb-2 font-semibold text-ink">{{ __('messages.Paying by bank transfer') }}</h3>
                        <dl class="space-y-1 text-sm text-ink">
                            @if($transfer['bank'])
                                <div class="flex justify-between gap-4"><dt class="text-ink-muted">{{ __('messages.Bank') }}</dt><dd class="text-end font-medium">{{ $transfer['bank'] }}</dd></div>
                            @endif
                            @if($transfer['account_name'])
                                <div class="flex justify-between gap-4"><dt class="text-ink-muted">{{ __('messages.Account name') }}</dt><dd class="text-end font-medium">{{ $transfer['account_name'] }}</dd></div>
                            @endif
                            <div class="flex justify-between gap-4"><dt class="text-ink-muted">{{ __('messages.Account number') }}</dt><dd class="text-end font-medium" dir="ltr">{{ $transfer['account'] }}</dd></div>
                            <div class="flex justify-between gap-4"><dt class="text-ink-muted">{{ __('messages.Reference') }}</dt><dd class="text-end font-medium" dir="ltr">{{ $made->reference }}</dd></div>
                        </dl>
                    </div>
                @endif
                <p dir="auto" class="mt-3 text-sm text-ink-muted">{{ __('messages.Or pay in cash at our office. We move your request on as soon as the money arrives.') }}</p>
            @endif
        </section>
    @endif
</div>
@endsection
