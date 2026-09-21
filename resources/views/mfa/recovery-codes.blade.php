@extends('layouts.app')

@section('title', __('messages.Your recovery codes'))

@section('content')
    <div class="container mx-auto max-w-2xl px-4 section-y-tight">
        <h1 class="mb-2 text-2xl font-bold text-ink">{{ __('messages.Your recovery codes') }}</h1>

        {{--
            Shown once, here. They are stored hashed, so nothing — including
            this application — can show them again, and saying that plainly
            is the difference between somebody writing them down now and
            somebody locked out of their own account in March.
        --}}
        <div class="card mb-6 border-s-4 border-s-wine">
            <p class="text-ink">
                {{ __('messages.This is the only time these are shown. Write them down or print them and keep them somewhere other than your telephone.') }}
            </p>
            <p class="mt-2 text-sm text-brand-body">
                {{ __('messages.Each one works once, and they are how you get in if you lose the telephone. Nobody can show them to you again — not the office, not us.') }}
            </p>
        </div>

        <ul class="mb-6 grid grid-cols-2 gap-2">
            @foreach ($codes as $code)
                <li class="select-all rounded-lg border border-cream-deep bg-cream p-3 text-center font-mono text-lg tracking-wider text-ink">
                    {{ $code }}
                </li>
            @endforeach
        </ul>

        <a href="{{ route('filament.staff.pages.dashboard') }}" class="btn-primary">
            {{ __('messages.I have written them down') }}
        </a>
    </div>
@endsection
