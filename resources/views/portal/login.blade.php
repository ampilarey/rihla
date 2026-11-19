@extends('layouts.app')

@section('title', __('messages.Sign in to your booking'))

@section('content')
    {{--
        The portal's second door (companies plan, Phase 3h): a mobile number
        and the password the customer chose. The first door, the link the
        office sends, still works and needs neither.
    --}}
    <div class="container mx-auto max-w-lg px-4 section-y-tight">
        <div class="card">
            <h1 dir="auto" class="mb-2 text-2xl font-bold text-ink">{{ __('messages.Sign in to your booking') }}</h1>
            <p dir="auto" class="mb-6 text-ink-muted">{{ __('messages.Use the mobile number you booked with and the password you chose.') }}</p>

            <form method="POST" action="{{ route('portal.login.attempt') }}" class="space-y-4">
                @csrf
                <div>
                    <label dir="auto" for="portal-phone" class="mb-1 block text-sm text-ink-muted">{{ __('messages.Mobile number') }}</label>
                    <input id="portal-phone" name="phone" type="tel" autocomplete="tel" required
                           value="{{ old('phone') }}"
                           class="w-full rounded-xl border border-gray-500 px-3 py-2" dir="ltr">
                    <x-input-error :messages="$errors->get('phone')" class="mt-1" />
                </div>

                <div>
                    <label dir="auto" for="portal-password" class="mb-1 block text-sm text-ink-muted">{{ __('messages.Password') }}</label>
                    <input id="portal-password" name="password" type="password" autocomplete="current-password" required
                           class="w-full rounded-xl border border-gray-500 px-3 py-2" dir="ltr">
                </div>

                <button type="submit" class="btn-primary w-full" dir="auto">{{ __('messages.Sign in') }}</button>
            </form>

            <p dir="auto" class="mt-6 text-sm text-ink-muted">
                {{ __('messages.No password yet? Open the link we sent you, and choose one there.') }}
            </p>
        </div>
    </div>
@endsection
