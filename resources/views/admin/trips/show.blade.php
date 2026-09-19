@extends('layouts.app')

@section('content')
<div class="container mx-auto px-4 py-8">
    <div class="max-w-4xl mx-auto">
        <div class="flex items-center justify-between mb-8">
            <h1 class="text-3xl font-bold text-gray-800">{{ __('Trip Details') }}</h1>
            <div class="flex space-x-4">
                <a href="{{ route('admin.trips.edit', $trip) }}" class="btn-primary">
                    {{ __('Edit Trip') }}
                </a>
                <a href="{{ route('admin.trips.index') }}" class="text-wine-500 hover:text-wine-600">
                    {{ __('← Back to Trips') }}
                </a>
            </div>
        </div>

        <div class="card">
            <div class="grid grid-cols-1 md:grid-cols-3 gap-8">
                <!-- Trip Image -->
                <div class="md:col-span-1">
                    @if($trip->cover_image)
                        <img src="{{ Storage::url($trip->cover_image) }}" alt="{{ $trip->title }}" 
                             class="w-full h-64 object-cover rounded-lg shadow-md"
         loading="lazy"
         decoding="async">
                    @else
                        <div class="w-full h-64 bg-gray-200 rounded-lg flex items-center justify-center">
                            <span class="text-gray-500">{{ __('No Image') }}</span>
                        </div>
                    @endif
                </div>

                <!-- Trip Info -->
                <div class="md:col-span-2">
                    <div class="space-y-6">
                        <!-- Title and Status -->
                        <div>
                            <h2 class="text-2xl font-bold text-gray-900 mb-2">{{ $trip->title }}</h2>
                            @if($trip->title_dv)
                                <h3 class="text-lg font-medium text-gray-700 mb-2">{{ $trip->title_dv }}</h3>
                            @endif
                            <div class="flex items-center space-x-4">
                                <span class="px-3 py-1 text-sm font-semibold rounded-full 
                                    @if($trip->status === 'current') bg-success/10 text-success-dark
                                    @elseif($trip->status === 'upcoming') bg-warning/10 text-warning-dark
                                    @else bg-gray-100 text-gray-800
                                    @endif">
                                    {{ ucfirst($trip->status) }}
                                </span>
                                <span class="px-3 py-1 text-sm font-semibold rounded-full 
                                    @if($trip->is_published) bg-wine-50 text-wine-600
                                    @else bg-error/10 text-error-dark
                                    @endif">
                                    {{ $trip->is_published ? __('Published') : __('Draft') }}
                                </span>
                                <span class="px-3 py-1 text-sm font-semibold rounded-full bg-wine-50 text-wine-600">
                                    {{ strtoupper($trip->locale) }}
                                </span>
                            </div>
                        </div>

                        <!-- Location and Dates -->
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                            <div>
                                <h3 class="text-sm font-medium text-gray-500 mb-1">{{ __('Location') }}</h3>
                                <p class="text-gray-900">{{ $trip->location ?: __('Not specified') }}</p>
                            </div>
                            <div>
                                <h3 class="text-sm font-medium text-gray-500 mb-1">{{ __('Price From') }}</h3>
                                <p class="text-gray-900">{{ $trip->price_from_mvr ? 'MVR ' . number_format($trip->price_from_mvr) : __('Not specified') }}</p>
                            </div>
                        </div>

                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                            <div>
                                <h3 class="text-sm font-medium text-gray-500 mb-1">{{ __('Start Date') }}</h3>
                                <p class="text-gray-900">{{ $trip->date_start->format('F d, Y') }}</p>
                            </div>
                            <div>
                                <h3 class="text-sm font-medium text-gray-500 mb-1">{{ __('End Date') }}</h3>
                                <p class="text-gray-900">{{ $trip->date_end->format('F d, Y') }}</p>
                            </div>
                        </div>

                        <!-- Summary -->
                        @if($trip->summary)
                        <div>
                            <h3 class="text-sm font-medium text-gray-500 mb-2">{{ __('Summary') }}</h3>
                            <p class="text-gray-900 leading-relaxed">{{ $trip->summary }}</p>
                        </div>
                        @endif

                        <!-- Details -->
                        @if($trip->details)
                        <div>
                            <h3 class="text-sm font-medium text-gray-500 mb-2">{{ __('Trip Details') }}</h3>
                            <div class="text-gray-900 leading-relaxed prose max-w-none">
                                {!! nl2br(e($trip->details)) !!}
                            </div>
                        </div>
                        @endif

                        <!-- Trip Media -->
                        @if($trip->media->count() > 0)
                        <div>
                            <h3 class="text-sm font-medium text-gray-500 mb-3">{{ __('Trip Media') }}</h3>
                            <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
                                @foreach($trip->media->take(8) as $media)
                                <div class="relative group">
                                    @if($media->type === 'photo')
                                        <img src="{{ $media->getThumbnailUrlAttribute() }}" 
                                             alt="{{ $media->title }}" 
                                             class="w-full h-24 object-cover rounded-lg"
         loading="lazy"
         decoding="async">
                                    @else
                                        <div class="w-full h-24 bg-gray-200 rounded-lg flex items-center justify-center">
                                            <svg aria-hidden="true" focusable="false" class="w-8 h-8 text-gray-500" fill="currentColor" viewBox="0 0 20 20">
                                                <path d="M2 6a2 2 0 012-2h6l2 2h6a2 2 0 012 2v6a2 2 0 01-2 2H4a2 2 0 01-2-2V6z"></path>
                                            </svg>
                                        </div>
                                    @endif
                                    <div class="absolute inset-0 bg-black bg-opacity-0 group-hover:bg-opacity-50 transition-all duration-200 rounded-lg flex items-center justify-center opacity-0 group-hover:opacity-100">
                                        <a href="{{ route('admin.media.edit', $media) }}" class="text-white text-sm font-medium">
                                            {{ __('Edit') }}
                                        </a>
                                    </div>
                                </div>
                                @endforeach
                            </div>
                            @if($trip->media->count() > 8)
                                <p class="text-sm text-gray-500 mt-2">{{ __('Showing first 8 items. Total: ') . $trip->media->count() }}</p>
                            @endif
                        </div>
                        @endif

                        <!-- Trip Statistics -->
                        <div class="border-t pt-6">
                            <h3 class="text-sm font-medium text-gray-500 mb-3">{{ __('Trip Statistics') }}</h3>
                            <div class="grid grid-cols-2 md:grid-cols-4 gap-4 text-center">
                                <div>
                                    <p class="text-2xl font-bold text-wine-500">{{ $trip->media->count() }}</p>
                                    <p class="text-sm text-gray-500">{{ __('Media Items') }}</p>
                                </div>
                                <div>
                                    <p class="text-2xl font-bold text-wine-500">{{ $trip->photos->count() }}</p>
                                    <p class="text-sm text-gray-500">{{ __('Photos') }}</p>
                                </div>
                                <div>
                                    <p class="text-2xl font-bold text-wine-500">{{ $trip->videos->count() }}</p>
                                    <p class="text-sm text-gray-500">{{ __('Videos') }}</p>
                                </div>
                                <div>
                                    <p class="text-2xl font-bold text-gray-600">{{ $trip->created_at->diffForHumans() }}</p>
                                    <p class="text-sm text-gray-500">{{ __('Created') }}</p>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Action Buttons -->
            <div class="mt-8 pt-6 border-t flex justify-between">
                <div class="flex space-x-4">
                    <a href="{{ route('admin.trips.index') }}" class="px-6 py-2 border border-gray-300 rounded-lg text-gray-700 hover:bg-gray-50 transition-colors">
                        {{ __('Back to Trips') }}
                    </a>
                    <a href="{{ route('admin.media.create', ['trip_id' => $trip->id]) }}" class="px-6 py-2 bg-wine-500 text-white rounded-lg hover:bg-wine-700 transition-colors">
                        {{ __('Add Media') }}
                    </a>
                </div>
                
                <form action="{{ route('admin.trips.destroy', $trip) }}" method="POST" class="inline" data-confirm="{{ __('Are you sure you want to delete this trip? This action cannot be undone.') }}">
                    @csrf
                    @method('DELETE')
                    <button type="submit" class="px-6 py-2 bg-error text-white rounded-lg hover:bg-error-dark transition-colors">
                        {{ __('Delete Trip') }}
                    </button>
                </form>
            </div>
        </div>
    </div>
</div>
@endsection
