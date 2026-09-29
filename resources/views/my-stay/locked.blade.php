@extends('layouts.app')

@section('title', __('messages.Your stay'))

{{-- Where a stay link that will not work lands — §16.7. Says why, as the
     portal's locked page does, because the next step is always a person. --}}
@section('content')
    <div class="container mx-auto max-w-lg px-4 section-y-tight">
        <div class="card text-center">
            <h1 dir="auto" class="mb-3 text-2xl font-bold text-ink">{{ __('messages.We cannot open that') }}</h1>

            <p dir="auto" class="mb-6 text-brand-body">
                {{ session('stay_problem') ?? __('messages.Open the link we sent you to see your stay. It works for 30 days.') }}
            </p>

            <a href="{{ \App\Support\Contact::whatsappUrl(__('messages.Hello Rihla, my stay link is not working.')) }}"
               class="btn-primary w-full" target="_blank" rel="noopener noreferrer">
                {{ __('messages.Message us on WhatsApp') }}
            </a>

            <p dir="auto" class="mt-4 text-sm text-ink-muted">
                {{ __('messages.Or call us on :number.', ['number' => \App\Support\Contact::displayNumber()]) }}
            </p>
        </div>
    </div>
@endsection
