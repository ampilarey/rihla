@extends('layouts.app')

@section('title', 'Add New Media - Admin')
@section('description', 'Add new media to the gallery')

@section('content')
<div class="container mx-auto px-4 py-8">
    <!-- Header -->
    <div class="flex justify-between items-center mb-8">
        <h1 class="text-3xl font-bold text-gray-800">{{ __('Add New Media') }}</h1>
        <a href="{{ route('admin.media.index') }}" 
           class="btn-secondary">
            {{ __('← Back to Media') }}
        </a>
    </div>

    <!-- Form -->
    <div class="max-w-4xl">
        <form method="POST" action="{{ route('admin.media.store') }}" enctype="multipart/form-data" class="space-y-6">
            @csrf
            
            <!-- Media Type Selection -->
            <div class="card">
                <h3 class="text-lg font-semibold text-gray-800 mb-4">{{ __('Media Type') }}</h3>
                <div class="flex space-x-6">
                    <label class="flex items-center">
                        <input type="radio" name="type" value="photo" class="mr-2" checked>
                        <span>{{ __('Photo') }}</span>
                    </label>
                    <label class="flex items-center">
                        <input type="radio" name="type" value="video" class="mr-2">
                        <span>{{ __('Video') }}</span>
                    </label>
                </div>
            </div>

            <!-- Photo Upload Section -->
            <div id="photo-section" class="card">
                <h3 class="text-lg font-semibold text-gray-800 mb-4">{{ __('Photo Upload') }}</h3>
                <div class="space-y-4">
                    <div>
                        <label for="file_path" class="block text-sm font-medium text-gray-700 mb-2">
                            {{ __('Photo File') }} <span class="text-red-500">*</span>
                        </label>
                        <input type="file" 
                               id="file_path" 
                               name="file_path" 
                               accept="image/jpeg,image/png,image/webp"
                               class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-brand-sky-blue focus:border-brand-sky-blue"
                               required>
                        <p class="text-sm text-gray-500 mt-1">{{ __('Supported formats: JPEG, PNG, WebP. Max size: 6MB') }}</p>
                    </div>
                </div>
            </div>

            <!-- Video URL Section -->
            <div id="video-section" class="card hidden">
                <h3 class="text-lg font-semibold text-gray-800 mb-4">{{ __('Video URL') }}</h3>
                <div class="space-y-4">
                    <div>
                        <label for="video_url" class="block text-sm font-medium text-gray-700 mb-2">
                            {{ __('Video URL') }} <span class="text-red-500">*</span>
                        </label>
                        <input type="url" 
                               id="video_url" 
                               name="video_url" 
                               placeholder="https://www.youtube.com/watch?v=..."
                               class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-brand-sky-blue focus:border-brand-sky-blue">
                        <p class="text-sm text-gray-500 mt-1">{{ __('Enter YouTube, Vimeo, or other video platform URL') }}</p>
                    </div>
                </div>
            </div>

            <!-- Media Details -->
            <div class="card">
                <h3 class="text-lg font-semibold text-gray-800 mb-4">{{ __('Media Details') }}</h3>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                    <div>
                        <label for="title" class="block text-sm font-medium text-gray-700 mb-2">
                            {{ __('Title') }}
                        </label>
                        <input type="text" 
                               id="title" 
                               name="title" 
                               value="{{ old('title') }}"
                               class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-brand-sky-blue focus:border-brand-sky-blue"
                               placeholder="{{ __('Enter media title') }}">
                    </div>

                    <div>
                        <label for="trip_id" class="block text-sm font-medium text-gray-700 mb-2">
                            {{ __('Associated Trip') }}
                        </label>
                        <select id="trip_id" 
                                name="trip_id" 
                                class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-brand-sky-blue focus:border-brand-sky-blue">
                            <option value="">{{ __('No trip (standalone media)') }}</option>
                            @foreach($trips as $trip)
                                <option value="{{ $trip->id }}" {{ old('trip_id') == $trip->id ? 'selected' : '' }}>
                                    {{ $trip->title }}
                                </option>
                            @endforeach
                        </select>
                    </div>

                    <div class="md:col-span-2">
                        <label for="caption" class="block text-sm font-medium text-gray-700 mb-2">
                            {{ __('Caption/Description') }}
                        </label>
                        <textarea id="caption" 
                                  name="caption" 
                                  rows="3"
                                  class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-brand-sky-blue focus:border-brand-sky-blue"
                                  placeholder="{{ __('Enter media description or caption') }}">{{ old('caption') }}</textarea>
                    </div>

                    <div>
                        <label for="sort_order" class="block text-sm font-medium text-gray-700 mb-2">
                            {{ __('Sort Order') }}
                        </label>
                        <input type="number" 
                               id="sort_order" 
                               name="sort_order" 
                               value="{{ old('sort_order', 0) }}"
                               min="0"
                               class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-brand-sky-blue focus:border-brand-sky-blue">
                        <p class="text-sm text-gray-500 mt-1">{{ __('Lower numbers appear first') }}</p>
                    </div>

                    <div class="flex items-center">
                        <input type="checkbox" 
                               id="is_published" 
                               name="is_published" 
                               value="1"
                               {{ old('is_published', true) ? 'checked' : '' }}
                               class="h-4 w-4 text-brand-sky-blue focus:ring-brand-sky-blue border-gray-300 rounded">
                        <label for="is_published" class="ml-2 block text-sm text-gray-700">
                            {{ __('Publish immediately') }}
                        </label>
                    </div>
                </div>
            </div>

            <!-- Submit Buttons -->
            <div class="flex justify-end space-x-4">
                <a href="{{ route('admin.media.index') }}" 
                   class="btn-secondary">
                    {{ __('Cancel') }}
                </a>
                <button type="submit" 
                        class="btn-primary">
                    {{ __('Create Media') }}
                </button>
            </div>
        </form>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const typeRadios = document.querySelectorAll('input[name="type"]');
    const photoSection = document.getElementById('photo-section');
    const videoSection = document.getElementById('video-section');
    const fileInput = document.getElementById('file_path');
    const videoUrlInput = document.getElementById('video_url');

    function toggleSections() {
        const selectedType = document.querySelector('input[name="type"]:checked').value;
        
        if (selectedType === 'photo') {
            photoSection.classList.remove('hidden');
            videoSection.classList.add('hidden');
            fileInput.required = true;
            videoUrlInput.required = false;
        } else {
            photoSection.classList.add('hidden');
            videoSection.classList.remove('hidden');
            fileInput.required = false;
            videoUrlInput.required = true;
        }
    }

    typeRadios.forEach(radio => {
        radio.addEventListener('change', toggleSections);
    });

    // Initial setup
    toggleSections();
});
</script>
@endsection
