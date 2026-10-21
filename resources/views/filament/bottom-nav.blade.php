{{--
    The bar along the bottom of a phone screen (App\Filament\BottomNav).

    Styled here rather than with utilities, because Tailwind utilities do
    nothing inside a panel (AGENTS.md), and in its own <style> rather than a
    panel theme because the panels have none: the colours are Filament's own
    variables, so the bar follows the panel's palette and its dark mode.
    Phone first: the bar is the default and a min-width query removes it
    once the sidebar is on screen.
--}}
<style>
    .rihla-bottom-nav {
        position: fixed;
        inset-inline: 0;
        bottom: 0;
        z-index: 30;
        display: flex;
        justify-content: space-around;
        align-items: stretch;
        padding-bottom: env(safe-area-inset-bottom);
        background: #fff;
        border-top: 1px solid var(--gray-200, #e7e5e4);
    }

    .dark .rihla-bottom-nav {
        background: var(--gray-900, #1c1917);
        border-top-color: var(--gray-800, #292524);
    }

    .rihla-bottom-nav__item {
        display: flex;
        flex: 1 1 0;
        flex-direction: column;
        align-items: center;
        gap: 2px;
        min-height: 56px;
        padding: 8px 4px;
        font-size: 11px;
        line-height: 1;
        color: var(--gray-500, #78716c);
        text-decoration: none;
        touch-action: manipulation;
    }

    .rihla-bottom-nav__item.is-active {
        color: var(--primary-600, #5f498a);
        font-weight: 600;
    }

    .rihla-bottom-nav__icon svg {
        width: 24px;
        height: 24px;
    }

    /* Keep the page's last row clear of the bar. */
    .fi-main {
        padding-bottom: calc(72px + env(safe-area-inset-bottom));
    }

    @media (min-width: 64rem) {
        .rihla-bottom-nav {
            display: none;
        }

        .fi-main {
            padding-bottom: 0;
        }
    }
</style>
<nav class="rihla-bottom-nav" data-bottom-nav aria-label="Quick navigation">
    @foreach ($items as $item)
        <a href="{{ $item['url'] }}" class="rihla-bottom-nav__item{{ $item['active'] ? ' is-active' : '' }}"@if ($item['active']) aria-current="page"@endif>
            <span class="rihla-bottom-nav__icon" aria-hidden="true">
                @switch($item['icon'])
                    @case('home')
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M3 11.5 12 4l9 7.5"/><path d="M5 10v10h5v-6h4v6h5V10"/></svg>
                        @break
                    @case('trips')
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M3 20h18"/><path d="M4 16 20 9l-2-2-5 2-6-3-2 1 4 3-4 2-2-1-1 1z"/></svg>
                        @break
                    @case('people')
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="8" r="4"/><path d="M4 21a8 8 0 0 1 16 0"/></svg>
                        @break
                    @case('bookings')
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M4 8a2 2 0 0 1 2-2h12a2 2 0 0 1 2 2v2a2 2 0 0 0 0 4v2a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2v-2a2 2 0 0 0 0-4z"/><path d="M10 6v12"/></svg>
                        @break
                    @case('payments')
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="7" width="18" height="11" rx="2"/><circle cx="12" cy="12.5" r="2.5"/><path d="M7 7V5h10v2"/></svg>
                        @break
                    @case('documents')
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M7 3h7l5 5v13H7z"/><path d="M14 3v5h5"/><path d="m9 15 2 2 4-4"/></svg>
                        @break
                    @case('calendar')
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="5" width="18" height="16" rx="2"/><path d="M3 10h18"/><path d="M8 3v4M16 3v4"/></svg>
                        @break
                    @case('rooms')
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M3 18V8a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2v10"/><path d="M3 13h18"/><path d="M7 13V9h4v4"/></svg>
                        @break
                    @case('messages')
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M4 5h16v11H8l-4 4z"/><path d="M8 9h8M8 12h5"/></svg>
                        @break
                    @default
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><circle cx="12" cy="12" r="8"/></svg>
                @endswitch
            </span>
            <span class="rihla-bottom-nav__label">{{ $item['label'] }}</span>
        </a>
    @endforeach
</nav>
