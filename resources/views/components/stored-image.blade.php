@props([
    'path',
    'alt' => '',
    'class' => '',
    'sizes' => null,
    'loading' => 'lazy',
    'decoding' => 'async',
])

{{--
    An image held on the public disk, served at the size the device asked
    for, with a branded stand-in when the file is not there.

    ## The stand-in

    Every gallery image on the live test site was 404ing and rendering as the
    browser's broken-image icon — a torn page and the alt text, on a page whose
    whole job is to show photographs. The cause there was demo rows pointing at
    files nobody uploaded, but the same hole is open in production: a failed
    upload, a file cleared from storage, a row restored from an old backup.

    A customer should never see a broken-image icon on this site. They see the
    mark on cream instead, which reads as "no picture yet" rather than "this
    site is broken".

    The existence check is a stat on local disk, which is cheap, and it only
    runs for rows that actually carry a path.

    ## The srcset — §10.1

    `_768w` / `_1280w` / `_1920w` WebP variants have been generated on hero
    uploads since Phase 2 and **no view ever used them**, so every visitor on
    every device was served the full-size file. On the homepage that file is
    the Largest Contentful Paint element, so a telephone downloaded a
    1920-pixel photograph to show it 390 pixels wide.

    `srcset` appears only when the variants are actually on disk
    (`App\Support\ResponsiveImage` asks, rather than assuming). An image
    uploaded before generation existed would otherwise get a srcset of three
    404s, and a browser handed that picks one and shows nothing — strictly
    worse than the plain <img> it would have had. `php artisan images:responsive`
    fills in what is missing.

    **Pass `sizes`.** Without it the browser assumes the image is the full
    viewport width and fetches the largest file, which is the whole problem
    this exists to solve. A card in a two-column grid wants
    `(min-width: 768px) 50vw, 100vw`.
--}}
@php
    $exists = $path && \Illuminate\Support\Facades\Storage::disk('public')->exists($path);
    $srcset = $exists ? \App\Support\ResponsiveImage::srcset($path) : null;
@endphp

@if ($exists)
    <img src="{{ \Illuminate\Support\Facades\Storage::url($path) }}"
         @if ($srcset) srcset="{{ $srcset }}" sizes="{{ $sizes ?? '100vw' }}" @endif
         alt="{{ $alt }}"
         loading="{{ $loading }}"
         decoding="{{ $decoding }}"
         {{ $attributes->merge(['class' => $class]) }}>
@else
    <div {{ $attributes->merge(['class' => $class.' flex items-center justify-center bg-cream-deep']) }}
         role="img"
         aria-label="{{ $alt !== '' ? $alt : __('messages.image_unavailable') }}">
        <img src="{{ asset('images/rihla-mark.svg') }}"
             alt=""
             width="6247"
             height="6468"
             loading="lazy"
             decoding="async"
             class="w-10 h-auto opacity-40">
    </div>
@endif
