@props(['loading' => 'eager', 'class' => 'h-20 w-auto'])

{{--
    The Rihla wordmark.

    public/images/rihla-logo.png is 6250 × 2976 and weighs 1.44 MB. It was
    served, untouched, in the header and again in the footer of every page —
    to be drawn 80 pixels tall. That one file was seventeen times the weight
    of the entire CSS and JavaScript bundle combined, and it was the first
    thing every visitor on a Maldivian mobile connection had to download.

    The artwork here is the same artwork: these files are resampled from that
    exact PNG, and the original is untouched and still shipped. Only the
    resolution served to the browser changes — 5 KB where 1.44 MB was.

    width and height are the intrinsic dimensions, not the drawn ones. The
    browser divides them to reserve the right space before the image arrives;
    the CSS class decides how big it actually appears.
--}}
<picture>
    {{-- WebP first, PNG for anything that cannot read it. The PNG alone is
         already 8 KB rather than 1.44 MB; WebP takes it to 5. --}}
    <source type="image/webp"
            srcset="{{ asset('images/rihla-logo-200.webp') }} 200w,
                    {{ asset('images/rihla-logo-400.webp') }} 400w,
                    {{ asset('images/rihla-logo-600.webp') }} 600w"
            sizes="(max-width: 640px) 140px, 200px">
    <img src="{{ asset('images/rihla-logo-400.png') }}"
     srcset="{{ asset('images/rihla-logo-200.png') }} 200w,
             {{ asset('images/rihla-logo-400.png') }} 400w,
             {{ asset('images/rihla-logo-600.png') }} 600w"
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
