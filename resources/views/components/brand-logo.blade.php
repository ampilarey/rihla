@props(['loading' => 'eager', 'class' => 'h-20 w-auto', 'on' => 'light'])

{{--
    The Rihla logo: the two-sail dhoni.

    This replaced the Kaaba-and-calligraphy wordmark, which is kept in the
    repository as `rihla-logo.png` (the original) and `rihla-logo-brand.png`
    (the same artwork recoloured into the palette). Neither is served any more.

    The mark is vector, so one 461-byte file covers every size from the 16-pixel
    favicon to the header — no srcset, no resampling, no blur on a high-density
    screen. For reference, the wordmark it replaces needed six raster files, and
    before that a single 1.44 MB PNG.

    width and height are the viewBox multiplied by 100, which is exact rather
    than rounded: the browser divides them to reserve the right space before the
    file lands, and the CSS class decides how large it actually appears.

    `on="dark"` swaps the hull to cream. The hull is ink #2E2621 and the footer
    is `bg-ink`, the same #2E2621 — so on the footer the hull disappeared
    entirely and the logo rendered as two sails floating above nothing. The
    sails are unaffected: wine and gold both hold against ink. Only the hull
    needs the swap, because it is the one part painted in the surface's own
    colour.
--}}
@php($mark = $on === 'dark' ? 'images/rihla-mark-inverse.svg' : 'images/rihla-mark.svg')
<img src="{{ asset($mark) }}"
     alt="{{ config('app.name') }}"
     width="6247"
     height="6468"
     loading="{{ $loading }}"
     decoding="async"
     {{-- `class` is a prop, not a merged attribute: $attributes->merge()
          appends to its default rather than replacing it, so the footer's
          `h-16 w-auto` arrived as `h-20 w-auto h-16 w-auto` and which height
          won came down to the order Tailwind happened to emit them in. --}}
     class="{{ $class }}"
     {{ $attributes }}>
