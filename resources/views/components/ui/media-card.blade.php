@props([
    'media',
    'showCaption' => true,
    'showTrip' => true
])

<div class="card-hover group">
    @if($media->type === 'photo' && $media->file_path)
        <div class="aspect-4-3 overflow-hidden rounded-xl mb-4">
            <img 
                src="{{ asset('storage/' . $media->file_path) }}" 
                alt="{{ $media->title }}"
                class="w-full h-full object-cover group-hover:scale-105 transition-transform duration-300"
                loading="lazy"
            >
        </div>
    @elseif($media->type === 'video' && $media->video_url)
        <div class="aspect-video overflow-hidden rounded-xl mb-4 relative" id="video-container-{{ $media->id }}">
            <!-- Video Thumbnail (shown initially) -->
            <div id="video-thumbnail-{{ $media->id }}" class="w-full h-full relative">
                <img 
                    src="{{ $media->thumbnail_url }}" 
                    alt="{{ $media->title }}"
                    class="w-full h-full object-cover"
                    loading="lazy"
                >
                <div class="absolute inset-0 bg-black bg-opacity-30 flex items-center justify-center group-hover:bg-opacity-20 transition-all">
                    <div class="w-16 h-16 bg-white bg-opacity-90 rounded-full flex items-center justify-center cursor-pointer hover:scale-110 transition-transform"
                         onclick="loadVideo({{ $media->id }}, '{{ $media->video_url }}')">
                        <svg class="w-8 h-8 text-brand-dark-grey ml-1" fill="currentColor" viewBox="0 0 20 20">
                            <path d="M8 5v10l8-5-8-5z"/>
                        </svg>
                    </div>
                </div>
            </div>
            
            <!-- Video Player (hidden initially) -->
            <div id="video-player-{{ $media->id }}" class="w-full h-full hidden">
                <iframe 
                    id="video-iframe-{{ $media->id }}"
                    class="w-full h-full rounded-xl"
                    frameborder="0"
                    allowfullscreen
                    allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture">
                </iframe>
            </div>
        </div>
    @endif
    
    <div class="space-y-3">
        <h3 class="text-lg font-bold text-brand-heading group-hover:text-brand-sky-blue transition-colors">
            {{ $media->title }}
        </h3>
        
        @if($showCaption && $media->caption)
            <p class="text-brand-body text-sm">
                {{ $media->caption }}
            </p>
        @endif
        
        @if($showTrip && $media->trip)
            <div class="flex items-center gap-2 text-sm text-brand-body">
                <span class="badge-sky">{{ $media->trip->title }}</span>
            </div>
        @endif
        
        <div class="flex items-center justify-between pt-2">
            <span class="badge-{{ $media->type === 'photo' ? 'gold' : 'sky' }}">
                {{ ucfirst($media->type) }}
            </span>
            
            @if($media->type === 'video')
                <div class="flex gap-2">
                    <button 
                        onclick="loadVideo({{ $media->id }}, '{{ $media->video_url }}')"
                        class="btn-outline text-sm py-2 px-4 hover:bg-brand-sky-blue hover:text-white transition-colors">
                        <svg class="w-4 h-4 mr-1 inline" fill="currentColor" viewBox="0 0 20 20">
                            <path d="M8 5v10l8-5-8-5z"/>
                        </svg>
                        Play
                    </button>
                    <a href="{{ $media->video_url }}" 
                       target="_blank" 
                       rel="noopener noreferrer"
                       class="btn-outline text-sm py-2 px-4 hover:bg-brand-gold hover:text-white transition-colors">
                        <svg class="w-4 h-4 mr-1 inline" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14"></path>
                        </svg>
                        External
                    </a>
                </div>
                
                <!-- Debug Info (remove in production) -->
                <div class="text-xs text-gray-500 mt-1">
                    Video URL: {{ $media->video_url }}<br>
                    Thumbnail: {{ $media->thumbnail_url }}
                </div>
                
                <!-- Test Button (remove in production) -->
                <button 
                    onclick="testVideoPlayer({{ $media->id }}, '{{ $media->video_url }}')"
                    class="text-xs bg-red-500 text-white px-2 py-1 rounded mt-1 hover:bg-red-600">
                    Test Player
                </button>
            @endif
        </div>
    </div>
</div>

<!-- Video player functionality is loaded from /js/video-player.js -->
