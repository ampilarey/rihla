@extends('layouts.app')

@section('title', __('messages.Your bookings'))

@section('content')
    {{--
        Every booking of a customer signed in with a password, or the one a
        link opened (companies plan, Phase 3h). A link session is also where
        a password is chosen, once, by somebody who knows the number on file.
    --}}
    <div class="container mx-auto max-w-3xl px-4 section-y-tight">
        <x-portal-nav current="bookings" />

        @if(session('portal_notice'))
            <p dir="auto" class="card mb-6 font-medium text-ink">{{ session('portal_notice') }}</p>
        @endif

        <section class="card mb-6">
            <h1 dir="auto" class="mb-4 text-2xl font-bold text-ink">{{ __('messages.Your bookings') }}</h1>

            <ul class="space-y-3">
                @foreach($bookings as $booking)
                    <li class="flex flex-wrap items-center justify-between gap-3 border-b border-cream-deep pb-3 last:border-0">
                        <div>
                            <p dir="auto" class="font-medium text-ink">{{ $booking->departure->package->title ?? __('messages.Your booking') }}</p>
                            <p class="text-sm text-ink-muted" dir="ltr">
                                {{ $booking->reference }} · <x-local-date :date="$booking->departure->date_start" />
                            </p>
                        </div>

                        @if($booking->is($current))
                            <span dir="auto" class="rounded-xl bg-cream px-4 py-2 text-sm font-medium text-ink">{{ __('messages.Open now') }}</span>
                        @else
                            <form method="POST" action="{{ route('portal.bookings.choose') }}">
                                @csrf
                                <input type="hidden" name="booking" value="{{ $booking->getKey() }}">
                                <button type="submit" class="btn-primary" dir="auto">{{ __('messages.Open') }}</button>
                            </form>
                        @endif
                    </li>
                @endforeach
            </ul>
        </section>

        @if(! $signedIn && ! $customer->hasPortalPassword())
            <section class="card">
                <h2 dir="auto" class="mb-2 text-lg font-bold text-ink">{{ __('messages.Choose a password') }}</h2>
                <p dir="auto" class="mb-4 text-ink-muted">{{ __('messages.Then you can sign in with your mobile number and see every booking, without waiting for a link.') }}</p>

                <form method="POST" action="{{ route('portal.password') }}" class="space-y-4">
                    @csrf
                    <div>
                        <label dir="auto" for="choose-phone" class="mb-1 block text-sm text-ink-muted">{{ __('messages.The mobile number you booked with') }}</label>
                        <input id="choose-phone" name="phone" type="tel" autocomplete="tel" required
                               class="w-full rounded-xl border border-gray-500 px-3 py-2" dir="ltr">
                        <x-input-error :messages="$errors->get('phone')" class="mt-1" />
                    </div>
                    <div>
                        <label dir="auto" for="choose-password" class="mb-1 block text-sm text-ink-muted">{{ __('messages.Password, at least 8 characters') }}</label>
                        <input id="choose-password" name="password" type="password" autocomplete="new-password" required minlength="8"
                               class="w-full rounded-xl border border-gray-500 px-3 py-2" dir="ltr">
                        <x-input-error :messages="$errors->get('password')" class="mt-1" />
                    </div>
                    <div>
                        <label dir="auto" for="choose-password-again" class="mb-1 block text-sm text-ink-muted">{{ __('messages.The same password again') }}</label>
                        <input id="choose-password-again" name="password_confirmation" type="password" autocomplete="new-password" required
                               class="w-full rounded-xl border border-gray-500 px-3 py-2" dir="ltr">
                    </div>
                    <button type="submit" class="btn-primary w-full" dir="auto">{{ __('messages.Set my password') }}</button>
                </form>
            </section>
        @endif
    </div>
@endsection
