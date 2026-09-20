@extends('layouts.app')

@section('title', __('messages.Your booking'))

@section('content')
    {{--
        The page a link that will not work lands on.

        It says *why* — expired, cancelled, or not one of ours — because
        "not found" for all three tells somebody nothing about what to do
        next, and what to do next is always the same: message us.
    --}}
    <div class="container mx-auto max-w-lg px-4 section-y-tight">
        <div class="card text-center">
            <h1 dir="auto" class="mb-3 text-2xl font-bold text-ink">{{ __('messages.We cannot open that') }}</h1>

            <p dir="auto" class="mb-6 text-brand-body">
                {{ session('portal_problem') ?? __('messages.That link has expired. Message us and we will send a new one.') }}
            </p>

            <a href="{{ \App\Support\Contact::whatsappUrl(__('messages.Hello Rihla, my portal link is not working.')) }}"
               class="btn-primary w-full"
               target="_blank" rel="noopener noreferrer">
                {{ __('messages.Message us on WhatsApp') }}
            </a>

            <p dir="auto" class="mt-4 text-sm text-ink-muted">
                {{ __('messages.Or call us on :number.', ['number' => \App\Support\Contact::displayNumber()]) }}
            </p>
        </div>
    </div>
@endsection
