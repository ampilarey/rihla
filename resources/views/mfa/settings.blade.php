@extends('layouts.app')

@section('title', __('messages.The second step at sign-in'))

@section('content')
    <div class="container mx-auto max-w-2xl px-4 section-y-tight">
        <h1 class="mb-2 text-2xl font-bold text-ink">{{ __('messages.The second step at sign-in') }}</h1>

        @if (session('status'))
            <div class="card mb-6 border-s-4 border-s-wine">
                <p class="text-ink">{{ session('status') }}</p>
            </div>
        @endif

        @if ($enabled)
            <div class="card mb-6">
                <p class="font-semibold text-ink">{{ __('messages.It is on for your account.') }}</p>
                <p class="mt-1 text-brand-body">
                    {{ trans_choice('messages.:count recovery code left|:count recovery codes left', $remaining, ['count' => $remaining]) }}
                </p>
            </div>

            @if ($required)
                <div class="card">
                    <p class="text-ink">
                        {{ __('messages.Your role needs a second step at sign-in, so this cannot be turned off.') }}
                    </p>
                </div>
            @else
                <form method="POST" action="{{ route('mfa.disable') }}" class="card">
                    @csrf

                    <p class="mb-3 font-semibold text-ink">{{ __('messages.Turn it off') }}</p>

                    <label for="password" class="mb-1 block text-sm text-ink">{{ __('messages.Your password') }}</label>
                    <input id="password" name="password" type="password" required autocomplete="current-password"
                           class="w-full rounded-lg border-cream-deep text-ink focus:border-wine-500 focus:ring-wine-500">

                    @error('password')
                        <p class="mt-1 text-sm text-error-dark">{{ $message }}</p>
                    @enderror
                    @error('code')
                        <p class="mt-1 text-sm text-error-dark">{{ $message }}</p>
                    @enderror

                    <button type="submit" class="btn-secondary mt-4">{{ __('messages.Turn it off') }}</button>
                </form>
            @endif
        @else
            <div class="card">
                <p class="mb-3 text-brand-body">
                    {{ __('messages.A second step means a password on its own is not enough to reach staff screens.') }}
                </p>
                <a href="{{ route('mfa.enrol') }}" class="btn-primary">{{ __('messages.Set up a second step') }}</a>
            </div>
        @endif
    </div>
@endsection
