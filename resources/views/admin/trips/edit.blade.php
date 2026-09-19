@extends('layouts.app')

@section('content')
<div class="container mx-auto px-4 py-8">
    <div class="max-w-4xl mx-auto">
        <div class="flex items-center justify-between mb-8">
            <h1 class="text-3xl font-bold text-gray-800">{{ __('Edit Trip') }}</h1>
            <a href="{{ route('admin.trips.index') }}" class="text-wine-500 hover:text-wine-600">
                {{ __('← Back to Trips') }}
            </a>
        </div>

        <div class="card">
            <form action="{{ route('admin.trips.update', $trip) }}" method="POST" enctype="multipart/form-data">
                @csrf
                @method('PUT')

                <p class="text-sm text-gray-600 mb-6">
                    {{ __('One trip, both languages. English is required; Dhivehi is optional, and a visitor reading Dhivehi sees the English text wherever it is left blank.') }}
                </p>

                <x-admin.translatable-field name="title" :label="__('Trip Title')"
                    :value="old('title', $trip->getTranslations('title'))" required />

                <x-admin.translatable-field name="location" :label="__('Location')"
                    :value="old('location', $trip->getTranslations('location'))" />

                <div class="grid grid-cols-1 md:grid-cols-2 gap-6 mb-6">
                    <div>
                        <label for="date_start" class="block text-sm font-medium text-gray-700 mb-2">
                            {{ __('Start Date') }} *
                        </label>
                        <input type="date" name="date_start" id="date_start" value="{{ old('date_start', $trip->date_start->format('Y-m-d')) }}" required
                               class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-wine-500 focus:border-transparent">
                        @error('date_start')
                            <p class="text-error text-sm mt-1">{{ $message }}</p>
                        @enderror
                    </div>

                    <div>
                        <label for="date_end" class="block text-sm font-medium text-gray-700 mb-2">
                            {{ __('End Date') }} *
                        </label>
                        <input type="date" name="date_end" id="date_end" value="{{ old('date_end', $trip->date_end->format('Y-m-d')) }}" required
                               class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-wine-500 focus:border-transparent">
                        @error('date_end')
                            <p class="text-error text-sm mt-1">{{ $message }}</p>
                        @enderror
                    </div>

                    <div>
                        <label for="status" class="block text-sm font-medium text-gray-700 mb-2">
                            {{ __('Status') }} *
                        </label>
                        <select name="status" id="status" required
                                class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-wine-500 focus:border-transparent">
                            <option value="upcoming" {{ old('status', $trip->status) === 'upcoming' ? 'selected' : '' }}>{{ __('Upcoming') }}</option>
                            <option value="current" {{ old('status', $trip->status) === 'current' ? 'selected' : '' }}>{{ __('Current') }}</option>
                            <option value="past" {{ old('status', $trip->status) === 'past' ? 'selected' : '' }}>{{ __('Past') }}</option>
                        </select>
                        @error('status')
                            <p class="text-error text-sm mt-1">{{ $message }}</p>
                        @enderror
                    </div>

                    <div>
                        <label for="price_from_mvr" class="block text-sm font-medium text-gray-700 mb-2">
                            {{ __('Price From (MVR)') }}
                        </label>
                        <input type="number" name="price_from_mvr" id="price_from_mvr" value="{{ old('price_from_mvr', $trip->price_from_mvr) }}" min="0"
                               class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-wine-500 focus:border-transparent">
                        @error('price_from_mvr')
                            <p class="text-error text-sm mt-1">{{ $message }}</p>
                        @enderror
                    </div>
                </div>

                <x-admin.translatable-field name="summary" :label="__('Summary')"
                    :value="old('summary', $trip->getTranslations('summary'))" type="textarea" :rows="3" />

                <x-admin.translatable-field name="details" :label="__('Trip Details')"
                    :value="old('details', $trip->getTranslations('details'))" type="textarea" :rows="6" />

                <div class="mb-6">
                    <label for="cover_image" class="block text-sm font-medium text-gray-700 mb-2">
                        {{ __('Cover Image') }}
                    </label>

                    @if($trip->cover_image)
                        <div class="mb-4">
                            <p class="text-sm text-gray-600 mb-2">{{ __('Current Image:') }}</p>
                            <img src="{{ Storage::url($trip->cover_image) }}" alt="{{ $trip->title }}" class="h-32 w-auto rounded-lg object-cover"
         loading="lazy"
         decoding="async">
                        </div>
                    @endif

                    <input type="file" name="cover_image" id="cover_image" accept="image/*"
                           class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-wine-500 focus:border-transparent">
                    <p class="text-sm text-gray-500 mt-1">{{ __('Accepted formats: JPEG, PNG, WebP. Max size: 6MB') }}</p>
                    @error('cover_image')
                        <p class="text-error text-sm mt-1">{{ $message }}</p>
                    @enderror
                </div>

                <div class="mb-6">
                    <label class="flex items-center">
                        <input type="checkbox" name="is_published" value="1" {{ old('is_published', $trip->is_published) ? 'checked' : '' }}
                               class="rounded border-gray-300 text-wine-500 focus:ring-wine-500">
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
