@props([
    'loading' => 'eager',
    'class' => 'w-20',
    'on' => 'light',
])

{{--
    The Rihla lockup: the dhoni, with the company name justified beneath it to
    the mark's own width.

    The name is real text, not part of the artwork. That is deliberate — it is
    indexed by search engines, read aloud by screen readers, selectable, and
    it stays sharp at any pixel density without a second file.

    The width is set on the wrapper and the mark fills it, so the mark and the
    name are always the same width by construction rather than by a number
    someone has to keep in step. `class` sizes the whole lockup: w-20 in the
    header, w-16 in the footer.

    `on="dark"` swaps the hull to cream and the name to cream. The hull is ink
    #2E2245 and the footer is `bg-ink`, the same #2E2245 — so on the footer the
    hull disappeared entirely and the logo rendered as two sails floating above
    nothing.

    The sails are NOT unaffected, which this comment used to claim. Measured,
    the old wine sail was 1.8:1 against ink — all but invisible on the footer
    for as long as the inverse mark has existed — and the old gold sail was
    2.38:1 on white. Both are below the 3:1 a graphic element needs, and no
    test could see it because the SVG was present and only the colour was
    wrong. What carries the silhouette — the hull and the main sail — is still
    chosen per ground and still measured: the light mark's hull is ink at
    14.68:1 on white with the guide's violet #5F498A at 7.48:1 beside it; the
    inverse carries a cream hull at 14.37:1 on ink and a lighter violet
    #9481BA at 4.27:1. Recolouring one of those and copying it to the other
    reintroduces exactly this bug.

    The fore sail is the exception, deliberately. Both variants paint it
    #EFD34D — the same gold as the primary call to action, which is what the
    owner asked for and what makes the mark and the page agree. On ink that is
    9.85:1. On white it is 1.49:1, and no vivid yellow can do better: 3:1
    against white needs a relative luminance at or below 0.30 and this sits at
    0.655, so every gold that passes is a drab one. Rendered at 80px and at
    34px it reads clearly regardless, because a fully saturated hue separates
    from white in a way a luminance ratio does not describe — and a logo is
    outside WCAG 1.4.11 anyway. BrandColourTest measures the other two and
    pins this one by value, so it can be changed but not drifted.

    The two lines carry different tracking because they are different lengths:
    five letters and seven letters, each spread to the same span. The numbers
    are solved, not chosen: at 16.5cqw, Montserrat SemiBold sets RIHLA at
    0.5305 of the lockup width and TRAVELS at 0.7676, so the tracking that
    makes each span exactly 1.0 is 0.7114em and 0.2347em. They were 0.58em
    and 0.21em for Inter. Change the face and both must be re-solved, or the
    lockup stops being justified to the mark and the whole construction —
    mark and both lines sharing one width — quietly stops holding.

    Sizes are in cqw — a percentage of the lockup's own width — so the name
    scales with the mark instead of being pinned to one pixel size. A px value
    is declared first as the fallback for anything that does not understand
    container units. `em` would not work here: it resolves against the parent's
    font size, not its width, which put the name at 2.6px on the first try.
--}}
@php
    $dark = $on === 'dark';
    $mark = $dark ? 'images/rihla-mark-inverse.svg' : 'images/rihla-mark.svg';
@endphp

<span {{ $attributes->merge(['class' => 'inline-flex flex-col items-center leading-none '.$class]) }}
      style="container-type: inline-size;">
    <img src="{{ asset($mark) }}"
         alt=""
         width="6247"
         height="6468"
         loading="{{ $loading }}"
         decoding="async"
         class="w-full h-auto">

    {{-- One accessible name for the pair, so a reader says "Rihla Travels"
         rather than spelling out two letter-spaced fragments. --}}
    <span class="sr-only">{{ config('app.name') }}</span>

    <span aria-hidden="true"
          class="mt-1.5 block w-full text-center font-wordmark font-semibold {{ $dark ? 'text-cream' : 'text-ink' }}"
          style="font-size: 13px; font-size: 16.5cqw; letter-spacing: 0.7114em; text-indent: 0.7114em;">RIHLA</span>

    <span aria-hidden="true"
          class="mt-0.5 block w-full text-center font-wordmark font-semibold {{ $dark ? 'text-cream' : 'text-ink' }}"
          style="font-size: 13px; font-size: 16.5cqw; letter-spacing: 0.2347em; text-indent: 0.2347em;">TRAVELS</span>
</span>
