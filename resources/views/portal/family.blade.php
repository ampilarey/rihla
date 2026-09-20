@extends('layouts.app')

@section('title', __('messages.Family links'))

@section('content')
    <div class="container mx-auto max-w-3xl px-4 section-y-tight">

        <x-portal-nav current="family" />

        <h1 class="mb-2 text-2xl font-bold text-ink">Letting your family follow the journey</h1>

        <p class="mb-6 text-ink-muted">
            You can give your family a link that shows where the group is and any news from the
            office. You choose what it shows, and you can turn it off at any time.
        </p>

        @if (session('status'))
            <div class="card mb-6 border-s-4 border-s-wine">
                <p class="text-ink">{{ session('status') }}</p>
            </div>
        @endif

        @if ($freshLink)
            {{-- Shown once. Held in the flash rather than the session proper
                 so a refresh does not put it back on screen, and never
                 stored — only its hash is. --}}
            <div class="card mb-6 border-s-4 border-s-gold">
                <h2 class="mb-2 text-lg font-bold text-ink">Here is the link — copy it now</h2>
                <p class="mb-3 text-ink-muted">
                    This is the only time it is shown. If you lose it, make another one and turn
                    this one off.
                </p>
                <p class="break-all rounded-xl bg-cream px-4 py-3 font-mono text-sm text-ink" dir="ltr">
                    {{ $freshLink }}
                </p>
            </div>
        @endif

        <section class="card mb-6">
            <h2 class="mb-3 text-lg font-bold text-ink">Make a link</h2>

            <form method="POST" action="{{ route('portal.family.store') }}" class="space-y-4">
                @csrf

                <div>
                    <label for="label" class="mb-1 block font-medium text-ink">Who is it for?</label>
                    <input
                        type="text" id="label" name="label" maxlength="60"
                        placeholder="Mum, or the family group"
                        class="w-full rounded-xl border border-cream-deep px-4 py-3"
                    >
                    <p class="mt-1 text-sm text-ink-muted">
                        Only so you can tell your links apart. Nobody else sees it.
                    </p>
                </div>

                <div class="flex items-start gap-3">
                    <input
                        type="checkbox" id="shares_attendance" name="shares_attendance" value="1"
                        class="mt-1 h-5 w-5 rounded border-cream-deep"
                    >
                    <label for="shares_attendance" class="text-ink">
                        Also show whether the group leader has counted you at the last head count.
                        <span class="block text-sm text-ink-muted">
                            This never shows where you are. Off unless you tick it.
                        </span>
                    </label>
                </div>

                <button type="submit" class="btn-primary">Make the link</button>
            </form>
        </section>

        <section class="card">
            <h2 class="mb-3 text-lg font-bold text-ink">Links you have made</h2>

            @if ($links->isEmpty())
                <p class="text-ink-muted">None yet.</p>
            @else
                <ul class="space-y-4">
                    @foreach ($links as $link)
                        <li class="border-b border-cream-deep pb-4 last:border-0 last:pb-0">
                            <p class="font-medium text-ink">{{ $link->label ?: 'Unnamed link' }}</p>

                            <p class="text-sm text-ink-muted">
                                @if (! $link->isLive())
                                    Turned off
                                @elseif ($link->shares_attendance)
                                    Shows the journey, the news, and whether you have been counted
                                @else
                                    Shows the journey and the news only
                                @endif
                            </p>

                            @if ($link->isLive())
                                <div class="mt-3 flex flex-wrap gap-2">
                                    <form method="POST" action="{{ route('portal.family.update', $link) }}">
                                        @csrf
                                        @method('PATCH')
                                        <input
                                            type="hidden" name="shares_attendance"
                                            value="{{ $link->shares_attendance ? '0' : '1' }}"
                                        >
                                        <button type="submit" class="rounded-xl border-2 border-cream-deep px-4 py-2 text-sm font-medium text-ink">
                                            {{ $link->shares_attendance
                                                ? 'Stop showing head counts'
                                                : 'Also show head counts' }}
                                        </button>
                                    </form>

                                    <form method="POST" action="{{ route('portal.family.revoke', $link) }}">
                                        @csrf
                                        @method('DELETE')
                                        <button type="submit" class="rounded-xl border-2 border-wine px-4 py-2 text-sm font-medium text-wine">
                                            Turn this link off
                                        </button>
                                    </form>
                                </div>
                            @endif
                        </li>
                    @endforeach
                </ul>
            @endif
        </section>
    </div>
@endsection
