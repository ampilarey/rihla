@props(['current' => 'home'])

{{--
    Four pages and a way out. Deliberately small: the portal's value is
    that a pilgrim can find one thing quickly on a phone, and a navigation
    bar with twelve entries — most of them empty because nothing feeds them
    yet — is worse than four that always have something in them.

    "Family links" earns its place because §6.2 puts the privacy controls in
    the pilgrim's hands, and a control nobody can find is not one they own.
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

    <a href="{{ route('portal.family', ['locale' => app()->getLocale()]) }}"
       @class([
           'rounded-xl px-4 py-2 text-sm font-medium',
           'bg-wine-600 text-cream' => $current === 'family',
           'bg-cream text-ink hover:bg-cream-deep' => $current !== 'family',
       ])
       @if($current === 'family') aria-current="page" @endif
       dir="auto">
        {{ __('messages.Family links') }}
    </a>

    {{-- "Learning" earns its place for the same reason: §7.3's plan is
         keyed to the departure date, so it changes under the pilgrim
         without being opened, and a deadline nobody can find is not one
         anybody meets. --}}
    <a href="{{ route('learning.index', ['locale' => app()->getLocale()]) }}"
       @class([
           'rounded-xl px-4 py-2 text-sm font-medium',
           'bg-wine-600 text-cream' => $current === 'learning',
           'bg-cream text-ink hover:bg-cream-deep' => $current !== 'learning',
       ])
       @if($current === 'learning') aria-current="page" @endif
       dir="auto">
        {{ __('messages.Learning') }}
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
