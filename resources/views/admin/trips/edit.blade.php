@extends('layouts.app')

@section('content')
<div class="container mx-auto px-4 py-8">
    <div class="max-w-4xl mx-auto">
        <div class="flex items-center justify-between mb-8">
            <h1 class="text-3xl font-bold text-gray-800">{{ __('Edit Trip') }}</h1>
            <a href="{{ route('admin.trips.index') }}" class="text-brand-green hover:text-brand-green/80">
                {{ __('← Back to Trips') }}
            </a>
        </div>

        <div class="card">
            <form action="{{ route('admin.trips.update', $trip) }}" method="POST" enctype="multipart/form-data">
                @csrf
                @method('PUT')
                
                <!-- Locale Display -->
                <div class="mb-6">
                    <label class="block text-sm font-medium text-gray-700 mb-2">
                        {{ __('Language') }}
                    </label>
                    <div class="px-3 py-2 bg-gray-100 border border-gray-300 rounded-lg">
                        <span class="text-gray-900 font-medium">{{ $trip->locale === 'en' ? __('English') : __('Dhivehi') }}</span>
                    </div>
                </div>
                
                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                    <div>
                        <label for="title" class="block text-sm font-medium text-gray-700 mb-2">
                            {{ __('Trip Title') }} *
                        </label>
                        <input type="text" name="title" id="title" value="{{ old('title', $trip->title) }}" required
                               class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-brand-green focus:border-transparent">
                        @error('title')
                            <p class="text-red-500 text-sm mt-1">{{ $message }}</p>
                        @enderror
                    </div>

                    <div>
                        <label for="location" class="block text-sm font-medium text-gray-700 mb-2">
                            {{ __('Location') }}
                        </label>
                        <input type="text" name="location" id="location" value="{{ old('location', $trip->location) }}"
                               class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-brand-green focus:border-transparent">
                        @error('location')
                            <p class="text-red-500 text-sm mt-1">{{ $message }}</p>
                        @enderror
                    </div>

                    <div>
                        <label for="date_start" class="block text-sm font-medium text-gray-700 mb-2">
                            {{ __('Start Date') }} *
                        </label>
                        <input type="date" name="date_start" id="date_start" value="{{ old('date_start', $trip->date_start->format('Y-m-d')) }}" required
                               class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-brand-green focus:border-transparent">
                        @error('date_start')
                            <p class="text-red-500 text-sm mt-1">{{ $message }}</p>
                        @enderror
                    </div>

                    <div>
                        <label for="date_end" class="block text-sm font-medium text-gray-700 mb-2">
                            {{ __('End Date') }} *
                        </label>
                        <input type="date" name="date_end" id="date_end" value="{{ old('date_end', $trip->date_end->format('Y-m-d')) }}" required
                               class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-brand-green focus:border-transparent">
                        @error('date_end')
                            <p class="text-red-500 text-sm mt-1">{{ $message }}</p>
                        @enderror
                    </div>

                    <div>
                        <label for="status" class="block text-sm font-medium text-gray-700 mb-2">
                            {{ __('Status') }} *
                        </label>
                        <select name="status" id="status" required
                                class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-brand-green focus:border-transparent">
                            <option value="upcoming" {{ old('status', $trip->status) === 'upcoming' ? 'selected' : '' }}>{{ __('Upcoming') }}</option>
                            <option value="current" {{ old('status', $trip->status) === 'current' ? 'selected' : '' }}>{{ __('Current') }}</option>
                            <option value="past" {{ old('status', $trip->status) === 'past' ? 'selected' : '' }}>{{ __('Past') }}</option>
                        </select>
                        @error('status')
                            <p class="text-red-500 text-sm mt-1">{{ $message }}</p>
                        @enderror
                    </div>

                    <div>
                        <label for="price_from_mvr" class="block text-sm font-medium text-gray-700 mb-2">
                            {{ __('Price From (MVR)') }}
                        </label>
                        <input type="number" name="price_from_mvr" id="price_from_mvr" value="{{ old('price_from_mvr', $trip->price_from_mvr) }}" min="0"
                               class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-brand-green focus:border-transparent">
                        @error('price_from_mvr')
                            <p class="text-red-500 text-sm mt-1">{{ $message }}</p>
                        @enderror
                    </div>
                </div>

                <div class="mt-6">
                    <label for="summary" class="block text-sm font-medium text-gray-700 mb-2">
                        {{ __('Summary') }}
                    </label>
                    <textarea name="summary" id="summary" rows="3"
                              class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-brand-green focus:border-transparent">{{ old('summary', $trip->summary) }}</textarea>
                    @error('summary')
                        <p class="text-red-500 text-sm mt-1">{{ $message }}</p>
                    @enderror
                </div>

                <div class="mt-6">
                    <label for="details" class="block text-sm font-medium text-gray-700 mb-2">
                        {{ __('Trip Details') }}
                    </label>
                    <textarea name="details" id="details" rows="6"
                              class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-brand-green focus:border-transparent">{{ old('details', $trip->details) }}</textarea>
                    @error('details')
                        <p class="text-red-500 text-sm mt-1">{{ $message }}</p>
                    @enderror
                </div>

                <!-- Dhivehi Fields (Only show when trip is in Dhivehi) -->
                @if($trip->locale === 'dv')
                <div class="mt-8 border-t pt-6">
                    <h3 class="text-lg font-medium text-gray-900 mb-4">{{ __('Dhivehi Content') }}</h3>
                    
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6 mb-6">
                        <div>
                            <label for="title_dv" class="block text-sm font-medium text-gray-700 mb-2">
                                {{ __('Trip Title (Dhivehi)') }}
                            </label>
                            <input type="text" name="title_dv" id="title_dv" value="{{ old('title_dv', $trip->title_dv) }}"
                                   class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-brand-green focus:border-transparent">
                            @error('title_dv')
                                <p class="text-red-500 text-sm mt-1">{{ $message }}</p>
                            @enderror
                        </div>

                        <div>
                            <label for="location_dv" class="block text-sm font-medium text-gray-700 mb-2">
                                {{ __('Location (Dhivehi)') }}
                            </label>
                            <input type="text" name="location_dv" id="location_dv" value="{{ old('location_dv', $trip->location_dv) }}"
                                   class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-brand-green focus:border-transparent">
                            @error('location_dv')
                                <p class="text-red-500 text-sm mt-1">{{ $message }}</p>
                            @enderror
                        </div>
                    </div>

                    <div class="mb-6">
                        <label for="summary_dv" class="block text-sm font-medium text-gray-700 mb-2">
                            {{ __('Summary (Dhivehi)') }}
                        </label>
                        <textarea name="summary_dv" id="summary_dv" rows="3"
                                  class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-brand-green focus:border-transparent">{{ old('summary_dv', $trip->summary_dv) }}</textarea>
                        @error('summary_dv')
                            <p class="text-red-500 text-sm mt-1">{{ $message }}</p>
                        @enderror
                    </div>

                    <div class="mb-6">
                        <label for="details_dv" class="block text-sm font-medium text-gray-700 mb-2">
                            {{ __('Trip Details (Dhivehi)') }}
                        </label>
                        <textarea name="details_dv" id="details_dv" rows="6"
                                  class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-brand-green focus:border-transparent">{{ old('details_dv', $trip->details_dv) }}</textarea>
                        @error('details_dv')
                            <p class="text-red-500 text-sm mt-1">{{ $message }}</p>
                        @enderror
                    </div>
                </div>
                @endif

                <div class="mt-6">
                    <label for="cover_image" class="block text-sm font-medium text-gray-700 mb-2">
                        {{ __('Cover Image') }}
                    </label>
                    
                    @if($trip->cover_image)
                        <div class="mb-4">
                            <p class="text-sm text-gray-600 mb-2">{{ __('Current Image:') }}</p>
                            <img src="{{ Storage::url($trip->cover_image) }}" alt="{{ $trip->title }}" class="h-32 w-auto rounded-lg object-cover">
                        </div>
                    @endif
                    
                    <input type="file" name="cover_image" id="cover_image" accept="image/*"
                           class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-brand-green focus:border-transparent">
                    <p class="text-sm text-gray-500 mt-1">{{ __('Accepted formats: JPEG, PNG, WebP. Max size: 6MB') }}</p>
                    @error('cover_image')
                        <p class="text-red-500 text-sm mt-1">{{ $message }}</p>
                    @enderror
                </div>

                <div class="mt-6">
                    <label class="flex items-center">
                        <input type="checkbox" name="is_published" value="1" {{ old('is_published', $trip->is_published) ? 'checked' : '' }}
                               class="rounded border-gray-300 text-brand-green focus:ring-brand-green">
                        <span class="ml-2 text-sm text-gray-700">{{ __('Publish this trip') }}</span>
                    </label>
                </div>

                <div class="mt-8 flex justify-end space-x-4">
                    <a href="{{ route('admin.trips.index') }}" class="px-6 py-2 border border-gray-300 rounded-lg text-gray-700 hover:bg-gray-50 transition-colors">
                        {{ __('Cancel') }}
                    </a>
                    <button type="submit" class="btn-primary">
                        {{ __('Update Trip') }}
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>
@endsection
