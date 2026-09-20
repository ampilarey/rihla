@extends('layouts.app')

@section('title', 'Following the journey')

@section('content')
    <div class="container mx-auto max-w-2xl px-4 section-y-tight">
        <div class="card">
            <h1 class="mb-3 text-2xl font-bold text-ink">Following the journey</h1>

            <p class="mb-4 text-ink-muted">
                {{ $reason ?? 'This page needs the link the person travelling sent you.' }}
            </p>

            <p class="text-ink-muted">
                Links are given out by the person on the journey, and they can turn one off at
                any time. If yours has stopped working, ask them for a new one.
            </p>
        </div>
    </div>
@endsection
