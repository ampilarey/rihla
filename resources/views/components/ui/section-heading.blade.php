@props([
    'title',
    'subtitle' => null,
    'align' => 'center',
    'size' => 'large'
])

@php
    $alignClasses = [
        'left' => 'text-left',
        'center' => 'text-center',
        'right' => 'text-right'
    ];
    
    $sizeClasses = [
        'small' => 'text-2xl md:text-3xl',
        'medium' => 'text-3xl md:text-4xl',
        'large' => 'text-4xl md:text-5xl'
    ];
@endphp

<div class="{{ $alignClasses[$align] }} mb-8">
    <h2 class="{{ $sizeClasses[$size] }} font-extrabold text-brand-heading mb-4">
        {{ $title }}
    </h2>
    
    @if($subtitle)
        <p class="text-lg text-brand-body max-w-3xl mx-auto">
            {{ $subtitle }}
        </p>
    @endif
    
    <div class="divider-gold w-24 mx-auto mt-6"></div>
</div>
