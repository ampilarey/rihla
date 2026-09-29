@extends('layouts.app')

@section('title', __('messages.How was your stay?'))

{{-- A review invitation link — §16.11. Opens one stay's review form, once. --}}
@section('content')
<div class="container mx-auto max-w-3xl px-4 section-y-tight">
    <header class="mb-6">
        <h1 dir="auto" class="section-title text-start">{{ $stay->property->name }}</h1>
        <p dir="auto" class="text-ink-muted">{{ $stay->check_in->isoFormat('D MMM YYYY') }} – {{ $stay->check_out->isoFormat('D MMM YYYY') }}</p>
    </header>

    @if(session('status'))
        <p role="status" dir="auto" class="mb-6 rounded-xl border-s-4 border-s-success bg-cream-deep px-4 py-3 text-sm text-ink">{{ session('status') }}</p>
    @endif

    @include('stays._review', ['stay' => $stay, 'action' => route('stays.review.store', ['token' => $token])])
</div>
@endsection
