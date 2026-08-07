@props([
    'trip',
    'showStatus' => true,
    'showPrice' => true,
    'showDates' => true
])

<div class="card-hover group">
    @if($trip->cover_image)
        <div class="aspect-4-3 overflow-hidden rounded-xl mb-4">
            <img 
                src="{{ asset('storage/' . $trip->cover_image) }}" 
                alt="{{ $trip->title }}"
                class="w-full h-full object-cover group-hover:scale-105 transition-transform duration-300"
                loading="lazy"
            >
        </div>
    @endif
    
    <div class="space-y-3">
        @if($showStatus)
            <div class="flex items-center gap-2">
                @switch($trip->status)
                    @case('current')
                        <span class="badge-emerald">Current Trip</span>
                        @break
                    @case('upcoming')
                        <span class="badge-sky">Upcoming</span>
                        @break
                    @case('past')
                        <span class="badge-gold">Past Trip</span>
                        @break
                @endswitch
            </div>
        @endif
        
        <h3 class="text-xl font-bold text-brand-heading group-hover:text-brand-sky-blue transition-colors">
            <a href="{{ route('trips.show', $trip) }}">
                {{ $trip->title }}
            </a>
        </h3>
        
        <p class="text-brand-body text-sm">
            📍 {{ $trip->location }}
        </p>
        
        @if($showDates && $trip->date_start)
            <p class="text-brand-body text-sm">
                📅 {{ $trip->date_start->format('M j, Y') }}
                @if($trip->date_end && $trip->date_end->ne($trip->date_start))
                    - {{ $trip->date_end->format('M j, Y') }}
                @endif
            </p>
        @endif
        
        <p class="text-brand-body text-sm line-clamp-2">
            {{ $trip->summary }}
        </p>
        
        @if($showPrice && $trip->price_from_mvr)
            <div class="pt-2">
                <span class="text-lg font-bold text-brand-emerald">
                    From MVR {{ number_format($trip->price_from_mvr) }}
                </span>
            </div>
        @endif
        
        <div class="pt-3">
            <a href="{{ route('trips.show', $trip) }}" 
               class="btn-sky inline-block">
                View Details
            </a>
        </div>
    </div>
</div>
