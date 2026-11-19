@extends('layouts.app')

@section('title', __('Contact'))

@section('content')
    <div class="container mx-auto max-w-3xl px-4 section-y-tight">
        <h1 class="mb-6 text-3xl font-bold text-[color:var(--site-primary)]" dir="auto">{{ __('Contact') }} — {{ $company->name }}</h1>

        <ul class="mb-8 space-y-2 text-lg">
            @if ($company->phone)
                <li><a class="hover:underline" href="{{ \App\Support\Contact::telUrlFor($company->phone) }}">{{ $company->phone }}</a></li>
            @endif
            @if ($company->email)
                <li><a class="hover:underline" href="mailto:{{ $company->email }}">{{ $company->email }}</a></li>
            @endif
            @if ($whatsapp = \App\Support\Contact::whatsappUrlFor(\App\Support\Contact::companyWhatsapp()))
                <li><a class="hover:underline" href="{{ $whatsapp }}" rel="noopener" target="_blank">WhatsApp</a></li>
            @endif
            @if ($company->address)
                <li dir="auto">{{ $company->address }}</li>
            @endif
        </ul>

        <div class="card text-start">
            <h2 dir="auto" class="mb-2 text-xl font-bold text-ink">{{ __('messages.Or send us a message') }}</h2>
            <p dir="auto" class="mb-4 text-sm text-ink-muted">
                {{ __('messages.We will come back to you. Leave a number or an email, whichever you prefer.') }}
            </p>
            @include('pages.partials.enquiry-form')
        </div>
    </div>
@endsection
