@extends('layouts.app')

@section('content')
<div class="container mx-auto px-4 py-8">
    <!-- Trip Header -->
    <div class="max-w-4xl mx-auto mb-12">
        @if($trip->cover_image)
        <div class="relative mb-8">
            <img src="{{ Storage::url($trip->cover_image) }}" 
                 alt="{{ $trip->title }}" 
                 class="w-full h-96 object-cover rounded-2xl">
            <div class="absolute top-4 left-4">
                @if($trip->status === 'current')
                    <span class="bg-wine-500 text-white px-4 py-2 rounded-full text-sm font-medium">
                        {{ __('Now') }}
                    </span>
                @elseif($trip->status === 'upcoming')
                    <span class="bg-gold-500 text-ink px-4 py-2 rounded-full text-sm font-medium">
                        {{ __('Coming Soon') }}
                    </span>
                @else
                    <span class="bg-gray-500 text-white px-4 py-2 rounded-full text-sm font-medium">
                        {{ __('Past') }}
                    </span>
                @endif
            </div>
        </div>
        @endif
        
        <div class="text-center">
            <h1 class="text-4xl font-bold text-gray-800 mb-4">{{ $trip->title }}</h1>
            
            <div class="flex flex-wrap justify-center gap-6 text-gray-600 mb-6">
                <div class="flex items-center">
                    <svg class="w-5 h-5 mr-2 text-wine-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"></path>
                    </svg>
                    <span>{{ $trip->date_start->format('M d, Y') }} - {{ $trip->date_end->format('M d, Y') }}</span>
                </div>
                
                @if($trip->location)
                <div class="flex items-center">
                    <svg class="w-5 h-5 mr-2 text-wine-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z"></path>
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 11a3 3 0 11-6 0 3 3 0 016 0z"></path>
                    </svg>
                    <span>{{ $trip->location }}</span>
                </div>
                @endif
                
                @if($trip->price_from_mvr)
                <div class="flex items-center">
                    <svg class="w-5 h-5 mr-2 text-wine-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1"></path>
                    </svg>
                    <span>{{ __('From') }} MVR {{ number_format($trip->price_from_mvr) }}</span>
                </div>
                @endif
            </div>
            
            @if($trip->summary)
            <p class="text-xl text-gray-600 max-w-3xl mx-auto">{{ $trip->summary }}</p>
            @endif
        </div>
    </div>
    
    <!-- Trip Details -->
    @if($trip->details)
    <div class="max-w-4xl mx-auto mb-12">
        <div class="card">
            <h2 class="text-2xl font-bold text-gray-800 mb-6">{{ __('Trip Details') }}</h2>
            <div class="prose max-w-none text-gray-600">
                {!! nl2br(e($trip->details)) !!}
            </div>
        </div>
    </div>
    @endif
    
    <!-- Media Gallery -->
    @if($media->count() > 0)
    <div class="max-w-6xl mx-auto">
        <h2 class="text-3xl font-bold text-center mb-12 text-gray-800">{{ __('Trip Gallery') }}</h2>
        
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
            @foreach($media as $item)
                <div class="group">
                    @if($item->type === 'photo')
                        <div class="relative overflow-hidden rounded-2xl shadow-soft hover:shadow-lg transition-shadow duration-300">
                            <img src="{{ Storage::url($item->thumb_path ?? $item->file_path) }}" 
                                 alt="{{ $item->title }}" 
                                 class="w-full h-64 object-cover transition-transform duration-300 group-hover:scale-105"
                                 loading="lazy">
                            
                            @if($item->title || $item->caption)
                            <div class="absolute bottom-0 left-0 right-0 bg-gradient-to-t from-black/70 to-transparent p-4">
                                @if($item->title)
                                <h3 class="text-white font-semibold mb-1">{{ $item->title }}</h3>
                                @endif
                                @if($item->caption)
                                <p class="text-white/90 text-sm">{{ $item->caption }}</p>
                                @endif
                            </div>
                            @endif
                        </div>
                    @else
                        <div class="relative overflow-hidden rounded-2xl shadow-soft hover:shadow-lg transition-shadow duration-300">
                            <div class="w-full h-64 bg-gray-200 flex items-center justify-center relative">
                                <img src="{{ $item->thumbnail_url }}" 
                                     alt="{{ $item->title }}" 
                                     class="w-full h-full object-cover">
                                <div class="absolute inset-0 bg-black/20 flex items-center justify-center">
                                    <div class="bg-white/90 rounded-full p-3">
                                        <svg class="w-8 h-8 text-wine-500" fill="currentColor" viewBox="0 0 24 24">
                                            <path d="M8 5v14l11-7z"/>
                                        </svg>
                                    </div>
                                </div>
                            </div>
                            
                            @if($item->title || $item->caption)
                            <div class="absolute bottom-0 left-0 right-0 bg-gradient-to-t from-black/70 to-transparent p-4">
                                @if($item->title)
                                <h3 class="text-white font-semibold mb-1">{{ $item->title }}</h3>
                                @endif
                                @if($item->caption)
                                <p class="text-white/90 text-sm">{{ $item->caption }}</p>
                                @endif
                            </div>
                            @endif
                        </div>
                    @endif
                </div>
            @endforeach
        </div>
    </div>
    @endif
    
    <!-- CTA Section -->
    <div class="max-w-4xl mx-auto mt-16 text-center">
        <div class="card">
            <h3 class="text-2xl font-bold text-gray-800 mb-4">{{ __('Interested in This Trip?') }}</h3>
            <p class="text-gray-600 mb-6">{{ __('trip_inquiry') }}</p>
            <div class="flex flex-col sm:flex-row gap-4 justify-center">
                <a href="https://wa.me/{{ $socialSettings['whatsapp_number'] ?? '9607972434' }}" 
                   target="_blank" 
                   class="btn-primary">
                    {{ __('cta_whatsapp') }}
                </a>
                <a href="{{ route('contact') }}" class="btn-secondary">
                    {{ __('Contact Us') }}
                </a>
            </div>
        </div>
    </div>
</div>
@endsection
