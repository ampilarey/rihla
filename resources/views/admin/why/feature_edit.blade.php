@extends('layouts.app')

@section('content')
<div class="container mx-auto px-4 py-8">
    <div class="max-w-2xl mx-auto">
        <div class="mb-8">
            <div class="flex items-center justify-between">
                <div>
                    <h1 class="text-3xl font-bold text-gray-900 mb-2">Edit Feature</h1>
                    <p class="text-gray-600">Update feature details for "{{ $feature->section->title }}"</p>
                </div>
                <a href="{{ route('admin.why-sections.edit', $feature->section) }}" 
                   class="bg-gray-500 text-white px-4 py-2 rounded-md hover:bg-gray-600 focus:outline-none focus:ring-2 focus:ring-wine-500">
                    Back to Section
                </a>
            </div>
        </div>

        @if(session('success'))
            <div class="bg-success/10 border border-success/40 text-success-dark px-4 py-3 rounded mb-6">
                {{ session('success') }}
            </div>
        @endif

        @if($errors->any())
            <div class="bg-error/10 border border-error/40 text-error-dark px-4 py-3 rounded mb-6">
                <ul class="list-disc list-inside">
                    @foreach($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <div class="bg-white rounded-lg shadow-md p-6">
            <form action="{{ route('admin.features.update', $feature) }}" method="POST" enctype="multipart/form-data">
                @csrf
                @method('PUT')
                
                <!-- Hidden section ID field -->
                <input type="hidden" name="why_section_id" value="{{ $feature->section->id }}">
                @error('why_section_id')
                    <div class="bg-error/10 border border-error/40 text-error-dark px-4 py-3 rounded mb-4">
                        <p class="text-sm">{{ $message }}</p>
                    </div>
                @enderror
                
                <div class="space-y-4">
                    <div>
                        <label for="title" class="block text-sm font-medium text-gray-700 mb-2">Title *</label>
                        <input type="text" id="title" name="title" value="{{ old('title', $feature->title) }}" 
                               class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-wine-500" required>
                        @error('title')
                            <p class="text-error text-sm mt-1">{{ $message }}</p>
                        @enderror
                    </div>

                    <div>
                        <label for="icon" class="block text-sm font-medium text-gray-700 mb-2">Icon</label>
                        <input type="text" id="icon" name="icon" value="{{ old('icon', $feature->icon) }}" 
                               placeholder="e.g., star, heart, shield"
                               class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-wine-500">
                        @error('icon')
                            <p class="text-error text-sm mt-1">{{ $message }}</p>
                        @enderror
                    </div>

                    <div>
                        <label for="text" class="block text-sm font-medium text-gray-700 mb-2">Text</label>
                        <textarea id="text" name="text" rows="4" 
                                  class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-wine-500">{{ old('text', $feature->text) }}</textarea>
                        @error('text')
                            <p class="text-error text-sm mt-1">{{ $message }}</p>
                        @enderror
                    </div>

                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div>
                            <label for="link_url" class="block text-sm font-medium text-gray-700 mb-2">Link URL (Optional)</label>
                            <input type="url" id="link_url" name="link_url" value="{{ old('link_url', $feature->link_url) }}" 
                                   placeholder="https://example.com"
                                   class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-wine-500">
                            @error('link_url')
                                <p class="text-error text-sm mt-1">{{ $message }}</p>
                            @enderror
                        </div>
                        <div>
                            <label for="link_text" class="block text-sm font-medium text-gray-700 mb-2">Link Text (Optional)</label>
                            <input type="text" id="link_text" name="link_text" value="{{ old('link_text', $feature->link_text) }}" 
                                   placeholder="e.g., Learn More, Book Now, View Details"
                                   class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-wine-500">
                            @error('link_text')
                                <p class="text-error text-sm mt-1">{{ $message }}</p>
                            @enderror
                        </div>
                    </div>

                    <div>
                        <label for="image" class="block text-sm font-medium text-gray-700 mb-2">Feature Image</label>
                        @if($feature->image_path)
                            <div class="mb-2">
                                <img src="{{ Storage::url($feature->image_path) }}" alt="Current image" class="w-32 h-24 object-cover rounded"
         loading="lazy"
         decoding="async">
                            </div>
                        @endif
                        <input type="file" id="image" name="image" accept="image/*" 
                               class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-wine-500">
                        @error('image')
                            <p class="text-error text-sm mt-1">{{ $message }}</p>
                        @enderror
                    </div>

                    <div>
                        <label for="background_color" class="block text-sm font-medium text-gray-700 mb-2">Background Color (Optional)</label>
                        <div class="flex items-center space-x-3">
                            <input type="color" id="background_color" name="background_color" 
                                   value="{{ old('background_color', $feature->background_color) ?: '#ffffff' }}"
                                   class="w-16 h-10 border border-gray-300 rounded-md cursor-pointer">
                            <input type="text" id="background_color_text" placeholder="#ffffff" 
                                   value="{{ old('background_color', $feature->background_color) ?: '#ffffff' }}"
                                   class="flex-1 px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-wine-500 text-sm"
                                   pattern="^#[0-9A-Fa-f]{6}$">
                            <span class="text-xs text-gray-500">or type hex code</span>
                        </div>
                        @error('background_color')
                            <p class="text-error text-sm mt-1">{{ $message }}</p>
                        @enderror
                    </div>

                    <div>
                        <label for="sort_order" class="block text-sm font-medium text-gray-700 mb-2">Sort Order</label>
                        <input type="number" id="sort_order" name="sort_order" value="{{ old('sort_order', $feature->sort_order) }}" min="0"
                               class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-wine-500">
                        @error('sort_order')
                            <p class="text-error text-sm mt-1">{{ $message }}</p>
                        @enderror
                    </div>

                    <div class="flex items-center">
                        <input type="checkbox" id="is_active" name="is_active" value="1" {{ $feature->is_active ? 'checked' : '' }} 
                               class="h-4 w-4 text-wine-500 focus:ring-wine-500 border-gray-300 rounded">
                        <label for="is_active" class="ml-2 block text-sm text-gray-900">Active</label>
                        @error('is_active')
                            <p class="text-error text-sm mt-1">{{ $message }}</p>
                        @enderror
                    </div>

                    <div class="pt-4 flex space-x-3">
                        <button type="submit" class="flex-1 bg-wine-500 text-white px-4 py-2 rounded-md hover:bg-wine-700 focus:outline-none focus:ring-2 focus:ring-wine-500">
                            Update Feature
                        </button>
                        
                        <a href="{{ route('admin.why-sections.edit', $feature->section) }}" 
                           class="flex-1 bg-gray-500 text-white px-4 py-2 rounded-md hover:bg-gray-600 focus:outline-none focus:ring-2 focus:ring-wine-500 text-center">
                            Cancel
                        </a>
                    </div>
                </div>
            </form>
            
            <script nonce="@cspNonce">
                document.addEventListener('DOMContentLoaded', function() {
                    // Synchronize color picker and text input
                    const colorPicker = document.getElementById('background_color');
                    const colorText = document.getElementById('background_color_text');
                    
                    if (colorPicker && colorText) {
                        colorPicker.addEventListener('input', function() {
                            colorText.value = this.value;
                        });
                        
                        colorText.addEventListener('input', function() {
                            if (this.value.match(/^#[0-9A-Fa-f]{6}$/)) {
                                colorPicker.value = this.value;
                            }
                        });
                    }
                });
            </script>
        </div>
    </div>
</div>
@endsection
