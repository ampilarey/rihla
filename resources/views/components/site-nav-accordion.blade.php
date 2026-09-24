@props(['label', 'active' => false])

{{--
    The mobile counterpart to `site-nav-dropdown` — §15.3 (Phase 8.2). The
    mobile menu is a stacked list, not a floating panel, so this expands
    in place rather than overlaying anything. Pre-opened when the visitor is
    already on one of its pages, so refreshing a Stays page on a phone does
    not hide the very link that got them there.
--}}
<div x-data="{ open: {{ $active ? 'true' : 'false' }} }">
    <button type="button"
            @click="open = ! open"
            :aria-expanded="open.toString()"
            aria-haspopup="true"
            class="flex w-full items-center justify-between rounded px-2 py-2 text-left font-medium transition-colors focus:outline-none focus:ring-2 focus:ring-wine-500 focus:ring-offset-2 {{ $active ? 'text-wine-500' : 'text-ink hover:text-wine-500' }}">
        <span>{{ $label }}</span>
        <svg aria-hidden="true" focusable="false" class="h-4 w-4 transition-transform" :class="{ 'rotate-180': open }"
             fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path>
        </svg>
    </button>

    <div x-show="open"
         role="menu"
         aria-label="{{ $label }}"
         style="display: {{ $active ? 'block' : 'none' }};"
         class="ms-2 mt-1 flex flex-col space-y-1 border-s-2 border-gray-200 ps-4">
        {{ $slot }}
    </div>
</div>
