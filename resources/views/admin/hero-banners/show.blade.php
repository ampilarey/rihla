@extends('layouts.app')

@section('title', 'Hero Banner Details')

@section('content')
<div class="container mx-auto px-4 py-8">
    <div class="max-w-4xl mx-auto">
        <div class="flex items-center justify-between mb-8">
            <h1 class="text-3xl font-bold text-gray-800">Hero Banner Details</h1>
            <div class="flex space-x-3">
                <a href="{{ route('admin.hero-banners.edit', $heroBanner) }}" 
                   class="btn-primary">
                    Edit Banner
                </a>
                <a href="{{ route('admin.hero-banners.index') }}" 
                   class="text-wine-500 hover:text-wine-600 font-medium">
                    ← Back to Banners
                </a>
            </div>
        </div>

        <div class="bg-white rounded-lg shadow-sm border overflow-hidden">
            <!-- Banner Image Preview -->
            @if($heroBanner->image_path)
                <div class="relative h-64 bg-gray-100">
                    <img src="{{ $heroBanner->image_url }}" 
                         alt="{{ $heroBanner->title }}"
                         class="w-full h-full object-cover">
                    <div class="absolute inset-0 bg-black/{{ $heroBanner->overlay_opacity/100 }}"></div>
                    <div class="absolute inset-0 flex items-center justify-center">
                        <div class="text-center text-white">
                            <h2 class="text-3xl font-bold mb-2">{{ $heroBanner->title }}</h2>
                            @if($heroBanner->subtitle)
                                <p class="text-xl">{{ $heroBanner->subtitle }}</p>
                            @endif
                        </div>
                    </div>
                </div>
            @endif

            <!-- Banner Information -->
            <div class="p-6">
                <div class="grid grid-cols-1 md:grid-cols-2 gap-8">
                    <!-- Left Column -->
                    <div class="space-y-6">
                        <!-- Basic Info -->
                        <div>
                            <h3 class="text-lg font-medium text-gray-900 mb-4">Basic Information</h3>
                            <dl class="space-y-3">
                                <div>
                                    <dt class="text-sm font-medium text-gray-500">Language</dt>
                                    <dd class="mt-1 text-sm text-gray-900">
                                        <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium {{ $heroBanner->locale === 'en' ? 'bg-wine-50 text-wine-600' : 'bg-success/10 text-success-dark' }}">
                                            {{ strtoupper($heroBanner->locale) }}
                                        </span>
                                    </dd>
                                </div>
                                <div>
                                    <dt class="text-sm font-medium text-gray-500">Title</dt>
                                    <dd class="mt-1 text-sm text-gray-900">{{ $heroBanner->title }}</dd>
                                </div>
                                <div>
                                    <dt class="text-sm font-medium text-gray-500">Subtitle</dt>
                                    <dd class="mt-1 text-sm text-gray-900">{{ $heroBanner->subtitle ?: 'No subtitle' }}</dd>
                                </div>
                                <div>
                                    <dt class="text-sm font-medium text-gray-500">Sort Order</dt>
                                    <dd class="mt-1 text-sm text-gray-900">{{ $heroBanner->sort_order }}</dd>
                                </div>
                            </dl>
                        </div>

                        <!-- Call to Action -->
                        <div>
                            <h3 class="text-lg font-medium text-gray-900 mb-4">Call to Action</h3>
                            <dl class="space-y-3">
                                @if($heroBanner->primary_cta_text && $heroBanner->primary_cta_url)
                                    <div>
                                        <dt class="text-sm font-medium text-gray-500">Primary CTA</dt>
                                        <dd class="mt-1 text-sm text-gray-900">
                                            <span class="text-wine-500">{{ $heroBanner->primary_cta_text }}</span> →
                                            <a href="{{ $heroBanner->primary_cta_url }}" 
                                               target="_blank"
                                               class="text-wine-500 hover:underline">
                                                {{ $heroBanner->primary_cta_url }}
                                            </a>
                                        </dd>
                                    </div>
                                @endif
                                @if($heroBanner->secondary_cta_text && $heroBanner->secondary_cta_url)
                                    <div>
                                        <dt class="text-sm font-medium text-gray-500">Secondary CTA</dt>
                                        <dd class="mt-1 text-sm text-gray-900">
                                            <span class="text-gold-700">{{ $heroBanner->secondary_cta_text }}</span> →
                                            <a href="{{ $heroBanner->secondary_cta_url }}" 
                                               target="_blank"
                                               class="text-gold-700 hover:underline">
                                                {{ $heroBanner->secondary_cta_url }}
                                            </a>
                                        </dd>
                                    </div>
                                @endif
                                @if(!$heroBanner->primary_cta_text && !$heroBanner->secondary_cta_text)
                                    <dd class="text-sm text-gray-500">No CTA configured</dd>
                                @endif
                            </dl>
                        </div>
                    </div>

                    <!-- Right Column -->
                    <div class="space-y-6">
                        <!-- Status & Schedule -->
                        <div>
                            <h3 class="text-lg font-medium text-gray-900 mb-4">Status & Schedule</h3>
                            <dl class="space-y-3">
                                <div>
                                    <dt class="text-sm font-medium text-gray-500">Status</dt>
                                    <dd class="mt-1">
                                        <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium {{ $heroBanner->is_active ? 'bg-success/10 text-success-dark' : 'bg-error/10 text-error-dark' }}">
                                            {{ $heroBanner->is_active ? 'Active' : 'Inactive' }}
                                        </span>
                                    </dd>
                                </div>
                                <div>
                                    <dt class="text-sm font-medium text-gray-500">Schedule</dt>
                                    <dd class="mt-1 text-sm text-gray-900">
                                        @if($heroBanner->start_at || $heroBanner->end_at)
                                            @if($heroBanner->start_at)
                                                <div>From: {{ $heroBanner->start_at->format('F j, Y \a\t g:i A') }}</div>
                                            @endif
                                            @if($heroBanner->end_at)
                                                <div>To: {{ $heroBanner->end_at->format('F j, Y \a\t g:i A') }}</div>
                                            @endif
                                        @else
                                            <span class="text-gray-500">Always visible</span>
                                        @endif
                                    </dd>
                                </div>
                            </dl>
                        </div>

                        <!-- Image & Overlay -->
                        <div>
                            <h3 class="text-lg font-medium text-gray-900 mb-4">Image & Overlay</h3>
                            <dl class="space-y-3">
                                <div>
                                    <dt class="text-sm font-medium text-gray-500">Image</dt>
                                    <dd class="mt-1 text-sm text-gray-900">
                                        @if($heroBanner->image_path)
                                            <span class="text-success">✓ Image uploaded</span>
                                        @else
                                            <span class="text-gray-500">No image</span>
                                        @endif
                                    </dd>
                                </div>
                                <div>
                                    <dt class="text-sm font-medium text-gray-500">Overlay Opacity</dt>
                                    <dd class="mt-1 text-sm text-gray-900">{{ $heroBanner->overlay_opacity }}%</dd>
                                </div>
                            </dl>
                        </div>

                        <!-- Timestamps -->
                        <div>
                            <h3 class="text-lg font-medium text-gray-900 mb-4">Timestamps</h3>
                            <dl class="space-y-3">
                                <div>
                                    <dt class="text-sm font-medium text-gray-500">Created</dt>
                                    <dd class="mt-1 text-sm text-gray-900">{{ $heroBanner->created_at->format('F j, Y \a\t g:i A') }}</dd>
                                </div>
                                <div>
                                    <dt class="text-sm font-medium text-gray-500">Last Updated</dt>
                                    <dd class="mt-1 text-sm text-gray-900">{{ $heroBanner->updated_at->format('F j, Y \a\t g:i A') }}</dd>
                                </div>
                            </dl>
                        </div>
                    </div>
                </div>

                <!-- Actions -->
                <div class="mt-8 pt-6 border-t border-gray-200">
                    <div class="flex justify-between items-center">
                        <div class="flex space-x-3">
                            <a href="{{ route('admin.hero-banners.edit', $heroBanner) }}" 
                               class="btn-primary">
                                Edit Banner
                            </a>
                            <form method="POST" action="{{ route('admin.hero-banners.toggle-status', $heroBanner) }}" class="inline">
                                @csrf
                                <button type="submit" 
                                        class="px-4 py-2 border border-gray-300 rounded-lg text-gray-700 hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-wine-500">
                                    {{ $heroBanner->is_active ? 'Deactivate' : 'Activate' }}
                                </button>
                            </form>
                        </div>
                        <form method="POST" action="{{ route('admin.hero-banners.destroy', $heroBanner) }}" class="inline">
                            @csrf
                            @method('DELETE')
                            <button type="submit" 
                                    class="px-4 py-2 bg-error text-white rounded-lg hover:bg-error-dark focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-error"
                                    onclick="return confirm('Are you sure you want to delete this banner?')">
                                Delete Banner
                            </button>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
@endsection
