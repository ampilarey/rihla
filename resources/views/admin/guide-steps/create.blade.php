@extends('layouts.app')

@section('title', 'Create Guide Step')

@section('content')
<div class="container mx-auto px-4 py-8">
    <div class="max-w-4xl mx-auto">
        <div class="flex items-center justify-between mb-8">
            <div>
                <h1 class="text-3xl font-bold text-gray-900">Create Guide Step</h1>
                <p class="mt-2 text-gray-600">Add a new step to the Umrah guide</p>
            </div>
            <a href="{{ route('admin.guide-steps.index') }}" 
               class="inline-flex items-center px-4 py-2 bg-gray-100 hover:bg-gray-200 text-gray-700 font-medium rounded-lg transition-colors duration-200">
                <svg aria-hidden="true" focusable="false" class="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"></path>
                </svg>
                Back to List
            </a>
        </div>

        <form action="{{ route('admin.guide-steps.store') }}" method="POST" enctype="multipart/form-data" class="space-y-8">
            @csrf

            <div class="bg-white rounded-lg shadow-sm border border-gray-200 p-6">
                <h2 class="text-lg font-semibold text-gray-900 mb-2">Basic Information</h2>
                <p class="text-sm text-gray-600 mb-6">
                    One step, both languages. English is required; Dhivehi is optional, and a
                    pilgrim reading Dhivehi sees the English wherever it is left blank.
                </p>

                <div class="mb-6">
                    <label for="step_number" class="block text-sm font-medium text-gray-700 mb-2">Step Number</label>
                    <input type="number" name="step_number" id="step_number" min="1" value="{{ old('step_number') }}" required
                           class="w-full md:w-1/2 border border-gray-300 rounded-lg px-3 py-2 focus:outline-none focus:ring-2 focus:ring-wine-500 focus:border-transparent">
                    @error('step_number')
                        <p class="mt-1 text-sm text-error">{{ $message }}</p>
                    @enderror
                </div>

                <x-admin.translatable-field name="title" label="Title" :value="old('title', [])" required
                    help="Maximum 120 characters." />

                <x-admin.translatable-field name="summary" label="Summary" :value="old('summary', [])" required
                    type="textarea" :rows="3" help="Shown on the step card — one or two sentences." />

                <x-admin.translatable-field name="details" label="Details (optional)" :value="old('details', [])"
                    type="textarea" :rows="5" />

                <div>
                    <label for="image" class="block text-sm font-medium text-gray-700 mb-2">Step Image (Optional)</label>
                    <input type="file" name="image" id="image" accept="image/*"
                           class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:outline-none focus:ring-2 focus:ring-wine-500 focus:border-transparent">
                    <p class="mt-1 text-sm text-gray-500">Recommended: 1200x900px, max 6MB, supports JPEG, PNG, WebP</p>
                    @error('image')
                        <p class="mt-1 text-sm text-error">{{ $message }}</p>
                    @enderror
                </div>
            </div>

            <div class="bg-white rounded-lg shadow-sm border border-gray-200 p-6">
                <h2 class="text-lg font-semibold text-gray-900 mb-6">Additional Content</h2>

                <div class="mb-6">
                    <label for="dua_text" class="block text-sm font-medium text-gray-700 mb-2">Du'a Text (Optional)</label>
                    <textarea name="dua_text" id="dua_text" rows="3"
                              class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:outline-none focus:ring-2 focus:ring-wine-500 focus:border-transparent"
                              placeholder="Supplication text for this step...">{{ old('dua_text') }}</textarea>
                    {{-- Deliberately one field, not two. The du'a is the Arabic of the
                         rite: the same words whatever language the page is in. --}}
                    <p class="mt-1 text-sm text-gray-500">Arabic, shown unchanged in both languages.</p>
                    @error('dua_text')
                        <p class="mt-1 text-sm text-error">{{ $message }}</p>
                    @enderror
                </div>

                <x-admin.translatable-field name="reference_text" label="Reference (Optional)"
                    :value="old('reference_text', [])" type="textarea" :rows="2"
                    help="Qur'an and hadith this step is based on." />

                <x-admin.translatable-field name="fiqh_notes" label="Fiqh Notes (Optional)"
                    :value="old('fiqh_notes', [])" type="lines" :rows="4"
                    help="One note per line — each is shown as a separate point." />

                <x-admin.translatable-field name="checklist" label="Checklist (Optional)"
                    :value="old('checklist', [])" type="lines" :rows="4"
                    help="One item per line." />

                <div>
                    <label for="video_url" class="block text-sm font-medium text-gray-700 mb-2">Video URL (Optional)</label>
                    <input type="url" name="video_url" id="video_url" value="{{ old('video_url') }}"
                           class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:outline-none focus:ring-2 focus:ring-wine-500 focus:border-transparent"
                           placeholder="https://youtube.com/watch?v=... or https://vimeo.com/...">
                    <p class="mt-1 text-sm text-gray-500">YouTube or Vimeo URL for step demonstration</p>
                    @error('video_url')
                        <p class="mt-1 text-sm text-error">{{ $message }}</p>
                    @enderror
                </div>
            </div>

            <div class="bg-white rounded-lg shadow-sm border border-gray-200 p-6">
                <h2 class="text-lg font-semibold text-gray-900 mb-6">Publishing</h2>

                <div class="flex items-center">
                    <input type="checkbox" name="is_published" id="is_published" value="1" {{ old('is_published') ? 'checked' : '' }}
                           class="h-4 w-4 text-wine-500 focus:ring-wine-500 border-gray-300 rounded">
                    <label for="is_published" class="ml-2 block text-sm text-gray-900">
                        Publish this step immediately
                    </label>
                </div>
                <p class="mt-2 text-sm text-gray-500">Uncheck to save as draft</p>
            </div>

            <div class="flex justify-end gap-4">
                <a href="{{ route('admin.guide-steps.index') }}"
                   class="px-6 py-3 border border-gray-300 rounded-lg text-gray-700 font-medium hover:bg-gray-50 transition-colors duration-200">
                    Cancel
                </a>
                <button type="submit"
                        class="px-6 py-3 bg-wine-500 hover:bg-wine-600 text-white font-medium rounded-lg transition-colors duration-200">
                    Create Guide Step
                </button>
            </div>
        </form>
    </div>
</div>
@endsection
