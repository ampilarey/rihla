@extends('layouts.app')

@section('title', __('messages.Set up a second step'))

@section('content')
    <div class="container mx-auto max-w-2xl px-4 section-y-tight">
        <h1 class="mb-2 text-2xl font-bold text-ink">{{ __('messages.Set up a second step') }}</h1>

        @if ($required)
            <div class="card mb-6 border-s-4 border-s-wine">
                <p class="text-ink">
                    {{ __('messages.Your role can read personal details and move money, so signing in needs a second step. Set it up here — it takes a minute, and nothing is locked in the meantime.') }}
                </p>
            </div>
        @endif

        <ol class="mb-6 space-y-4">
            <li class="card">
                <p class="font-semibold text-ink">{{ __('messages.1. Open an authenticator app on your telephone.') }}</p>
                <p class="mt-1 text-sm text-brand-body">
                    {{ __('messages.Google Authenticator, Authy, 1Password and the rest all work. Any of them will do.') }}
                </p>
            </li>

            <li class="card">
                <p class="font-semibold text-ink">{{ __('messages.2. Add an account and type this key in.') }}</p>

                {{--
                    Typed in rather than scanned. Rendering a QR code needs a
                    package, and every dependency on this host is another
                    thing somebody has to remember to update by hand
                    (ADR 0002) — in the authentication path, a stale one is
                    worse than a minute of typing. Every authenticator
                    accepts a key entered by hand.
                --}}
                <p class="mt-3 select-all break-all rounded-lg border border-cream-deep bg-cream p-3 font-mono text-lg tracking-wider text-ink">
                    {{ $spaced }}
                </p>

                <p class="mt-2 text-sm text-ink-muted">
                    {{ __('messages.Choose "time-based" if it asks. Leave every other setting alone.') }}
                </p>
            </li>

            <li class="card">
                <p class="font-semibold text-ink">{{ __('messages.3. Type the six digits it shows.') }}</p>

                <form method="POST" action="{{ route('mfa.confirm') }}" class="mt-3">
                    @csrf

                    <label for="code" class="sr-only">{{ __('messages.The six digits from your app') }}</label>

                    <input id="code" name="code" type="text" inputmode="numeric" autocomplete="one-time-code"
                           required maxlength="6" pattern="[0-9]{6}" autofocus
                           class="w-full rounded-lg border-cream-deep font-mono text-lg tracking-widest text-ink focus:border-wine-500 focus:ring-wine-500">

                    @error('code')
                        <p class="mt-1 text-sm text-error-dark">{{ $message }}</p>
                    @enderror

                    <button type="submit" class="btn-primary mt-4">{{ __('messages.Turn it on') }}</button>
                </form>
            </li>
        </ol>
    </div>
@endsection
