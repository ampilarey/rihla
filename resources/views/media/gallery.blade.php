@extends('layouts.app')

@section('content')
<div class="container mx-auto px-4 py-8">
    <h1 class="text-4xl font-bold text-center mb-12 text-gray-800">{{ __('Gallery') }}</h1>
    
    <!-- Filter Pills -->
    <div class="flex justify-center mb-8">
        <div class="bg-white rounded-2xl p-1 shadow-soft">
            <a href="{{ route('gallery') }}" 
               class="filter-pill {{ !$type ? 'active' : '' }} px-6 py-3 rounded-xl font-medium transition-colors"
               @if(!$type) aria-current="page" @endif>
                {{ __('All') }}
            </a>
            <a href="{{ route('gallery', ['type' => 'photo']) }}" 
               class="filter-pill {{ $type === 'photo' ? 'active' : '' }} px-6 py-3 rounded-xl font-medium transition-colors"
               @if($type === 'photo') aria-current="page" @endif>
                {{ __('Photos') }}
            </a>
            <a href="{{ route('gallery', ['type' => 'video']) }}" 
               class="filter-pill {{ $type === 'video' ? 'active' : '' }} px-6 py-3 rounded-xl font-medium transition-colors"
               @if($type === 'video') aria-current="page" @endif>
                {{ __('Videos') }}
            </a>
        </div>
    </div>
    
    <!-- Media Grid -->
    @if($media->count() > 0)
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-6">
            @foreach($media as $item)
                <div class="group">
                    @if($item->type === 'photo')
                        <div class="relative overflow-hidden rounded-2xl shadow-soft hover:shadow-lg transition-shadow duration-300">
                            <x-stored-image :path="$item->thumb_path ?? $item->file_path"
                                            :alt="$item->title"
                                            class="w-full h-48 object-cover transition-transform duration-300 group-hover:scale-105" />
                            
                            @if($item->title || $item->caption)
                            <div class="absolute bottom-0 left-0 right-0 bg-gradient-to-t from-black/70 to-transparent p-4">
                                @if($item->title)
                                <h2 class="text-white font-semibold mb-1">{{ $item->title }}</h2>
                                @endif
                                @if($item->caption)
                                <p class="text-white/90 text-sm">{{ $item->caption }}</p>
                                @endif
                            </div>
                            @endif
                        </div>
                    @else
                        <div class="relative overflow-hidden rounded-2xl shadow-soft hover:shadow-lg transition-shadow duration-300">
                            <div class="w-full h-48 bg-gray-200 flex items-center justify-center relative">
                                <img src="{{ $item->thumbnail_url }}" 
                                     alt="{{ $item->title }}" 
                                     class="w-full h-full object-cover"
         loading="lazy"
         decoding="async">
                                <div class="absolute inset-0 bg-black/20 flex items-center justify-center">
                                    <div class="bg-white/90 rounded-full p-3">
                                        <svg aria-hidden="true" focusable="false" class="w-8 h-8 text-wine-500" fill="currentColor" viewBox="0 0 24 24">
                                            <path d="M8 5v14l11-7z"/>
                                        </svg>
                                    </div>
                                </div>
                            </div>
                            
                            @if($item->title || $item->caption)
                            <div class="absolute bottom-0 left-0 right-0 bg-gradient-to-t from-black/70 to-transparent p-4">
                                @if($item->title)
                                <h2 class="text-white font-semibold mb-1">{{ $item->title }}</h2>
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
        
        <!-- Pagination -->
        <div class="mt-12">
            {{ $media->links() }}
        </div>
    @else
        <div class="text-center py-12">
            <p class="text-gray-500 text-lg">{{ __('No media found.') }}</p>
        </div>
    @endif
</div>

<style>
.filter-pill.active {
    background-color: {{ \App\Support\Brand::WINE }};
    color: white;
}

.filter-pill:not(.active) {
    color: {{ \App\Support\Brand::INK_MUTED }};
}

.filter-pill:not(.active):hover {
    color: {{ \App\Support\Brand::WINE }};
}
</style>
@endsection
