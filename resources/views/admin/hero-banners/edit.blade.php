@extends('layouts.app')

@section('title', 'Edit Hero Banner')

@section('content')
<div class="container mx-auto px-4 py-8">
    <div class="max-w-4xl mx-auto">
        <div class="flex items-center justify-between mb-8">
            <h1 class="text-3xl font-bold text-gray-800">Edit Hero Banner</h1>
            <a href="{{ route('admin.hero-banners.index') }}" 
               class="text-wine-500 hover:text-wine-600 font-medium">
                ← Back to Banners
            </a>
        </div>

        <div class="bg-white rounded-lg shadow-sm border p-6">
            <form method="POST" action="{{ route('admin.hero-banners.update', $heroBanner) }}" enctype="multipart/form-data" class="space-y-6">
                @csrf
                @method('PUT')

                <!-- Basic Information -->
                <div class="border-b border-gray-200 pb-6">
                    <h3 class="text-lg font-medium text-gray-900 mb-4">Basic Information</h3>
                    
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                        <!-- Language -->
                        <div>
                            <label for="locale" class="block text-sm font-medium text-gray-700 mb-2">Language *</label>
                            <select id="locale" name="locale" required 
                                    class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:outline-none focus:ring-2 focus:ring-wine-500 focus:border-transparent">
                                <option value="">Select Language</option>
                                <option value="en" {{ old('locale', $heroBanner->locale) == 'en' ? 'selected' : '' }}>English</option>
                                <option value="dv" {{ old('locale', $heroBanner->locale) == 'dv' ? 'selected' : '' }}>ދިވެހިބަހުން</option>
                            </select>
                            @error('locale')
                                <p class="mt-1 text-sm text-error">{{ $message }}</p>
                            @enderror
                        </div>

                        <!-- Sort Order -->
                        <div>
                            <label for="sort_order" class="block text-sm font-medium text-gray-700 mb-2">Sort Order</label>
                            <input type="number" id="sort_order" name="sort_order" 
                                   value="{{ old('sort_order', $heroBanner->sort_order) }}" min="0"
                                   class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:outline-none focus:ring-2 focus:ring-wine-500 focus:border-transparent"
                                   placeholder="0">
                            <p class="mt-1 text-sm text-gray-500">Lower numbers appear first</p>
                            @error('sort_order')
                                <p class="mt-1 text-sm text-error">{{ $message }}</p>
                            @enderror
                        </div>
                    </div>
                </div>

                <!-- Banner Content -->
                <div class="border-b border-gray-200 pb-6">
                    <h3 class="text-lg font-medium text-gray-900 mb-4">Banner Content</h3>
                    
                    <div class="space-y-6">
                        <!-- Title -->
                        <div>
                            <label for="title" class="block text-sm font-medium text-gray-700 mb-2">Title *</label>
                            <input type="text" id="title" name="title" 
                                   value="{{ old('title', $heroBanner->title) }}" maxlength="120" required
                                   class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:outline-none focus:ring-2 focus:ring-wine-500 focus:border-transparent"
                                   placeholder="Enter banner title">
                            <p class="mt-1 text-sm text-gray-500">Maximum 120 characters</p>
                            @error('title')
                                <p class="mt-1 text-sm text-error">{{ $message }}</p>
                            @enderror
                        </div>

                        <!-- Subtitle -->
                        <div>
                            <label for="subtitle" class="block text-sm font-medium text-gray-700 mb-2">Subtitle</label>
                            <textarea id="subtitle" name="subtitle" rows="3" maxlength="200"
                                      class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:outline-none focus:ring-2 focus:ring-wine-500 focus:border-transparent"
                                      placeholder="Enter banner subtitle (optional)">{{ old('subtitle', $heroBanner->subtitle) }}</textarea>
                            <p class="mt-1 text-sm text-gray-500">Maximum 200 characters</p>
                            @error('subtitle')
                                <p class="mt-1 text-sm text-error">{{ $message }}</p>
                            @enderror
                        </div>
                    </div>
                </div>

                <!-- Call to Action -->
                <div class="border-b border-gray-200 pb-6">
                    <h3 class="text-lg font-medium text-gray-900 mb-4">Call to Action</h3>
                    
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                        <!-- Primary CTA -->
                        <div>
                            <label for="primary_cta_text" class="block text-sm font-medium text-gray-700 mb-2">Primary CTA Text</label>
                            <input type="text" id="primary_cta_text" name="primary_cta_text" 
                                   value="{{ old('primary_cta_text', $heroBanner->primary_cta_text) }}" maxlength="60"
                                   class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:outline-none focus:ring-2 focus:ring-wine-500 focus:border-transparent"
                                   placeholder="e.g., Explore Trips">
                            <p class="mt-1 text-sm text-gray-500">Maximum 60 characters</p>
                            @error('primary_cta_text')
                                <p class="mt-1 text-sm text-error">{{ $message }}</p>
                            @enderror
                        </div>

                        <div>
                            <label for="primary_cta_url" class="block text-sm font-medium text-gray-700 mb-2">Primary CTA URL</label>
                            <input type="text" id="primary_cta_url" name="primary_cta_url" 
                                   value="{{ old('primary_cta_url', $heroBanner->primary_cta_url) }}"
                                   class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:outline-none focus:ring-2 focus:ring-wine-500 focus:border-transparent"
                                   placeholder="e.g., /trips or https://example.com">
                            <p class="mt-1 text-sm text-gray-500">Internal path or full URL</p>
                            @error('primary_cta_url')
                                <p class="mt-1 text-sm text-error">{{ $message }}</p>
                            @enderror
                        </div>

                        <!-- Secondary CTA -->
                        <div>
                            <label for="secondary_cta_text" class="block text-sm font-medium text-gray-700 mb-2">Secondary CTA Text</label>
                            <input type="text" id="secondary_cta_text" name="secondary_cta_text" 
                                   value="{{ old('secondary_cta_text', $heroBanner->secondary_cta_text) }}" maxlength="60"
                                   class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:outline-none focus:ring-2 focus:ring-wine-500 focus:border-transparent"
                                   placeholder="e.g., Contact Us">
                            <p class="mt-1 text-sm text-gray-500">Maximum 60 characters</p>
                            @error('secondary_cta_text')
                                <p class="mt-1 text-sm text-error">{{ $message }}</p>
                            @enderror
                        </div>

                        <div>
                            <label for="secondary_cta_url" class="block text-sm font-medium text-gray-700 mb-2">Secondary CTA URL</label>
                            <input type="text" id="secondary_cta_url" name="secondary_cta_url" 
                                   value="{{ old('secondary_cta_url', $heroBanner->secondary_cta_url) }}"
                                   class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:outline-none focus:ring-2 focus:ring-wine-500 focus:border-transparent"
                                   placeholder="e.g., /contact or https://example.com">
                            <p class="mt-1 text-sm text-gray-500">Internal path or full URL</p>
                            @error('secondary_cta_url')
                                <p class="mt-1 text-sm text-error">{{ $message }}</p>
                            @enderror
                        </div>
                    </div>
                </div>

                <!-- Banner Image -->
                <div class="border-b border-gray-200 pb-6">
                    <h3 class="text-lg font-medium text-gray-900 mb-4">Banner Image</h3>
                    
                    @if($heroBanner->image_path)
                        <div class="mb-4">
                            <label class="block text-sm font-medium text-gray-700 mb-2">Current Image</label>
                            <img src="{{ $heroBanner->image_url }}" 
                                 alt="{{ $heroBanner->title }}"
                                 class="w-64 h-36 object-cover rounded-lg border"
         loading="lazy"
         decoding="async">
                        </div>
                    @endif
                    
                    <div>
                        <label for="image" class="block text-sm font-medium text-gray-700 mb-2">New Image File</label>
                        <input type="file" id="image" name="image" accept="image/jpeg,image/png,image/webp"
                               class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:outline-none focus:ring-2 focus:ring-wine-500 focus:border-transparent">
                        <p class="mt-1 text-sm text-gray-500">Leave empty to keep current image. Supported formats: JPEG, PNG, WebP. Max size: 8MB.</p>
                        @error('image')
                            <p class="mt-1 text-sm text-error">{{ $message }}</p>
                        @enderror
                    </div>

                    <!-- Overlay Opacity -->
                    <div class="mb-4">
                        <label for="overlay_opacity" class="block text-sm font-medium text-gray-700 mb-2">
                            {{ __('Overlay Opacity') }} (%)
                        </label>
                        <input type="range" 
                               id="overlay_opacity" 
                               name="overlay_opacity" 
                               min="0" 
                               max="100" 
                               value="{{ old('overlay_opacity', $heroBanner->overlay_opacity) }}"
                               class="w-full h-2 bg-gray-200 rounded-lg appearance-none cursor-pointer">
                        <div class="flex justify-between text-xs text-gray-500 mt-1">
                            <span>0%</span>
                            <span id="opacity-value">{{ old('overlay_opacity', $heroBanner->overlay_opacity) }}%</span>
                            <span>100%</span>
                        </div>
                    </div>

                    <!-- Custom Styling Section -->
                    <div class="border-t pt-6 mt-6">
                        <h3 class="text-lg font-medium text-gray-900 mb-4">{{ __('Custom Styling') }}</h3>
                        
                        <!-- Heading Styling -->
                        <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-4">
                            <div>
                                <label for="heading_color" class="block text-sm font-medium text-gray-700 mb-2">
                                    {{ __('Heading Color') }}
                                </label>
                                <input type="color" 
                                       id="heading_color" 
                                       name="heading_color" 
                                       value="{{ old('heading_color', $heroBanner->heading_color) }}"
                                       class="w-full h-10 border border-gray-300 rounded-md">
                            </div>
                            <div>
                                <label for="heading_size" class="block text-sm font-medium text-gray-700 mb-2">
                                    {{ __('Heading Size') }}
                                </label>
                                <select id="heading_size" name="heading_size" class="w-full border border-gray-300 rounded-md px-3 py-2">
                                    <option value="text-sm" {{ old('heading_size', $heroBanner->heading_size) == 'text-sm' ? 'selected' : '' }}>Small</option>
                                    <option value="text-base" {{ old('heading_size', $heroBanner->heading_size) == 'text-base' ? 'selected' : '' }}>Base</option>
                                    <option value="text-lg" {{ old('heading_size', $heroBanner->heading_size) == 'text-lg' ? 'selected' : '' }}>Large</option>
                                    <option value="text-xl" {{ old('heading_size', $heroBanner->heading_size) == 'text-xl' ? 'selected' : '' }}>XL</option>
                                    <option value="text-2xl" {{ old('heading_size', $heroBanner->heading_size) == 'text-2xl' ? 'selected' : '' }}>2XL</option>
                                    <option value="text-3xl" {{ old('heading_size', $heroBanner->heading_size) == 'text-3xl' ? 'selected' : '' }}>3XL</option>
                                    <option value="text-4xl" {{ old('heading_size', $heroBanner->heading_size) == 'text-4xl' ? 'selected' : '' }}>4XL</option>
                                </select>
                            </div>
                            <div>
                                <label for="heading_weight" class="block text-sm font-medium text-gray-700 mb-2">
                                    {{ __('Heading Weight') }}
                                </label>
                                <select id="heading_weight" name="heading_weight" class="w-full border border-gray-300 rounded-md px-3 py-2">
                                    <option value="font-normal" {{ old('heading_weight', $heroBanner->heading_weight) == 'font-normal' ? 'selected' : '' }}>Normal</option>
                                    <option value="font-medium" {{ old('heading_weight', $heroBanner->heading_weight) == 'font-medium' ? 'selected' : '' }}>Medium</option>
                                    <option value="font-semibold" {{ old('heading_weight', $heroBanner->heading_weight) == 'font-semibold' ? 'selected' : '' }}>Semi-bold</option>
                                    <option value="font-bold" {{ old('heading_weight', $heroBanner->heading_weight) == 'font-bold' ? 'selected' : '' }}>Bold</option>
                                    <option value="font-extrabold" {{ old('heading_weight', $heroBanner->heading_weight) == 'font-extrabold' ? 'selected' : '' }}>Extra-bold</option>
                                </select>
                            </div>
                        </div>

                        <!-- Subheading Styling -->
                        <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-4">
                            <div>
                                <label for="subheading_color" class="block text-sm font-medium text-gray-700 mb-2">
                                    {{ __('Subheading Color') }}
                                </label>
                                <input type="color" 
                                       id="subheading_color" 
                                       name="subheading_color" 
                                       value="{{ old('subheading_color', $heroBanner->subheading_color) }}"
                                       class="w-full h-10 border border-gray-300 rounded-md">
                            </div>
                            <div>
                                <label for="subheading_size" class="block text-sm font-medium text-gray-700 mb-2">
                                    {{ __('Subheading Size') }}
                                </label>
                                <select id="subheading_size" name="subheading_size" class="w-full border border-gray-300 rounded-md px-3 py-2">
                                    <option value="text-xs" {{ old('subheading_size', $heroBanner->subheading_size) == 'text-xs' ? 'selected' : '' }}>Extra Small</option>
                                    <option value="text-sm" {{ old('subheading_size', $heroBanner->subheading_size) == 'text-sm' ? 'selected' : '' }}>Small</option>
                                    <option value="text-base" {{ old('subheading_size', $heroBanner->subheading_size) == 'text-base' ? 'selected' : '' }}>Base</option>
                                    <option value="text-lg" {{ old('heading_size', $heroBanner->subheading_size) == 'text-lg' ? 'selected' : '' }}>Large</option>
                                    <option value="text-xl" {{ old('subheading_size', $heroBanner->subheading_size) == 'text-xl' ? 'selected' : '' }}>XL</option>
                                </select>
                            </div>
                            <div>
                                <label for="subheading_weight" class="block text-sm font-medium text-gray-700 mb-2">
                                    {{ __('Subheading Weight') }}
                                </label>
                                <select id="subheading_weight" name="subheading_weight" class="w-full border border-gray-300 rounded-md px-3 py-2">
                                    <option value="font-light" {{ old('subheading_weight', $heroBanner->subheading_weight) == 'font-light' ? 'selected' : '' }}>Light</option>
                                    <option value="font-normal" {{ old('subheading_weight', $heroBanner->subheading_weight) == 'font-normal' ? 'selected' : '' }}>Normal</option>
                                    <option value="font-medium" {{ old('subheading_weight', $heroBanner->subheading_weight) == 'font-medium' ? 'selected' : '' }}>Medium</option>
                                    <option value="font-semibold" {{ old('subheading_weight', $heroBanner->subheading_weight) == 'font-semibold' ? 'selected' : '' }}>Semi-bold</option>
                                </select>
                            </div>
                        </div>

                        <!-- Primary CTA Styling -->
                        <div class="grid grid-cols-1 md:grid-cols-4 gap-4 mb-4">
                            <div>
                                <label for="primary_cta_bg_color" class="block text-sm font-medium text-gray-700 mb-2">
                                    {{ __('Primary CTA Background') }}
                                </label>
                                <input type="color" 
                                       id="primary_cta_bg_color" 
                                       name="primary_cta_bg_color" 
                                       value="{{ old('primary_cta_bg_color', $heroBanner->primary_cta_bg_color) }}"
                                       class="w-full h-10 border border-gray-300 rounded-md">
                            </div>
                            <div>
                                <label for="primary_cta_text_color" class="block text-sm font-medium text-gray-700 mb-2">
                                    {{ __('Primary CTA Text Color') }}
                                </label>
                                <input type="color" 
                                       id="primary_cta_text_color" 
                                       name="primary_cta_text_color" 
                                       value="{{ old('primary_cta_text_color', $heroBanner->primary_cta_text_color) }}"
                                       class="w-full h-10 border border-gray-300 rounded-md">
                            </div>
                            <div>
                                <label for="primary_cta_size" class="block text-sm font-medium text-gray-700 mb-2">
                                    {{ __('Primary CTA Size') }}
                                </label>
                                <select id="primary_cta_size" name="primary_cta_size" class="w-full border border-gray-300 rounded-md px-3 py-2">
                                    <option value="text-sm" {{ old('primary_cta_size', $heroBanner->primary_cta_size) == 'text-sm' ? 'selected' : '' }}>Small</option>
                                    <option value="text-base" {{ old('primary_cta_size', $heroBanner->primary_cta_size) == 'text-base' ? 'selected' : '' }}>Base</option>
                                    <option value="text-lg" {{ old('primary_cta_size', $heroBanner->primary_cta_size) == 'text-lg' ? 'selected' : '' }}>Large</option>
                                    <option value="text-xl" {{ old('primary_cta_size', $heroBanner->primary_cta_size) == 'text-xl' ? 'selected' : '' }}>XL</option>
                                </select>
                            </div>
                            <div>
                                <label for="primary_cta_radius" class="block text-sm font-medium text-gray-700 mb-2">
                                    {{ __('Primary CTA Radius') }}
                                </label>
                                <select id="primary_cta_radius" name="primary_cta_radius" class="w-full border border-gray-300 rounded-md px-3 py-2">
                                    <option value="rounded-none" {{ old('primary_cta_radius', $heroBanner->primary_cta_radius) == 'rounded-none' ? 'selected' : '' }}>None</option>
                                    <option value="rounded-sm" {{ old('primary_cta_radius', $heroBanner->primary_cta_radius) == 'rounded-sm' ? 'selected' : '' }}>Small</option>
                                    <option value="rounded" {{ old('primary_cta_radius', $heroBanner->primary_cta_radius) == 'rounded' ? 'selected' : '' }}>Base</option>
                                    <option value="rounded-md" {{ old('primary_cta_radius', $heroBanner->primary_cta_radius) == 'rounded-md' ? 'selected' : '' }}>Medium</option>
                                    <option value="rounded-lg" {{ old('primary_cta_radius', $heroBanner->primary_cta_radius) == 'rounded-lg' ? 'selected' : '' }}>Large</option>
                                    <option value="rounded-xl" {{ old('primary_cta_radius', $heroBanner->primary_cta_radius) == 'rounded-xl' ? 'selected' : '' }}>XL</option>
                                    <option value="rounded-full" {{ old('primary_cta_radius', $heroBanner->primary_cta_radius) == 'rounded-full' ? 'selected' : '' }}>Full</option>
                                </select>
                            </div>
                        </div>

                        <!-- Secondary CTA Styling -->
                        <div class="grid grid-cols-1 md:grid-cols-4 gap-4 mb-4">
                            <div>
                                <label for="secondary_cta_bg_color" class="block text-sm font-medium text-gray-700 mb-2">
                                    {{ __('Secondary CTA Background') }}
                                </label>
                                <input type="color" 
                                       id="secondary_cta_bg_color" 
                                       name="secondary_cta_bg_color" 
                                       value="{{ old('secondary_cta_bg_color', $heroBanner->secondary_cta_bg_color) }}"
                                       class="w-full h-10 border border-gray-300 rounded-md">
                            </div>
                            <div>
                                <label for="secondary_cta_text_color" class="block text-sm font-medium text-gray-700 mb-2">
                                    {{ __('Secondary CTA Text Color') }}
                                </label>
                                <input type="color" 
                                       id="secondary_cta_text_color" 
                                       name="secondary_cta_text_color" 
                                       value="{{ old('secondary_cta_text_color', $heroBanner->secondary_cta_text_color) }}"
                                       class="w-full h-10 border border-gray-300 rounded-md">
                                    </div>
                            <div>
                                <label for="secondary_cta_size" class="block text-sm font-medium text-gray-700 mb-2">
                                    {{ __('Secondary CTA Size') }}
                                </label>
                                <select id="secondary_cta_size" name="secondary_cta_size" class="w-full border border-gray-300 rounded-md px-3 py-2">
                                    <option value="text-sm" {{ old('secondary_cta_size', $heroBanner->secondary_cta_size) == 'text-sm' ? 'selected' : '' }}>Small</option>
                                    <option value="text-base" {{ old('secondary_cta_size', $heroBanner->secondary_cta_size) == 'text-base' ? 'selected' : '' }}>Base</option>
                                    <option value="text-lg" {{ old('secondary_cta_size', $heroBanner->secondary_cta_size) == 'text-lg' ? 'selected' : '' }}>Large</option>
                                    <option value="text-xl" {{ old('secondary_cta_size', $heroBanner->secondary_cta_size) == 'text-xl' ? 'selected' : '' }}>XL</option>
                                </select>
                            </div>
                            <div>
                                <label for="secondary_cta_radius" class="block text-sm font-medium text-gray-700 mb-2">
                                    {{ __('Secondary CTA Radius') }}
                                </label>
                                <select id="secondary_cta_radius" name="secondary_cta_radius" class="w-full border border-gray-300 rounded-md px-3 py-2">
                                    <option value="rounded-none" {{ old('secondary_cta_radius', $heroBanner->secondary_cta_radius) == 'rounded-none' ? 'selected' : '' }}>None</option>
                                    <option value="rounded-sm" {{ old('secondary_cta_radius', $heroBanner->secondary_cta_radius) == 'rounded-sm' ? 'selected' : '' }}>Small</option>
                                    <option value="rounded" {{ old('secondary_cta_radius', $heroBanner->secondary_cta_radius) == 'rounded' ? 'selected' : '' }}>Base</option>
                                    <option value="rounded-md" {{ old('secondary_cta_radius', $heroBanner->secondary_cta_radius) == 'rounded-md' ? 'selected' : '' }}>Medium</option>
                                    <option value="rounded-lg" {{ old('secondary_cta_radius', $heroBanner->secondary_cta_radius) == 'rounded-lg' ? 'selected' : '' }}>Large</option>
                                    <option value="rounded-xl" {{ old('secondary_cta_radius', $heroBanner->secondary_cta_radius) == 'rounded-xl' ? 'selected' : '' }}>XL</option>
                                    <option value="rounded-full" {{ old('secondary_cta_radius', $heroBanner->secondary_cta_radius) == 'rounded-full' ? 'selected' : '' }}>Full</option>
                                </select>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Schedule -->
                <div class="border-b border-gray-200 pb-6">
                    <h3 class="text-lg font-medium text-gray-900 mb-4">Schedule (Optional)</h3>
                    
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                        <div>
                            <label for="start_at" class="block text-sm font-medium text-gray-700 mb-2">Start Date</label>
                            <input type="datetime-local" id="start_at" name="start_at" 
                                   value="{{ old('start_at', $heroBanner->start_at ? $heroBanner->start_at->format('Y-m-d\TH:i') : '') }}"
                                   class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:outline-none focus:ring-2 focus:ring-wine-500 focus:border-transparent">
                            <p class="mt-1 text-sm text-gray-500">Leave empty to show immediately</p>
                            @error('start_at')
                                <p class="mt-1 text-sm text-error">{{ $message }}</p>
                            @enderror
                        </div>

                        <div>
                            <label for="end_at" class="block text-sm font-medium text-gray-700 mb-2">End Date</label>
                            <input type="datetime-local" id="end_at" name="end_at" 
                                   value="{{ old('end_at', $heroBanner->end_at ? $heroBanner->end_at->format('Y-m-d\TH:i') : '') }}"
                                   class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:outline-none focus:ring-2 focus:ring-wine-500 focus:border-transparent">
                            <p class="mt-1 text-sm text-gray-500">Leave empty to show indefinitely</p>
                            @error('end_at')
                                <p class="mt-1 text-sm text-error">{{ $message }}</p>
                            @enderror
                        </div>
                    </div>
                </div>

                <!-- Settings -->
                <div class="pb-6">
                    <h3 class="text-lg font-medium text-gray-900 mb-4">Settings</h3>
                    
                    <div class="flex items-center">
                        <input type="checkbox" id="is_active" name="is_active" 
                               {{ old('is_active', $heroBanner->is_active) ? 'checked' : '' }}
                               class="h-4 w-4 text-wine-500 focus:ring-wine-500 border-gray-300 rounded">
                        <label for="is_active" class="ml-2 block text-sm text-gray-900">
                            Publish immediately
                        </label>
                    </div>
                </div>

                <!-- Submit Buttons -->
                <div class="flex justify-end space-x-4 pt-6 border-t border-gray-200">
                    <a href="{{ route('admin.hero-banners.index') }}" 
                       class="px-6 py-2 border border-gray-300 rounded-lg text-gray-700 hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-wine-500">
                        Cancel
                    </a>
                    <button type="submit" 
                            class="px-6 py-2 bg-wine-500 text-white rounded-lg hover:bg-wine-600 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-wine-500">
                        Update Banner
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const opacitySlider = document.getElementById('overlay_opacity');
    const opacityValue = document.getElementById('opacity-value');
    
    if (opacitySlider && opacityValue) {
        opacitySlider.addEventListener('input', function() {
            opacityValue.textContent = this.value + '%';
        });
    }
});
</script>
@endsection
