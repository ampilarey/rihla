@props(['label', 'active' => false])

{{--
    A grouped item in the public header — §15.1/§15.2 (Phase 8.2). Umrah and
    Stays each collapse several pages behind one trigger rather than adding
    them to the flat list `NavigationFitTest` already guards.

    `aria-haspopup`/`aria-expanded` are bound to the same `open` flag the
    panel's visibility uses, so the announced state and the visible one
    cannot drift apart — the same discipline `site-nav-link` already applies
    to `aria-current`. Escape and a click outside both close it; the trigger
    is a `<button>`, never an `<a>`, so it adds nothing to the `<a>` count
    `NavigationFitTest` measures.
--}}
<div class="relative" x-data="{ open: false }" @click.outside="open = false" @keydown.escape.window="open = false">
    <button type="button"
            @click="open = ! open"
            :aria-expanded="open.toString()"
            aria-haspopup="true"
            {{ $attributes->class([
                'flex items-center gap-1 rounded border-b-2 px-2 py-1 font-medium transition-colors focus:outline-none focus:ring-2 focus:ring-wine-500 focus:ring-offset-2',
                'border-gold-500 text-wine-500' => $active,
                'border-transparent text-ink hover:text-wine-500' => ! $active,
            ]) }}>
        {{ $label }}
        <svg aria-hidden="true" focusable="false" class="h-4 w-4 transition-transform" :class="{ 'rotate-180': open }"
             fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path>
        </svg>
    </button>

    <div x-show="open"
         style="display: none;"
         role="menu"
         aria-label="{{ $label }}"
         class="absolute z-50 ltr:start-0 rtl:end-0 mt-2 w-56 rounded-md bg-white py-1 shadow-lg ring-1 ring-black ring-opacity-5">
        {{ $slot }}
    </div>
</div>
