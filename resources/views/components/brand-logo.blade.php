@props(['loading' => 'eager', 'class' => 'h-20 w-auto'])

{{--
    The Rihla wordmark.

    public/images/rihla-logo.png is 6250 × 2976 and weighs 1.44 MB. It was
    served, untouched, in the header and again in the footer of every page —
    to be drawn 80 pixels tall. That one file was seventeen times the weight
    of the entire CSS and JavaScript bundle combined, and it was the first
    thing every visitor on a Maldivian mobile connection had to download.

    The artwork here is the same artwork, resampled from that exact PNG. The
    original is untouched and still shipped as rihla-logo.png.

    The colours are not the same. The original is drawn in #097EDD bright blue
    and pure black, neither of which is in the Rihla palette — it was the last
    thing on the site still wearing the pre-rebrand scheme, sitting at the top
    of every page beside everything that had moved to wine, gold and ink. That
    is why the header kept looking unchanged: it was unchanged. Blue is now
    wine and pure black is now ink, and nothing else about the mark moved —
    same letterforms, same Kaaba, same calligraphy, same proportions.

    rihla-logo-brand.png is the recoloured master these are cut from;
    rihla-logo.png is the untouched original, kept for reference.

    width and height are the intrinsic dimensions, not the drawn ones. The
    browser divides them to reserve the right space before the image arrives;
    the CSS class decides how big it actually appears.
--}}
<picture>
    {{-- WebP first, PNG for anything that cannot read it. The PNG alone is
         already 8 KB rather than 1.44 MB; WebP takes it to 5. --}}
    <source type="image/webp"
            srcset="{{ asset('images/rihla-logo-brand-200.webp') }} 200w,
                    {{ asset('images/rihla-logo-brand-400.webp') }} 400w,
                    {{ asset('images/rihla-logo-brand-600.webp') }} 600w"
            sizes="(max-width: 640px) 140px, 200px">
    <img src="{{ asset('images/rihla-logo-brand-400.png') }}"
     srcset="{{ asset('images/rihla-logo-brand-200.png') }} 200w,
             {{ asset('images/rihla-logo-brand-400.png') }} 400w,
             {{ asset('images/rihla-logo-brand-600.png') }} 600w"
     sizes="(max-width: 640px) 140px, 200px"
     alt="{{ config('app.name') }}"
     width="6250"
     height="2976"
     loading="{{ $loading }}"
     decoding="async"
     {{-- `class` is a prop, not a merged attribute: $attributes->merge()
          appends to its default rather than replacing it, so the footer's
          `h-16 w-auto` arrived as `h-20 w-auto h-16 w-auto` and which height
          won came down to the order Tailwind happened to emit them in. --}}
     class="{{ $class }}"
     {{ $attributes }}>
</picture>
