@props(['current' => 'home'])

{{--
    Two pages and a way out. Deliberately small: the portal's value is that a
    pilgrim can find one thing quickly on a phone, and a navigation bar with
    twelve entries — most of them empty because nothing feeds them yet — is
    worse than two that always have something in them.
--}}
<nav class="mb-6 flex flex-wrap items-center gap-2" aria-label="{{ __('messages.Your booking') }}">
    <a href="{{ route('portal.home', ['locale' => app()->getLocale()]) }}"
       @class([
           'rounded-xl px-4 py-2 text-sm font-medium',
           'bg-wine-600 text-cream' => $current === 'home',
           'bg-cream text-ink hover:bg-cream-deep' => $current !== 'home',
       ])
       @if($current === 'home') aria-current="page" @endif
       dir="auto">
        {{ __('messages.Your booking') }}
    </a>

    <a href="{{ route('portal.documents', ['locale' => app()->getLocale()]) }}"
       @class([
           'rounded-xl px-4 py-2 text-sm font-medium',
           'bg-wine-600 text-cream' => $current === 'documents',
           'bg-cream text-ink hover:bg-cream-deep' => $current !== 'documents',
       ])
       @if($current === 'documents') aria-current="page" @endif
       dir="auto">
        {{ __('messages.Documents') }}
    </a>

    {{-- A POST, because signing out with a GET is something a link
         prefetcher can do to you. --}}
    <form method="POST" action="{{ route('portal.leave', ['locale' => app()->getLocale()]) }}" class="ms-auto">
        @csrf
        <button type="submit" dir="auto" class="rounded-xl px-4 py-2 text-sm font-medium text-ink-muted hover:text-ink">
            {{ __('messages.Sign out') }}
        </button>
    </form>
</nav>
