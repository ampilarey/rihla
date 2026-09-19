@extends('layouts.app')

@section('content')
<div class="container mx-auto px-4 py-8">
    <div class="max-w-4xl mx-auto">
        <div class="flex items-center justify-between mb-8">
            <h1 class="text-3xl font-bold text-gray-800">{{ __('Create New Trip') }}</h1>
            <a href="{{ route('admin.trips.index') }}" class="text-wine-500 hover:text-wine-600">
                {{ __('← Back to Trips') }}
            </a>
        </div>

        <div class="card">
            <form action="{{ route('admin.trips.store') }}" method="POST" enctype="multipart/form-data">
                @csrf
                
                <!-- Locale Selection -->
                <div class="mb-6">
                    <label for="locale" class="block text-sm font-medium text-gray-700 mb-2">
                        {{ __('Language') }} *
                    </label>
                    <select name="locale" id="locale" required
                            class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-wine-500 focus:border-transparent">
                        <option value="en" {{ ($locale ?? 'en') === 'en' ? 'selected' : '' }}>{{ __('English') }}</option>
                        <option value="dv" {{ ($locale ?? 'en') === 'dv' ? 'selected' : '' }}>{{ __('Dhivehi') }}</option>
                    </select>
                    @error('locale')
                        <p class="text-error text-sm mt-1">{{ $message }}</p>
                    @enderror
                </div>
                
                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                    <div>
                        <label for="title" class="block text-sm font-medium text-gray-700 mb-2">
                            {{ __('Trip Title') }} *
                        </label>
                        <input type="text" name="title" id="title" value="{{ old('title') }}" required
                               class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-wine-500 focus:border-transparent">
                        @error('title')
                            <p class="text-error text-sm mt-1">{{ $message }}</p>
                        @enderror
                    </div>

                    <div>
                        <label for="location" class="block text-sm font-medium text-gray-700 mb-2">
                            {{ __('Location') }}
                        </label>
                        <input type="text" name="location" id="location" value="{{ old('location') }}"
                               class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-wine-500 focus:border-transparent">
                        @error('location')
                            <p class="text-error text-sm mt-1">{{ $message }}</p>
                        @enderror
                    </div>

                    <div>
                        <label for="date_start" class="block text-sm font-medium text-gray-700 mb-2">
                            {{ __('Start Date') }} *
                        </label>
                        <input type="date" name="date_start" id="date_start" value="{{ old('date_start') }}" required
                               class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-wine-500 focus:border-transparent">
                        @error('date_start')
                            <p class="text-error text-sm mt-1">{{ $message }}</p>
                        @enderror
                    </div>

                    <div>
                        <label for="date_end" class="block text-sm font-medium text-gray-700 mb-2">
                            {{ __('End Date') }} *
                        </label>
                        <input type="date" name="date_end" id="date_end" value="{{ old('date_end') }}" required
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
                            <option value="upcoming" {{ old('status') === 'upcoming' ? 'selected' : '' }}>{{ __('Upcoming') }}</option>
                            <option value="current" {{ old('status') === 'current' ? 'selected' : '' }}>{{ __('Current') }}</option>
                            <option value="past" {{ old('status') === 'past' ? 'selected' : '' }}>{{ __('Past') }}</option>
                        </select>
                        @error('status')
                            <p class="text-error text-sm mt-1">{{ $message }}</p>
                        @enderror
                    </div>

                    <div>
                        <label for="price_from_mvr" class="block text-sm font-medium text-gray-700 mb-2">
                            {{ __('Price From (MVR)') }}
                        </label>
                        <input type="number" name="price_from_mvr" id="price_from_mvr" value="{{ old('price_from_mvr') }}" min="0"
                               class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-wine-500 focus:border-transparent">
                        @error('price_from_mvr')
                            <p class="text-error text-sm mt-1">{{ $message }}</p>
                        @enderror
                    </div>
                </div>

                <div class="mt-6">
                    <label for="summary" class="block text-sm font-medium text-gray-700 mb-2">
                        {{ __('Summary') }}
                    </label>
                    <textarea name="summary" id="summary" rows="3"
                              class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-wine-500 focus:border-transparent">{{ old('summary') }}</textarea>
                    @error('summary')
                        <p class="text-error text-sm mt-1">{{ $message }}</p>
                    @enderror
                </div>

                <!-- Dhivehi Fields (Only show when Dhivehi is selected) -->
                <div id="dhivehi-fields" class="mt-8 border-t pt-6 hidden">
                    <h3 class="text-lg font-medium text-gray-900 mb-4">{{ __('Dhivehi Content') }}</h3>
                    
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6 mb-6">
                        <div>
                            <label for="title_dv" class="block text-sm font-medium text-gray-700 mb-2">
                                {{ __('Trip Title (Dhivehi)') }}
                            </label>
                            <input type="text" name="title_dv" id="title_dv" value="{{ old('title_dv') }}"
                                   class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-wine-500 focus:border-transparent">
                            @error('title_dv')
                                <p class="text-error text-sm mt-1">{{ $message }}</p>
                            @enderror
                        </div>

                        <div>
                            <label for="location_dv" class="block text-sm font-medium text-gray-700 mb-2">
                                {{ __('Location (Dhivehi)') }}
                            </label>
                            <input type="text" name="location_dv" id="location_dv" value="{{ old('location_dv') }}"
                                   class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-wine-500 focus:border-transparent">
                            @error('location_dv')
                                <p class="text-error text-sm mt-1">{{ $message }}</p>
                            @enderror
                        </div>
                    </div>

                    <div class="mb-6">
                        <label for="summary_dv" class="block text-sm font-medium text-gray-700 mb-2">
                            {{ __('Summary (Dhivehi)') }}
                        </label>
                        <textarea name="summary_dv" id="summary_dv" rows="3"
                                  class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-wine-500 focus:border-transparent">{{ old('summary_dv') }}</textarea>
                        @error('summary_dv')
                            <p class="text-error text-sm mt-1">{{ $message }}</p>
                        @enderror
                    </div>

                    <div class="mb-6">
                        <label for="details_dv" class="block text-sm font-medium text-gray-700 mb-2">
                            {{ __('Trip Details (Dhivehi)') }}
                        </label>
                        <textarea name="details_dv" id="details_dv" rows="6"
                                  class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-wine-500 focus:border-transparent">{{ old('details_dv') }}</textarea>
                        @error('details_dv')
                            <p class="text-error text-sm mt-1">{{ $message }}</p>
                        @enderror
                    </div>
                </div>

                <div class="mt-6">
                    <label for="details" class="block text-sm font-medium text-gray-700 mb-2">
                        {{ __('Trip Details') }}
                    </label>
                    <textarea name="details" id="details" rows="6"
                              class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-wine-500 focus:border-transparent">{{ old('details') }}</textarea>
                    @error('details')
                        <p class="text-error text-sm mt-1">{{ $message }}</p>
                    @enderror
                </div>

                <div class="mt-6">
                    <label for="cover_image" class="block text-sm font-medium text-gray-700 mb-2">
                        {{ __('Cover Image') }}
                    </label>
                    <input type="file" name="cover_image" id="cover_image" accept="image/*"
                           class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-wine-500 focus:border-transparent">
                    <p class="text-sm text-gray-500 mt-1">{{ __('Accepted formats: JPEG, PNG, WebP. Max size: 6MB') }}</p>
                    @error('cover_image')
                        <p class="text-error text-sm mt-1">{{ $message }}</p>
                    @enderror
                </div>

                <div class="mt-6">
                    <label class="flex items-center">
                        <input type="checkbox" name="is_published" value="1" {{ old('is_published') ? 'checked' : '' }}
                               class="rounded border-gray-300 text-wine-500 focus:ring-wine-500">
                        <span class="ml-2 text-sm text-gray-700">{{ __('Publish this trip') }}</span>
                    </label>
                </div>

                <div class="mt-8 flex justify-end space-x-4">
                    <a href="{{ route('admin.trips.index') }}" class="px-6 py-2 border border-gray-300 rounded-lg text-gray-700 hover:bg-gray-50 transition-colors">
                        {{ __('Cancel') }}
                    </a>
                    <button type="submit" class="btn-primary">
                        {{ __('Create Trip') }}
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    
    const localeSelect = document.getElementById('locale');
    const dhivehiFields = document.getElementById('dhivehi-fields');

    if (!localeSelect || !dhivehiFields) {
        console.error('Required elements not found');
        return;
    }
    
    function toggleDhivehiFields() {
        if (localeSelect.value === 'dv') {
            dhivehiFields.classList.remove('hidden');
        } else {
            dhivehiFields.classList.add('hidden');
        }
    }
    
    // Initial state
    toggleDhivehiFields();
    
    // Listen for changes
    localeSelect.addEventListener('change', toggleDhivehiFields);
    
});
</script>
@endsection
