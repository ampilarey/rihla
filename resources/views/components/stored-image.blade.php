@props(['path', 'alt' => '', 'class' => ''])

{{--
    An image held on the public disk, with a branded stand-in when the file is
    not there.

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
--}}
@php
    $exists = $path && \Illuminate\Support\Facades\Storage::disk('public')->exists($path);
@endphp

@if ($exists)
    <img src="{{ \Illuminate\Support\Facades\Storage::url($path) }}"
         alt="{{ $alt }}"
         loading="lazy"
         decoding="async"
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
