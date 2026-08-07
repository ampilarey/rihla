<div class="card hover:shadow-lg transition-shadow duration-300">
    @if($trip->cover_image)
    <div class="relative mb-4">
        <img src="{{ Storage::url($trip->cover_image) }}" 
             alt="{{ $trip->title }}" 
             class="w-full h-48 object-cover rounded-2xl">
        <div class="absolute top-3 left-3">
            @if($trip->status === 'current')
                <span class="bg-brand-green text-white px-3 py-1 rounded-full text-sm font-medium">
                    {{ __('Now') }}
                </span>
            @elseif($trip->status === 'upcoming')
                <span class="bg-brand-gold text-white px-3 py-1 rounded-full text-sm font-medium">
                    {{ __('Coming Soon') }}
                </span>
            @else
                <span class="bg-gray-500 text-white px-3 py-1 rounded-full text-sm font-medium">
                    {{ __('Past') }}
                </span>
            @endif
        </div>
    </div>
    @endif
    
    <div class="mb-4">
        <h3 class="text-xl font-bold text-gray-800 mb-2">{{ $trip->title }}</h3>
        
        <div class="space-y-2 text-sm text-gray-600">
            <div class="flex items-center">
                <svg class="w-4 h-4 mr-2 text-brand-green" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"></path>
                </svg>
                <span>{{ $trip->date_start->format('M d') }} - {{ $trip->date_end->format('M d, Y') }}</span>
            </div>
            
            @if($trip->location)
            <div class="flex items-center">
                <svg class="w-4 h-4 mr-2 text-brand-green" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z"></path>
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 11a3 3 0 11-6 0 3 3 0 016 0z"></path>
                </svg>
                <span>{{ $trip->location }}</span>
            </div>
            @endif
            
            @if($trip->price_from_mvr)
            <div class="flex items-center">
                <svg class="w-4 h-4 mr-2 text-brand-green" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1"></path>
                </svg>
                <span>{{ __('From') }} MVR {{ number_format($trip->price_from_mvr) }}</span>
            </div>
            @endif
        </div>
    </div>
    
    @if($trip->summary)
    <p class="text-gray-600 mb-4 line-clamp-3">{{ $trip->summary }}</p>
    @endif
    
    <a href="{{ route('trips.show', $trip->slug) }}" class="btn-primary w-full text-center">
        {{ __('View Details') }}
    </a>
</div>
