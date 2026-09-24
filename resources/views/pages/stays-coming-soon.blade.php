@extends('layouts.app')

@section('title', $label)

{{--
    The placeholder every Stays service shows until Phases 9–11 give it
    something real to list — §15.3 (Phase 8.2). Reusing the contact page's
    enquiry form and its `enquiries.store` endpoint rather than a new one:
    a Stays lead is a lead the same way an Umrah one is, and the message
    field carries which service it was sent from, so nothing here needed a
    schema change.
--}}
@section('content')
<div class="container mx-auto px-4 py-16">
    <div class="max-w-2xl mx-auto text-center">
        <h1 dir="auto" class="mb-4 text-4xl font-bold text-ink">{{ $label }}</h1>
        <p dir="auto" class="mb-8 text-lg text-ink-muted">{{ $blurb }}</p>
    </div>

    <div class="mx-auto max-w-xl">
        <div class="card mb-6 text-center">
            <p dir="auto" class="mb-4 text-sm text-ink-muted">
                {{ __('messages.The fastest way to reach us is WhatsApp.') }}
            </p>
            <a href="{{ \App\Support\Contact::whatsappUrl() }}"
               target="_blank"
               rel="noopener"
               class="btn-primary inline-flex items-center gap-2">
                <svg aria-hidden="true" focusable="false" class="h-4 w-4" fill="currentColor" viewBox="0 0 24 24">
                    <path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347m-5.421 7.403h-.004a9.87 9.87 0 01-5.031-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86 9.86 0 01-1.51-5.26c.001-5.45 4.436-9.884 9.888-9.884 2.64 0 5.122 1.03 6.988 2.898a9.825 9.825 0 012.893 6.994c-.003 5.45-4.437 9.884-9.885 9.884m8.413-18.297A11.815 11.815 0 0012.05 0C5.495 0 .16 5.335.157 11.892c0 2.096.547 4.142 1.588 5.945L.057 24l6.305-1.654a11.882 11.882 0 005.683 1.448h.005c6.554 0 11.89-5.335 11.893-11.893A11.821 11.821 0 0020.885 3.488"/>
                </svg>
                {{ __('messages.Message us') }}
            </a>
        </div>

        <div class="card text-start">
            <h2 dir="auto" class="mb-2 text-xl font-bold text-ink">{{ __('messages.Or leave your details') }}</h2>
            <p dir="auto" class="mb-4 text-sm text-ink-muted">
                {{ __('messages.We will come back to you. Leave a number or an email, whichever you prefer.') }}
            </p>

            <form method="POST" action="{{ route('enquiries.store') }}" class="space-y-3">
                @csrf

                <div>
                    <label dir="auto" for="stays-enquiry-name" class="mb-1 block text-sm text-ink-muted">{{ __('messages.Your name') }}</label>
                    <input id="stays-enquiry-name" name="name" type="text" required maxlength="255"
                           value="{{ old('name') }}" dir="auto"
                           class="w-full rounded-xl border border-cream-deep px-3 py-2">
                    <x-input-error :messages="$errors->get('name')" class="mt-1" />
                </div>

                <div>
                    <label dir="auto" for="stays-enquiry-phone" class="mb-1 block text-sm text-ink-muted">{{ __('messages.Phone') }}</label>
                    <input id="stays-enquiry-phone" name="phone" type="tel" maxlength="40" inputmode="tel"
                           value="{{ old('phone') }}" dir="ltr"
                           class="w-full rounded-xl border border-cream-deep px-3 py-2">
                    <x-input-error :messages="$errors->get('phone')" class="mt-1" />
                </div>

                <div>
                    <label dir="auto" for="stays-enquiry-email" class="mb-1 block text-sm text-ink-muted">{{ __('messages.Email') }}</label>
                    <input id="stays-enquiry-email" name="email" type="email" maxlength="255"
                           value="{{ old('email') }}" dir="ltr"
                           class="w-full rounded-xl border border-cream-deep px-3 py-2">
                    <x-input-error :messages="$errors->get('email')" class="mt-1" />
                </div>

                <div>
                    <label dir="auto" for="stays-enquiry-party" class="mb-1 block text-sm text-ink-muted">{{ __('messages.How many of you?') }}</label>
                    <input id="stays-enquiry-party" name="party_size" type="number" min="1" max="60" inputmode="numeric"
                           value="{{ old('party_size') }}" dir="ltr"
                           class="w-full rounded-xl border border-cream-deep px-3 py-2">
                    <x-input-error :messages="$errors->get('party_size')" class="mt-1" />
                </div>

                <div>
                    <label dir="auto" for="stays-enquiry-message" class="mb-1 block text-sm text-ink-muted">{{ __('messages.Anything else?') }}</label>
                    <textarea id="stays-enquiry-message" name="message" rows="3" maxlength="2000" dir="auto"
                              class="w-full rounded-xl border border-cream-deep px-3 py-2">{{ old('message', __('messages.Interested in: :service', ['service' => $label])) }}</textarea>
                    <x-input-error :messages="$errors->get('message')" class="mt-1" />
                </div>

                {{-- Not shown to anybody, and not announced to a screen
                     reader either — the same honeypot the contact page uses. --}}
                <div aria-hidden="true" style="position:absolute;left:-9999px;">
                    <label for="stays-enquiry-website">{{ __('messages.Leave this empty') }}</label>
                    <input id="stays-enquiry-website" name="website" type="text" tabindex="-1" autocomplete="off">
                </div>

                <button type="submit" dir="auto" class="btn-primary w-full">{{ __('messages.Send') }}</button>
            </form>
        </div>
    </div>
</div>
@endsection
