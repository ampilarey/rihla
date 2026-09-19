@extends('layouts.app')

@section('title', 'View Media - Admin')
@section('description', 'View media details in the gallery')

@section('content')
<div class="container mx-auto px-4 py-8">
    <!-- Header -->
    <div class="flex justify-between items-center mb-8">
        <h1 class="text-3xl font-bold text-gray-800">{{ __('Media Details') }}</h1>
        <div class="flex space-x-4">
            <a href="{{ route('admin.media.edit', $medium) }}" 
               class="btn-primary">
                {{ __('Edit Media') }}
            </a>
            <a href="{{ route('admin.media.index') }}" 
               class="btn-secondary">
                {{ __('← Back to Media') }}
            </a>
        </div>
    </div>

    <!-- Media Content -->
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-8">
        <!-- Media Display -->
        <div class="card">
            <h3 class="text-lg font-semibold text-gray-800 mb-4">{{ __('Media Content') }}</h3>
            
            @if($medium->type === 'photo')
                <div class="space-y-4">
                    @if($medium->file_path)
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-2">{{ __('Large Version') }}</label>
                            <img src="{{ asset('storage/' . $medium->file_path) }}" 
                                 alt="{{ $medium->title }}" 
                                 class="w-full h-64 object-cover rounded-lg border"
         loading="lazy"
         decoding="async">
                        </div>
                    @endif
                    
                    @if($medium->thumb_path)
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-2">{{ __('Thumbnail') }}</label>
                            <img src="{{ asset('storage/' . $medium->thumb_path) }}" 
                                 alt="{{ $medium->title }}" 
                                 class="w-32 h-32 object-cover rounded-lg border"
         loading="lazy"
         decoding="async">
                        </div>
                    @endif
                </div>
            @else
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-2">{{ __('Video URL') }}</label>
                    <div class="bg-gray-100 p-4 rounded-lg">
                        <a href="{{ $medium->video_url }}" 
                           target="_blank" 
                           class="text-wine-500 hover:underline break-all">
                            {{ $medium->video_url }}
                        </a>
                    </div>
                </div>
            @endif
        </div>

        <!-- Media Information -->
        <div class="card">
            <h3 class="text-lg font-semibold text-gray-800 mb-4">{{ __('Media Information') }}</h3>
            
            <div class="space-y-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700">{{ __('Type') }}</label>
                    <p class="mt-1 text-sm text-gray-900">
                        <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium 
                            {{ $medium->type === 'photo' ? 'bg-wine-50 text-wine-600' : 'bg-wine-50 text-wine-600' }}">
                            {{ ucfirst($medium->type) }}
                        </span>
                    </p>
                </div>

                <div>
                    <label class="block text-sm font-medium text-gray-700">{{ __('Title') }}</label>
                    <p class="mt-1 text-sm text-gray-900">{{ $medium->title ?: 'No title' }}</p>
                </div>

                <div>
                    <label class="block text-sm font-medium text-gray-700">{{ __('Caption/Description') }}</label>
                    <p class="mt-1 text-sm text-gray-900">{{ $medium->caption ?: 'No description' }}</p>
                </div>

                <div>
                    <label class="block text-sm font-medium text-gray-700">{{ __('Associated Trip') }}</label>
                    <p class="mt-1 text-sm text-gray-900">
                        @if($medium->trip)
                            <a href="{{ route('admin.trips.edit', $medium->trip) }}" 
                               class="text-wine-500 hover:underline">
                                {{ $medium->trip->title }}
                            </a>
                        @else
                            <span class="text-gray-500">No trip associated</span>
                        @endif
                    </p>
                </div>

                <div>
                    <label class="block text-sm font-medium text-gray-700">{{ __('Sort Order') }}</label>
                    <p class="mt-1 text-sm text-gray-900">{{ $medium->sort_order }}</p>
                </div>

                <div>
                    <label class="block text-sm font-medium text-gray-700">{{ __('Status') }}</label>
                    <p class="mt-1 text-sm text-gray-900">
                        <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium 
                            {{ $medium->is_published ? 'bg-success/10 text-success-dark' : 'bg-warning/10 text-warning-dark' }}">
                            {{ $medium->is_published ? 'Published' : 'Draft' }}
                        </span>
                    </p>
                </div>

                <div>
                    <label class="block text-sm font-medium text-gray-700">{{ __('Created') }}</label>
                    <p class="mt-1 text-sm text-gray-900">{{ $medium->created_at->format('F j, Y \a\t g:i A') }}</p>
                </div>

                <div>
                    <label class="block text-sm font-medium text-gray-700">{{ __('Last Updated') }}</label>
                    <p class="mt-1 text-sm text-gray-900">{{ $medium->updated_at->format('F j, Y \a\t g:i A') }}</p>
                </div>
            </div>
        </div>
    </div>

    <!-- Actions -->
    <div class="mt-8 flex justify-between items-center">
        <div class="flex space-x-4">
            <a href="{{ route('admin.media.edit', $medium) }}" 
               class="btn-primary">
                {{ __('Edit Media') }}
            </a>
            
            <form method="POST" action="{{ route('admin.media.destroy', $medium) }}" class="inline" style="display: inline;">
                @csrf
                @method('DELETE')
                <button type="submit" 
                        class="bg-error hover:bg-error-dark text-white font-medium py-2 px-4 rounded-lg transition-colors duration-200"
                        onclick="return confirm('{{ __('Are you sure you want to delete this media?') }}')">
                    {{ __('Delete Media') }}
                </button>
            </form>
        </div>
        
        <a href="{{ route('admin.media.index') }}" 
           class="btn-secondary">
            {{ __('← Back to Media List') }}
        </a>
    </div>
</div>
@endsection
