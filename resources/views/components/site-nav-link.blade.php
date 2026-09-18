@props(['active' => false])

{{--
    A link in the public header.

    The header previously gave no sign of which page you were on — every item
    looked identical on every page, and nothing told a screen reader either.
    `aria-current="page"` is the half that assistive tech reads; the wine text
    and gold underline are the half everyone else reads. Both come from the
    same flag, so they cannot drift apart.
--}}
<a {{ $attributes->merge(['class' => 'transition-colors font-medium rounded px-2 py-1 border-b-2 focus:outline-none focus:ring-2 focus:ring-wine-500 focus:ring-offset-2 '.($active
        ? 'text-wine-500 border-gold-500'
        : 'text-ink hover:text-wine-500 border-transparent')]) }}
    @if ($active) aria-current="page" @endif>
    {{ $slot }}
</a>
