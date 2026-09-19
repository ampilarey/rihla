@extends('layouts.app')

@section('content')
<div class="container mx-auto px-4 py-8">
    <div class="mb-8">
        <h1 class="text-3xl font-bold text-gray-900 mb-2">Edit Why Section</h1>
        <p class="text-gray-600">Manage your "Why Choose Us" section content and features</p>
    </div>

    @if(session('success'))
        <div class="bg-success/10 border border-success/40 text-success-dark px-4 py-3 rounded mb-6">
            {{ session('success') }}
        </div>
    @endif

    <div class="grid grid-cols-1 lg:grid-cols-2 gap-8">
        <!-- Section Form -->
        <div class="bg-white rounded-lg shadow-md p-6">
            <h2 class="text-xl font-semibold text-gray-900 mb-4">Section Content</h2>
            
            <form action="{{ route('admin.why-sections.update', $section) }}" method="POST" enctype="multipart/form-data">
                @csrf
                @method('PUT')
                
                <div class="space-y-4">
                    <div>
                        <label for="locale" class="block text-sm font-medium text-gray-700 mb-2">Language *</label>
                        <select id="locale" name="locale" 
                                class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-wine-500" required>
                            <option value="en" {{ $section->locale === 'en' ? 'selected' : '' }}>English</option>
                            <option value="dv" {{ $section->locale === 'dv' ? 'selected' : '' }}>ދިވެހި (Dhivehi)</option>
                        </select>
                        @error('locale')
                            <p class="text-error text-sm mt-1">{{ $message }}</p>
                        @enderror
                    </div>

                    <div>
                        <label for="title" class="block text-sm font-medium text-gray-700 mb-2">Title *</label>
                        <input type="text" id="title" name="title" value="{{ old('title', $section->title) }}" 
                               class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-wine-500" required>
                        @error('title')
                            <p class="text-error text-sm mt-1">{{ $message }}</p>
                        @enderror
                    </div>

                    <div>
                        <label for="subtitle" class="block text-sm font-medium text-gray-700 mb-2">Subtitle</label>
                        <textarea id="subtitle" name="subtitle" rows="3" 
                                  class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-wine-500">{{ old('subtitle', $section->subtitle) }}</textarea>
                        @error('subtitle')
                            <p class="text-error text-sm mt-1">{{ $message }}</p>
                        @enderror
                    </div>

                    <div>
                        <label for="image" class="block text-sm font-medium text-gray-700 mb-2">Section Image</label>
                        @if($section->image_path)
                            <div class="mb-2">
                                <img src="{{ Storage::url($section->image_path) }}" alt="Current image" class="w-32 h-24 object-cover rounded"
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

                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div>
                            <label for="primary_cta_text" class="block text-sm font-medium text-gray-700 mb-2">Primary CTA Text</label>
                            <input type="text" id="primary_cta_text" name="primary_cta_text" value="{{ old('primary_cta_text', $section->primary_cta_text) }}" 
                                   class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-wine-500">
                            @error('primary_cta_text')
                                <p class="text-error text-sm mt-1">{{ $message }}</p>
                            @enderror
                        </div>
                        <div>
                            <label for="primary_cta_url" class="block text-sm font-medium text-gray-700 mb-2">Primary CTA URL</label>
                            <input type="url" id="primary_cta_url" name="primary_cta_url" value="{{ old('primary_cta_url', $section->primary_cta_url) }}"
                                   class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-wine-500">
                            @error('primary_cta_url')
                                <p class="text-error text-sm mt-1">{{ $message }}</p>
                            @enderror
                        </div>
                    </div>

                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div>
                            <label for="secondary_cta_text" class="block text-sm font-medium text-gray-700 mb-2">Secondary CTA Text</label>
                            <input type="text" id="secondary_cta_text" name="secondary_cta_text" value="{{ old('secondary_cta_text', $section->secondary_cta_text) }}" 
                                   class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-wine-500">
                            @error('secondary_cta_text')
                                <p class="text-error text-sm mt-1">{{ $message }}</p>
                            @enderror
                        </div>
                        <div>
                            <label for="secondary_cta_url" class="block text-sm font-medium text-gray-700 mb-2">Secondary CTA URL</label>
                            <input type="url" id="secondary_cta_url" name="secondary_cta_url" value="{{ old('secondary_cta_url', $section->secondary_cta_url) }}" 
                                   class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-wine-500">
                            @error('secondary_cta_url')
                                <p class="text-error text-sm mt-1">{{ $message }}</p>
                            @enderror
                        </div>
                    </div>

                    <div class="flex items-center">
                        <input type="checkbox" id="is_active" name="is_active" value="1" {{ $section->is_active ? 'checked' : '' }} 
                               class="h-4 w-4 text-wine-500 focus:ring-wine-500 border-gray-300 rounded">
                        <label for="is_active" class="ml-2 block text-sm text-gray-900">Active</label>
                    </div>
                    
                    <!-- Color Customization Section -->
                    <div class="border-t border-gray-200 pt-6 mt-6">
                        <h3 class="text-lg font-medium text-gray-900 mb-4">Color Customization</h3>
                        
                        <!-- Title and Subtitle Colors -->
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-4">
                            <div>
                                <label for="title_color" class="block text-sm font-medium text-gray-700 mb-2">Title Color</label>
                                <div class="flex items-center space-x-3">
                                    <input type="color" id="title_color" name="title_color" 
                                           value="{{ old('title_color', $section->title_color) ?: \App\Support\Brand::INK }}"
                                           class="w-16 h-10 border border-gray-300 rounded-md cursor-pointer">
                                    <input type="text" id="title_color_text" placeholder="{{ \App\Support\Brand::INK }}" 
                                           value="{{ old('title_color', $section->title_color) ?: \App\Support\Brand::INK }}"
                                           class="flex-1 px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-wine-500 text-sm">
                                </div>
                                @error('title_color')
                                    <p class="text-error text-sm mt-1">{{ $message }}</p>
                                @enderror
                            </div>
                            <div>
                                <label for="subtitle_color" class="block text-sm font-medium text-gray-700 mb-2">Subtitle Color</label>
                                <div class="flex items-center space-x-3">
                                    <input type="color" id="subtitle_color" name="subtitle_color" 
                                           value="{{ old('subtitle_color', $section->subtitle_color) ?: \App\Support\Brand::INK_MUTED }}"
                                           class="w-16 h-10 border border-gray-300 rounded-md cursor-pointer">
                                    <input type="text" id="subtitle_color_text" placeholder="{{ \App\Support\Brand::INK_MUTED }}" 
                                           value="{{ old('subtitle_color', $section->subtitle_color) ?: \App\Support\Brand::INK_MUTED }}"
                                           class="flex-1 px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-wine-500 text-sm">
                                </div>
                                @error('subtitle_color')
                                    <p class="text-error text-sm mt-1">{{ $message }}</p>
                                @enderror
                            </div>
                        </div>
                        
                        <!-- Primary CTA Colors -->
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-4">
                            <div>
                                <label for="primary_cta_bg_color" class="block text-sm font-medium text-gray-700 mb-2">Primary CTA Background</label>
                                <div class="flex items-center space-x-3">
                                    <input type="color" id="primary_cta_bg_color" name="primary_cta_bg_color" 
                                           value="{{ old('primary_cta_bg_color', $section->primary_cta_bg_color) ?: \App\Support\Brand::WINE }}"
                                           class="w-16 h-10 border border-gray-300 rounded-md cursor-pointer">
                                    <input type="text" id="primary_cta_bg_color_text" placeholder="{{ \App\Support\Brand::WINE }}" 
                                           value="{{ old('primary_cta_bg_color', $section->primary_cta_bg_color) ?: \App\Support\Brand::WINE }}"
                                           class="flex-1 px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-wine-500 text-sm">
                                </div>
                                @error('primary_cta_bg_color')
                                    <p class="text-error text-sm mt-1">{{ $message }}</p>
                                @enderror
                            </div>
                            <div>
                                <label for="primary_cta_text_color" class="block text-sm font-medium text-gray-700 mb-2">Primary CTA Text</label>
                                <div class="flex items-center space-x-3">
                                    <input type="color" id="primary_cta_text_color" name="primary_cta_text_color" 
                                           value="{{ old('primary_cta_text_color', $section->primary_cta_text_color) ?: '#ffffff' }}"
                                           class="w-16 h-10 border border-gray-300 rounded-md cursor-pointer">
                                    <input type="text" id="primary_cta_text_color_text" placeholder="#ffffff" 
                                           value="{{ old('primary_cta_text_color', $section->primary_cta_text_color) ?: '#ffffff' }}"
                                           class="flex-1 px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-wine-500 text-sm">
                                </div>
                                @error('primary_cta_text_color')
                                    <p class="text-error text-sm mt-1">{{ $message }}</p>
                                @enderror
                            </div>
                        </div>
                        
                        <!-- Secondary CTA Colors -->
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                            <div>
                                <label for="secondary_cta_bg_color" class="block text-sm font-medium text-gray-700 mb-2">Secondary CTA Background</label>
                                <div class="flex items-center space-x-3">
                                    <input type="color" id="secondary_cta_bg_color" name="secondary_cta_bg_color" 
                                           value="{{ old('secondary_cta_bg_color', $section->secondary_cta_bg_color) ?: '#ffffff' }}"
                                           class="w-16 h-10 border border-gray-300 rounded-md cursor-pointer">
                                    <input type="text" id="secondary_cta_bg_color_text" placeholder="#ffffff" 
                                           value="{{ old('secondary_cta_bg_color', $section->secondary_cta_bg_color) ?: '#ffffff' }}"
                                           class="flex-1 px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-wine-500 text-sm">
                                </div>
                                @error('secondary_cta_bg_color')
                                    <p class="text-error text-sm mt-1">{{ $message }}</p>
                                @enderror
                            </div>
                            <div>
                                <label for="secondary_cta_text_color" class="block text-sm font-medium text-gray-700 mb-2">Secondary CTA Text</label>
                                <div class="flex items-center space-x-3">
                                    <input type="color" id="secondary_cta_text_color" name="secondary_cta_text_color" 
                                           value="{{ old('secondary_cta_text_color', $section->secondary_cta_text_color) ?: \App\Support\Brand::INK }}"
                                           class="w-16 h-10 border border-gray-300 rounded-md cursor-pointer">
                                    <input type="text" id="secondary_cta_text_color_text" placeholder="{{ \App\Support\Brand::INK }}" 
                                           value="{{ old('secondary_cta_text_color', $section->secondary_cta_text_color) ?: \App\Support\Brand::INK }}"
                                           class="flex-1 px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-wine-500 text-sm">
                                </div>
                                @error('secondary_cta_text_color')
                                    <p class="text-error text-sm mt-1">{{ $message }}</p>
                                @enderror
                            </div>
                        </div>
                    </div>

                    <div class="pt-4">
                        <button type="submit" class="w-full bg-wine-500 text-white px-4 py-2 rounded-md hover:bg-wine-700 focus:outline-none focus:ring-2 focus:ring-wine-500">
                            Update Section
                        </button>
                    </div>
                </div>
            </form>
            
            <script>
                document.addEventListener('DOMContentLoaded', function() {
                    // Function to sync color picker and text input
                    function syncColorInputs(colorPickerId, textInputId) {
                        const colorPicker = document.getElementById(colorPickerId);
                        const colorText = document.getElementById(textInputId);
                        
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
                    }
                    
                    // Sync all color inputs
                    syncColorInputs('title_color', 'title_color_text');
                    syncColorInputs('subtitle_color', 'subtitle_color_text');
                    syncColorInputs('primary_cta_bg_color', 'primary_cta_bg_color_text');
                    syncColorInputs('primary_cta_text_color', 'primary_cta_text_color_text');
                    syncColorInputs('secondary_cta_bg_color', 'secondary_cta_bg_color_text');
                    syncColorInputs('secondary_cta_text_color', 'secondary_cta_text_color_text');
                });
            </script>
        </div>

        <!-- Features Management -->
        <div class="bg-white rounded-lg shadow-md p-6">
            <h2 class="text-xl font-semibold text-gray-900 mb-4">Features</h2>
            
            <!-- Add Feature Form -->
            <div class="border-b border-gray-200 pb-4 mb-4">
                <h3 class="text-lg font-medium text-gray-900 mb-3">Add New Feature</h3>
                
                <form action="{{ route('admin.why-sections.features.store', $section) }}" method="POST" enctype="multipart/form-data">
                    @csrf
                    <input type="hidden" name="why_section_id" value="{{ $section->id }}">
                    @error('why_section_id')
                        <div class="bg-error/10 border border-error/40 text-error-dark px-4 py-3 rounded mb-4">
                            <p class="text-sm">{{ $message }}</p>
                        </div>
                    @enderror
                    
                    @if($errors->any())
                        <div class="bg-error/10 border border-error/40 text-error-dark px-4 py-3 rounded mb-4">
                            <ul class="list-disc list-inside">
                                @foreach($errors->all() as $error)
                                    <li>{{ $error }}</li>
                                @endforeach
                            </ul>
                        </div>
                    @endif
                    
                    <div class="space-y-3">
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                            <div>
                                <label for="feature_title" class="block text-sm font-medium text-gray-700 mb-1">Title *</label>
                                <input type="text" id="feature_title" name="title" required
                                       class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-wine-500 text-sm">
                                @error('title')
                                    <p class="text-error text-sm mt-1">{{ $message }}</p>
                                @enderror
                            </div>
                            <div>
                                <label for="feature_icon" class="block text-sm font-medium text-gray-700 mb-1">Icon</label>
                                <input type="text" id="feature_icon" name="icon" placeholder="e.g., star, heart, shield"
                                       class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-wine-500 text-sm">
                                @error('icon')
                                    <p class="text-error text-sm mt-1">{{ $message }}</p>
                                @enderror
                            </div>
                        </div>
                        
                        <div>
                            <label for="feature_text" class="block text-sm font-medium text-gray-700 mb-1">Text</label>
                            <textarea id="feature_text" name="text" rows="2"
                                      class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-wine-500 text-sm"></textarea>
                            @error('text')
                                <p class="text-error text-sm mt-1">{{ $message }}</p>
                            @enderror
                        </div>
                        
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                            <div>
                                <label for="feature_link_url" class="block text-sm font-medium text-gray-700 mb-1">Link URL (Optional)</label>
                                <input type="url" id="feature_link_url" name="link_url" placeholder="https://example.com"
                                       class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-wine-500 text-sm">
                                @error('link_url')
                                    <p class="text-error text-sm mt-1">{{ $message }}</p>
                                @enderror
                            </div>
                            <div>
                                <label for="feature_link_text" class="block text-sm font-medium text-gray-700 mb-1">Link Text (Optional)</label>
                                <input type="text" id="feature_link_text" name="link_text" placeholder="e.g., Learn More, Book Now, View Details"
                                       class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-wine-500 text-sm">
                                @error('link_text')
                                    <p class="text-error text-sm mt-1">{{ $message }}</p>
                                @enderror
                            </div>
                        </div>
                        
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                            <div>
                                <label for="feature_image" class="block text-sm font-medium text-gray-700 mb-1">Image</label>
                                <input type="file" id="feature_image" name="image" accept="image/*"
                                       class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-wine-500 text-sm">
                                @error('image')
                                    <p class="text-error text-sm mt-1">{{ $message }}</p>
                                @enderror
                            </div>
                            <div>
                                <label for="feature_sort_order" class="block text-sm font-medium text-gray-700 mb-1">Sort Order</label>
                                <input type="number" id="feature_sort_order" name="sort_order" value="{{ $section->features->count() }}" min="0"
                                       class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-wine-500 text-sm">
                                @error('sort_order')
                                    <p class="text-sm mt-1 text-error">{{ $message }}</p>
                                @enderror
                            </div>
                        </div>
                        
                        <div>
                            <label for="feature_background_color" class="block text-sm font-medium text-gray-700 mb-1">Background Color (Optional)</label>
                            <div class="flex items-center space-x-3">
                                <input type="color" id="feature_background_color" name="background_color" value="#ffffff"
                                       class="w-16 h-10 border border-gray-300 rounded-md cursor-pointer">
                                <input type="text" id="feature_background_color_text" placeholder="#ffffff" 
                                       class="flex-1 px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-wine-500 text-sm"
                                       pattern="^#[0-9A-Fa-f]{6}$">
                                <span class="text-xs text-gray-500">or type hex code</span>
                            </div>
                            @error('background_color')
                                <p class="text-error text-sm mt-1">{{ $message }}</p>
                            @enderror
                        </div>
                        
                        <div class="flex items-center">
                            <input type="checkbox" id="feature_is_active" name="is_active" value="1" checked
                                   class="h-4 w-4 text-wine-500 focus:ring-wine-500 border-gray-300 rounded">
                            <label for="feature_is_active" class="ml-2 block text-sm text-gray-900">Active</label>
                            @error('is_active')
                                <p class="text-error text-sm mt-1">{{ $message }}</p>
                            @enderror
                        </div>
                        
                        <button type="submit" class="w-full bg-success text-white px-6 py-3 rounded-lg hover:bg-success-dark focus:outline-none focus:ring-2 focus:ring-success text-base font-semibold shadow-md" style="background-color: #059669 !important; color: #ffffff !important;">
                            Add New Feature
                        </button>
                        

                    </div>
                </form>
                
                <script>
                    document.addEventListener('DOMContentLoaded', function() {
                        // Synchronize color picker and text input
                        const colorPicker = document.getElementById('feature_background_color');
                        const colorText = document.getElementById('feature_background_color_text');
                        
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

            <!-- Features List -->
            <div class="space-y-3">
                @forelse($section->features as $feature)
                    <div class="border border-gray-200 rounded-lg p-3">
                        <div class="flex items-start justify-between">
                            <div class="flex items-start space-x-3 flex-1">
                                @if($feature->image_path)
                                    <img src="{{ Storage::url($feature->image_path) }}" alt="{{ $feature->title }}" class="w-12 h-12 object-cover rounded"
         loading="lazy"
         decoding="async">
                                @elseif($feature->icon)
                                    <div class="w-12 h-12 bg-gray-100 rounded flex items-center justify-center">
                                        <span class="text-gray-500 text-lg">{{ $feature->icon }}</span>
                                    </div>
                                @else
                                    <div class="w-12 h-12 bg-gray-100 rounded flex items-center justify-center">
                                        <span class="text-gray-400 text-lg">📋</span>
                                    </div>
                                @endif
                                
                                <div class="flex-1 min-w-0">
                                    <h4 class="text-sm font-medium text-gray-900 truncate">{{ $feature->title }}</h4>
                                    @if($feature->text)
                                        <p class="text-sm text-gray-600 mt-1 line-clamp-2">{{ Str::limit($feature->text, 80) }}</p>
                                    @endif
                                    <div class="flex items-center space-x-2 mt-1">
                                        <span class="text-xs text-gray-500">Order: {{ $feature->sort_order }}</span>
                                        <span class="text-xs px-2 py-1 rounded {{ $feature->is_active ? 'bg-success/10 text-success-dark' : 'bg-error/10 text-error-dark' }}">
                                            {{ $feature->is_active ? 'Active' : 'Inactive' }}
                                        </span>
                                        @if($feature->link_url)
                                            <span class="text-xs px-2 py-1 rounded bg-wine-50 text-wine-600">
                                                🔗 {{ $feature->link_text ?: 'Linked' }}
                                            </span>
                                        @endif
                                        @if($feature->background_color)
                                            <div class="flex items-center space-x-1">
                                                <span class="text-xs text-gray-500">BG:</span>
                                                <div class="w-4 h-4 rounded border border-gray-300" 
                                                     style="background-color: {{ $feature->background_color }};"></div>
                                            </div>
                                        @endif
                                    </div>
                                </div>
                            </div>
                            
                            <div class="flex items-center space-x-2">
                                <a href="{{ route('admin.features.edit', $feature) }}" 
                                   class="text-wine-500 hover:text-wine-600 text-sm font-medium">Edit</a>
                                
                                <form action="{{ route('admin.features.destroy', $feature) }}" method="POST" class="inline" 
                                      data-confirm="Are you sure you want to delete this feature?">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" class="text-error hover:text-error-dark text-sm font-medium">Delete</button>
                                </form>
                            </div>
                        </div>
                    </div>
                @empty
                    <div class="text-center py-8 text-gray-500">
                        <p>No features added yet.</p>
                        <p class="text-sm">Add your first feature using the form above.</p>
                    </div>
                @endforelse
            </div>
        </div>
    </div>
</div>
@endsection
