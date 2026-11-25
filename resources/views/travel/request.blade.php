@extends('layouts.app')

@section('title', __('messages.Your request :reference', ['reference' => $made->reference]))

{{-- A trip request, followed and chosen by the customer — docs/TRAVEL_AGENCY_PLAN.md (A1). Found by a random key. --}}
@section('content')
@php($T = \Modules\Travel\Models\TravelRequest::class)
<div class="container mx-auto max-w-3xl px-4 section-y-tight">
    <header class="mb-6">
        <h1 dir="auto" class="section-title text-start">{{ __('messages.Your request :reference', ['reference' => $made->reference]) }}</h1>
        <p dir="auto" class="text-brand-body">
            {{ $made->origin ? $made->origin.' → ' : '' }}{{ $made->destination }}
            · {{ $made->depart_on->isoFormat('D MMM YYYY') }}@if($made->return_on) – {{ $made->return_on->isoFormat('D MMM YYYY') }}@endif
            · {{ trans_choice('messages.:count traveller|:count travellers', $made->adults + $made->children, ['count' => $made->adults + $made->children]) }}
        </p>
        <p dir="auto" class="mt-1 text-sm text-ink-muted">{{ __('messages.Keep this page: its address is how you follow your request.') }}</p>
    </header>

    @if(session('status'))
        <div role="status" dir="auto" class="card mb-6 border-s-4 border-s-success">{{ session('status') }}</div>
    @endif
    @if($errors->any())
        <div role="alert" dir="auto" class="card mb-6 border-s-4 border-s-error">{{ $errors->first() }}</div>
    @endif

    @if($made->status === $T::REQUESTED)
        <p dir="auto" class="card mb-6 text-brand-body">{{ __('messages.We are finding options and will message you when they are ready.') }}</p>
    @elseif($made->status === $T::CANCELLED)
        <p dir="auto" class="card mb-6 text-brand-body">{{ __('messages.This request is closed.') }} {{ $made->cancelled_reason }}</p>
    @elseif($made->status === $T::QUOTED)
        <section class="mb-6" aria-labelledby="trip-options-heading">
            <h2 id="trip-options-heading" dir="auto" class="mb-1 text-lg font-bold text-ink">{{ __('messages.Your options') }}</h2>
            @if($made->quoteIsLive())
                <p dir="auto" class="mb-3 text-sm text-ink-muted">{{ __('messages.Choose one by :date. Prices can change after that.', ['date' => $made->quote_expires_at->isoFormat('D MMM')]) }}</p>
            @else
                <p dir="auto" class="mb-3 text-sm text-ink">{{ __('messages.These prices have expired. Message us and we will check them again.') }}</p>
            @endif
            <ul class="space-y-3">
                @foreach($made->options as $option)
                    <li class="card">
                        <div class="flex flex-wrap items-start justify-between gap-4">
                            <div dir="auto">
                                <p class="font-bold text-ink">{{ $option->title }}</p>
                                @if($option->details)
                                    <p class="mt-1 whitespace-pre-line text-sm text-brand-body">{{ $option->details }}</p>
                                @endif
                            </div>
                            <p class="text-lg font-bold text-ink">{{ $option->price()->format() }}</p>
                        </div>
                        @if($made->quoteIsLive())
                            <form method="POST" action="{{ route('travel.requests.accept', ['key' => $made->status_key]) }}" class="mt-3">
                                @csrf
                                <input type="hidden" name="option" value="{{ $option->id }}">
                                <button type="submit" class="btn-primary">{{ __('messages.Choose this option') }}</button>
                            </form>
                        @endif
                    </li>
                @endforeach
            </ul>
        </section>
    @else
        <section class="card mb-6" aria-labelledby="trip-chosen-heading">
            <h2 id="trip-chosen-heading" dir="auto" class="mb-3 text-lg font-bold text-ink">{{ __('messages.Your choice') }}</h2>
            <p dir="auto" class="font-bold text-ink">{{ $made->acceptedOption?->title }}</p>
            @if($made->acceptedOption?->details)
                <p dir="auto" class="mt-1 whitespace-pre-line text-sm text-brand-body">{{ $made->acceptedOption->details }}</p>
            @endif
            <dl dir="auto" class="mt-3 space-y-2 text-sm text-ink">
                <div class="flex justify-between gap-4"><dt class="text-ink-muted">{{ __('messages.Total') }}</dt><dd class="text-end font-medium">{{ $made->total()?->format() }}</dd></div>
                <div class="flex justify-between gap-4"><dt class="text-ink-muted">{{ __('messages.Reference') }}</dt><dd class="text-end font-medium" dir="ltr">{{ $made->reference }}</dd></div>
            </dl>

            @if($made->status === $T::ACCEPTED)
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
                <p dir="auto" class="mt-3 text-sm text-ink-muted">{{ __('messages.Or pay in cash at our office. We book as soon as the money arrives.') }}</p>
            @elseif($made->status === $T::PAID)
                <p dir="auto" class="mt-4 font-medium text-ink">{{ __('messages.Paid. We are booking it and will send your tickets here.') }}</p>
            @elseif($made->status === $T::ISSUED)
                <p dir="auto" class="mt-4 font-medium text-ink">{{ __('messages.Booked. Your tickets and documents are below.') }}</p>
            @endif
        </section>
    @endif

    @foreach($made->visaApplications as $visa)
        <section class="card mb-6" aria-labelledby="visa-{{ $visa->id }}-heading">
            <h2 id="visa-{{ $visa->id }}-heading" dir="auto" class="mb-1 text-lg font-bold text-ink">
                {{ __('messages.Visa: :what for :name', ['what' => $visa->product->label(), 'name' => $visa->applicant_name]) }}
            </h2>
            <p dir="auto" class="mb-3 text-sm text-ink-muted">{{ __('messages.'.\Modules\Travel\Models\TravelVisaApplication::STATUSES[$visa->status]) }}@if($visa->decision_note) · {{ $visa->decision_note }}@endif</p>
            <ul class="space-y-3">
                @foreach($visa->checklist as $i => $line)
                    <li class="flex flex-wrap items-center justify-between gap-3">
                        <span dir="auto" class="text-ink">{{ $line['item'] }}</span>
                        @if($line['received_at'])
                            <span dir="auto" class="text-sm font-medium text-success-dark">{{ __('messages.Received') }}</span>
                        @elseif($visa->status === \Modules\Travel\Models\TravelVisaApplication::COLLECTING)
                            <form method="POST" enctype="multipart/form-data" class="flex flex-wrap items-center gap-2"
                                  action="{{ route('travel.requests.visa-document', ['key' => $made->status_key, 'application' => $visa->id, 'item' => $i]) }}">
                                @csrf
                                <label class="sr-only" for="visa-{{ $visa->id }}-{{ $i }}">{{ $line['item'] }}</label>
                                <input id="visa-{{ $visa->id }}-{{ $i }}" type="file" name="file" required accept=".pdf,.jpg,.jpeg,.png" class="text-sm text-ink">
                                <button type="submit" class="btn-primary">{{ __('messages.Send') }}</button>
                            </form>
                        @endif
                    </li>
                @endforeach
            </ul>
        </section>
    @endforeach

    @if($made->documents->isNotEmpty())
        <section class="card mb-6" aria-labelledby="trip-docs-heading">
            <h2 id="trip-docs-heading" dir="auto" class="mb-3 text-lg font-bold text-ink">{{ __('messages.Your tickets and documents') }}</h2>
            <ul class="space-y-2">
                @foreach($made->documents as $document)
                    <li class="flex flex-wrap items-center justify-between gap-3">
                        <span dir="auto" class="text-ink">{{ $document->label }} <span class="text-sm text-ink-muted">· {{ __('messages.'.\Modules\Travel\Models\TravelDocument::KINDS[$document->kind]) }}</span></span>
                        <a href="{{ route('travel.requests.document', ['key' => $made->status_key, 'document' => $document->id]) }}" class="btn-primary">{{ __('messages.Download') }}</a>
                    </li>
                @endforeach
            </ul>
        </section>
    @endif

    <section class="card" aria-labelledby="trip-steps-heading">
        <h2 id="trip-steps-heading" dir="auto" class="mb-3 text-lg font-bold text-ink">{{ __('messages.Where it is') }}</h2>
        <ol dir="auto" class="space-y-2 text-sm text-ink">
            @foreach($made->events as $event)
                <li class="flex justify-between gap-4">
                    <span class="font-medium">{{ __('messages.'.($T::STATUSES[$event->status] ?? $event->status)) }}</span>
                    <span class="text-ink-muted">{{ $event->created_at?->isoFormat('D MMM, HH:mm') }}</span>
                </li>
            @endforeach
        </ol>
    </section>
</div>
@endsection
