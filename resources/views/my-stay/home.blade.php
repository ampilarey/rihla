@extends('layouts.app')

@section('title', __('messages.Your stay'))

{{--
    The guest's own stay — §16.7 (Phase 13.3).

    Every state is said in words a guest uses, and every figure is read from
    the stay's own snapshot, never recomputed from the property: the guest
    agreed to what the page said on the day.
--}}
@section('content')
<div class="container mx-auto max-w-3xl px-4 section-y-tight">
    @if($freshLink)
        {{-- Shown once. The link is the guest's way back, on any device. --}}
        <div role="status" class="mb-6 rounded-xl border-s-4 border-s-success bg-cream-deep px-4 py-3 text-sm text-ink">
            <p dir="auto" class="font-medium">{{ __('messages.Keep this link — it opens this page on any phone for the next 30 days:') }}</p>
            <p class="mt-1 break-all" dir="ltr"><a href="{{ $freshLink }}" class="text-wine-700 underline">{{ $freshLink }}</a></p>
        </div>
    @endif

    <header class="mb-6">
        <p dir="auto" class="text-sm text-ink-muted">{{ __('messages.Reference') }} <span dir="ltr" class="font-medium text-ink">{{ $stay->reference }}</span></p>
        <h1 dir="auto" class="section-title text-start">{{ $property->name }}</h1>
        <p dir="auto" class="text-ink-muted">
            {{ $stay->roomType?->name }} · {{ $stay->check_in->isoFormat('D MMM YYYY') }} – {{ $stay->check_out->isoFormat('D MMM YYYY') }}
        </p>
    </header>

    <section class="card mb-6" aria-labelledby="my-stay-status-heading">
        <h2 id="my-stay-status-heading" dir="auto" class="mb-2 text-lg font-bold text-ink">{{ __('messages.Where it stands') }}</h2>
        <p dir="auto" class="text-brand-body">
            @switch($stay->status)
                @case(\App\Models\Stay::REQUESTED)
                    {{ __('messages.We have asked the host. We will confirm within 24 hours, and you pay nothing until then.') }}
                    @break
                @case(\App\Models\Stay::HELD)
                    {{ __('messages.The host has your room. Pay the deposit to keep it.') }}
                    @if($stay->expires_at)
                        <strong>{{ __('messages.Held until :time.', ['time' => $stay->expires_at->isoFormat('D MMM YYYY, HH:mm')]) }}</strong>
                    @endif
                    @break
                @case(\App\Models\Stay::CONFIRMED)
                    {{ __('messages.Your stay is booked.') }}
                    @break
                @case(\App\Models\Stay::CHECKED_IN)
                    {{ __('messages.Welcome — you are checked in.') }}
                    @break
                @case(\App\Models\Stay::COMPLETED)
                    {{ __('messages.Thank you for staying.') }}
                    @break
                @case(\App\Models\Stay::DECLINED)
                    {{ __('messages.The host could not take this booking. You have paid nothing.') }}
                    @break
                @case(\App\Models\Stay::EXPIRED)
                    {{ __('messages.The hold ran out before the deposit arrived, so the room went back on sale.') }}
                    @break
                @default
                    {{ __('messages.This stay is cancelled.') }}
            @endswitch
        </p>
    </section>

    @if(session('status'))
        <p role="status" dir="auto" class="mb-6 rounded-xl border-s-4 border-s-success bg-cream-deep px-4 py-3 text-sm text-ink">{{ session('status') }}</p>
    @endif

    {{-- §16.11: once the stay is over, the review — or the one they wrote. --}}
    @if($stay->status === \App\Models\Stay::COMPLETED)
        @include('stays._review', ['stay' => $stay, 'action' => route('my-stay.review')])
    @endif

    <section class="card mb-6" aria-labelledby="my-stay-bill-heading">
        <h2 id="my-stay-bill-heading" dir="auto" class="mb-3 text-lg font-bold text-ink">{{ __('messages.The bill') }}</h2>
        <dl dir="auto" class="space-y-2 text-sm text-ink">
            <div class="flex justify-between gap-4">
                <dt class="text-ink-muted">{{ trans_choice('messages.:count night|:count nights', $stay->nights, ['count' => $stay->nights]) }}</dt>
                <dd class="text-end">{{ $stay->total()->format() }}</dd>
            </div>
            @foreach($stay->charges as $charge)
                <div class="flex justify-between gap-4">
                    <dt class="text-ink-muted">{{ $charge->description }}</dt>
                    <dd class="text-end">{{ $charge->total()->format() }}</dd>
                </div>
            @endforeach
            <div class="flex justify-between gap-4">
                <dt class="text-ink-muted">{{ __('messages.Deposit') }}</dt>
                <dd class="text-end">{{ $stay->deposit()->format() }}</dd>
            </div>
            <div class="flex justify-between gap-4">
                <dt class="text-ink-muted">{{ __('messages.Received') }}</dt>
                <dd class="text-end">{{ $stay->paid()->format() }}</dd>
            </div>
        </dl>

        @if($stay->payments->where('status', \App\Models\Payment::AWAITING_REVIEW)->isNotEmpty())
            <p dir="auto" class="mt-3 text-sm text-ink-muted">{{ __('messages.We have your slip and are checking it against our account.') }}</p>
        @endif

        @if($due)
            <p dir="auto" class="mt-4 font-medium text-ink">{{ __('messages.To pay now: :amount', ['amount' => $due->format()]) }}</p>

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
                        <div class="flex justify-between gap-4"><dt class="text-ink-muted">{{ __('messages.Reference') }}</dt><dd class="text-end font-medium" dir="ltr">{{ $stay->reference }}</dd></div>
                    </dl>
                </div>
            @endif

            @if($canSendSlip)
                <x-portal-slip-form :balance="$due" :action="route('my-stay.payments.store')" />
            @else
                <p dir="auto" class="mt-3 text-sm text-ink-muted">{{ __('messages.Message us and we will tell you how to pay.') }}</p>
            @endif
        @endif
    </section>

    <section class="card mb-6" aria-labelledby="my-stay-cancel-heading">
        <h2 id="my-stay-cancel-heading" dir="auto" class="mb-2 text-lg font-bold text-ink">{{ __('messages.Changing your plans') }}</h2>
        @if($canCancel)
            <p dir="auto" class="mb-4 text-sm text-brand-body">
                {{ __('messages.You can cancel free of charge until :date.', ['date' => $freeCancelUntil->isoFormat('D MMM YYYY')]) }}
            </p>
            <form method="POST" action="{{ route('my-stay.cancel') }}">
                @csrf
                <button type="submit" class="btn-outline">{{ __('messages.Cancel this stay') }}</button>
            </form>
        @elseif(in_array($stay->status, [\App\Models\Stay::HELD, \App\Models\Stay::CONFIRMED], true))
            {{-- Outside the window: say what the policy keeps and offer a
                 person, rather than a button that would refuse. --}}
            <p dir="auto" class="mb-4 text-sm text-brand-body">
                {{ __('messages.The free cancellation window has passed, so the deposit is kept under the terms you agreed. Message us and we will see what we can do.') }}
            </p>
            <a href="{{ \App\Support\Contact::whatsappUrl(__('messages.Hello Rihla, I need to change my stay :reference.', ['reference' => $stay->reference])) }}"
               class="btn-secondary" target="_blank" rel="noopener noreferrer">{{ __('messages.Message us on WhatsApp') }}</a>
        @else
            <a href="{{ \App\Support\Contact::whatsappUrl(__('messages.Hello Rihla, I need to change my stay :reference.', ['reference' => $stay->reference])) }}"
               class="btn-secondary" target="_blank" rel="noopener noreferrer">{{ __('messages.Message us on WhatsApp') }}</a>
        @endif
    </section>

    @if(! in_array($stay->status, [\App\Models\Stay::DECLINED, \App\Models\Stay::EXPIRED, \App\Models\Stay::CANCELLED], true))
        <p class="mb-6 text-center">
            <a href="{{ route('my-stay.confirmation') }}" class="text-sm text-wine-700 underline hover:no-underline">
                {{ __('messages.Download your stay summary (PDF)') }}
            </a>
        </p>
    @endif

    <form method="POST" action="{{ route('my-stay.leave') }}" class="text-center">
        @csrf
        <button type="submit" class="text-sm text-wine-700 underline hover:no-underline">{{ __('messages.Sign out of this page') }}</button>
    </form>
</div>
@endsection
