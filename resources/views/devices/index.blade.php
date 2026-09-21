@extends('layouts.app')

@section('title', __('messages.Where you are signed in'))

@section('content')
    <div class="container mx-auto max-w-2xl px-4 section-y-tight">
        <h1 class="mb-2 text-2xl font-bold text-ink">{{ __('messages.Where you are signed in') }}</h1>

        @if (session('status'))
            <div class="card mb-6 border-s-4 border-s-wine">
                <p class="text-ink">{{ session('status') }}</p>
            </div>
        @endif

        @error('device')
            <div class="card mb-6 border-s-4 border-s-error-dark">
                <p class="text-ink">{{ $message }}</p>
            </div>
        @enderror

        @unless ($available)
            {{-- An empty list here would read as "you are only signed in
                 here", which is the opposite of the truth and the worst
                 possible answer for somebody checking whether they have been
                 broken into. --}}
            <div class="card">
                <p class="font-semibold text-ink">{{ __('messages.This cannot be shown on this server.') }}</p>
                <p class="mt-1 text-brand-body">
                    {{ __('messages.Sessions are not being stored somewhere this page can read, so it cannot tell you where you are signed in. This is not the same as being signed in nowhere else.') }}
                </p>
            </div>
        @else
            <p class="mb-6 text-brand-body">
                {{ __('messages.One row for every browser your account is currently signed in on. If you do not recognise one, sign it out and change your password.') }}
            </p>

            <div class="space-y-4">
                @foreach ($devices as $device)
                    <div class="card @if ($device->isCurrent) border-s-4 border-s-wine @endif">
                        <div class="flex flex-wrap items-start justify-between gap-4">
                            <div>
                                <p class="font-semibold text-ink">{{ $device->description() }}</p>
                                @if ($device->isCurrent)
                                    {{-- Its own line. Inline after a name as long as
                                         "A device that did not say what it is", this
                                         wrapped onto a second line on a telephone and
                                         read as a stray fragment. --}}
                                    <p class="mt-0.5 text-sm font-medium text-wine-700">{{ __('messages.This is the device you are using now.') }}</p>
                                @endif
                                <p class="mt-1 text-sm text-brand-body">
                                    {{ __('messages.Last used :when', ['when' => $device->lastActive->diffForHumans()]) }}
                                    @if ($device->ipAddress)
                                        · {{ $device->ipAddress }}
                                    @endif
                                </p>
                            </div>

                            @unless ($device->isCurrent)
                                <form method="POST" action="{{ route('devices.destroy', $device->id) }}">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" class="btn-secondary">{{ __('messages.Sign out') }}</button>
                                </form>
                            @endunless
                        </div>
                    </div>
                @endforeach
            </div>

            @if ($devices->count() > 1)
                <form method="POST" action="{{ route('devices.destroyOthers') }}" class="card mt-6">
                    @csrf
                    @method('DELETE')

                    <p class="mb-3 text-brand-body">
                        {{ __('messages.Sign out everywhere except here. You will stay signed in on this device.') }}
                    </p>

                    <button type="submit" class="btn-primary">{{ __('messages.Sign out everywhere else') }}</button>
                </form>
            @endif
        @endunless

        <p class="mt-8 text-sm text-brand-body">
            <a href="{{ route('mfa.settings') }}" class="underline">{{ __('messages.The second step at sign-in') }}</a>
        </p>
    </div>
@endsection
