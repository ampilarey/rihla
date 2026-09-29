@extends('layouts.app')

@section('title', 'Join '.$invitation->partner->name)

{{-- An invitation to work for a host — §16.10 Team. One address, one use. --}}
@section('content')
<div class="container mx-auto max-w-xl px-4 section-y-tight">
    <div class="card">
        <h1 dir="auto" class="mb-2 text-2xl font-bold text-ink">Join {{ $invitation->partner->name }}</h1>
        <p dir="auto" class="mb-4 text-ink-muted">
            You have been invited as <strong class="text-ink">{{ \App\Support\HostRole::label($invitation->role) }}</strong>,
            for <span dir="ltr">{{ $invitation->email }}</span>.
        </p>

        @if($errors->any())
            <div role="alert" class="mb-4 rounded-xl border-s-4 border-s-error bg-cream-deep px-4 py-3 text-sm text-ink">
                @foreach($errors->all() as $error)
                    <p dir="auto">{{ $error }}</p>
                @endforeach
            </div>
        @endif

        @if($user)
            @if(strtolower($user->email) === strtolower($invitation->email))
                <form method="POST" action="{{ route('host.join.accept', ['token' => $token]) }}">
                    @csrf
                    <button type="submit" class="btn-primary">Join the team</button>
                </form>
            @else
                <p dir="auto" class="text-sm text-ink">
                    You are signed in as <span dir="ltr">{{ $user->email }}</span>. This invitation is for
                    <span dir="ltr">{{ $invitation->email }}</span> — sign out and sign in with that address.
                </p>
            @endif
        @elseif($hasAccount)
            <p dir="auto" class="mb-4 text-sm text-ink">There is already an account for this address. Sign in, and you will come back here to accept.</p>
            <a href="{{ $loginUrl }}" class="btn-primary">Sign in</a>
        @else
            <form method="POST" action="{{ route('host.join.register', ['token' => $token]) }}" class="space-y-4">
                @csrf
                <div>
                    <label for="join-name" class="mb-1 block text-sm font-medium text-ink">Your name</label>
                    <input id="join-name" name="name" type="text" required maxlength="255" value="{{ old('name') }}" autocomplete="name"
                           class="w-full rounded-lg border border-gray-500 px-3 py-2 text-ink focus:border-wine-500 focus:ring-wine-500">
                </div>
                <div>
                    <label for="join-password" class="mb-1 block text-sm font-medium text-ink">Choose a password (at least 12 characters)</label>
                    <input id="join-password" name="password" type="password" required minlength="12" autocomplete="new-password"
                           class="w-full rounded-lg border border-gray-500 px-3 py-2 text-ink focus:border-wine-500 focus:ring-wine-500">
                </div>
                <div>
                    <label for="join-password-confirmation" class="mb-1 block text-sm font-medium text-ink">The same password again</label>
                    <input id="join-password-confirmation" name="password_confirmation" type="password" required minlength="12" autocomplete="new-password"
                           class="w-full rounded-lg border border-gray-500 px-3 py-2 text-ink focus:border-wine-500 focus:ring-wine-500">
                </div>
                <button type="submit" class="btn-primary">Create my account and join</button>
            </form>
        @endif
    </div>
</div>
@endsection
