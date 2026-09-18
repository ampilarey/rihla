@props([
    'type' => 'message', // message, call, catalog
    'phone' => '9607972434',
    'catalog' => '9607972434'
])

@php
    $config = [
        'message' => [
            'url' => "https://wa.me/{$phone}",
            'icon' => 'M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm-2 15l-5-5 1.41-1.41L10 14.17l7.59-7.59L19 8l-9 9z',
            'color' => 'bg-success hover:bg-success-dark',
            'text' => 'Message Us'
        ],
        'call' => [
            'url' => "tel:+{$phone}",
            'icon' => 'M6.62 10.79c1.44 2.83 3.76 5.14 6.59 6.59l2.2-2.2c.27-.27.67-.36 1.02-.24 1.12.37 2.33.57 3.57.57.55 0 1 .45 1 1V20c0 .55-.45 1-1 1-9.39 0-17-7.61-17-17 0-.55.45-1 1-1h3.5c.55 0 1 .45 1 1 0 1.25.2 2.45.57 3.57.11.35.03.74-.25 1.02l-2.2 2.2z',
            'color' => 'bg-wine-500 hover:bg-wine-600',
            'text' => 'Call Us'
        ],
        'catalog' => [
            'url' => "https://wa.me/c/{$catalog}",
            'icon' => 'M19 3H5c-1.1 0-2 .9-2 2v14c0 1.1.9 2 2 2h14c1.1 0 2-.9 2-2V5c0-1.1-.9-2-2-2zm-5 14H7v-2h7v2zm3-4H7v-2h10v2zm0-4H7V7h10v2z',
            'color' => 'bg-gold-500 hover:bg-gold-600',
            'text' => 'View Catalog'
        ]
    ];
    
    $current = $config[$type];
@endphp

<a href="{{ $current['url'] }}" 
   target="_blank" 
   rel="noopener noreferrer"
   class="fixed bottom-6 right-6 {{ $current['color'] }} text-white p-4 rounded-full shadow-lg hover:shadow-xl transition-all duration-300 z-50 group"
   title="{{ $current['text'] }}">
    
    <svg class="w-6 h-6" fill="currentColor" viewBox="0 0 24 24">
        <path d="{{ $current['icon'] }}"/>
    </svg>
    
    <div class="absolute right-full mr-3 top-1/2 transform -translate-y-1/2 bg-ink text-white px-3 py-2 rounded-lg text-sm font-medium opacity-0 group-hover:opacity-100 transition-opacity duration-300 whitespace-nowrap">
        {{ $current['text'] }}
        <div class="absolute left-full top-1/2 transform -translate-y-1/2 border-4 border-transparent border-l-ink"></div>
    </div>
</a>
