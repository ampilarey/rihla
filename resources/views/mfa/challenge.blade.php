@extends('layouts.app')

@section('title', __('messages.The six digits from your app'))

@section('content')
    <div class="container mx-auto max-w-md px-4 section-y-tight">
        <h1 class="mb-2 text-2xl font-bold text-ink">{{ __('messages.One more step') }}</h1>

        <p class="mb-6 text-brand-body">
            {{ __('messages.Open your authenticator app and type the six digits it shows for Rihla.') }}
        </p>

        <form method="POST" action="{{ route('mfa.verify') }}" class="card">
            @csrf

            <label for="code" class="mb-1 block font-medium text-ink">
                {{ __('messages.The six digits from your app') }}
            </label>

            <input id="code" name="code" type="text" inputmode="numeric" autocomplete="one-time-code"
                   required autofocus
                   class="w-full rounded-lg border-cream-deep font-mono text-lg tracking-widest text-ink focus:border-wine-500 focus:ring-wine-500">

            @error('code')
                <p class="mt-1 text-sm text-error-dark">{{ $message }}</p>
            @enderror

            <p class="mt-2 text-sm text-ink-muted">
                {{ __('messages.Lost the telephone? Type one of your recovery codes here instead.') }}
            </p>

            <button type="submit" class="btn-primary mt-4">{{ __('messages.Carry on') }}</button>
        </form>
    </div>
@endsection
