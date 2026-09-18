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
                <svg class="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"></path>
                </svg>
                Back to List
            </a>
        </div>

        <form action="{{ route('admin.guide-steps.store') }}" method="POST" enctype="multipart/form-data" class="space-y-8">
            @csrf
            
            <div class="bg-white rounded-lg shadow-sm border border-gray-200 p-6">
                <h2 class="text-lg font-semibold text-gray-900 mb-6">Basic Information</h2>
                
                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                    <div>
                        <label for="locale" class="block text-sm font-medium text-gray-700 mb-2">Language</label>
                        <select name="locale" id="locale" required class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:outline-none focus:ring-2 focus:ring-wine-500 focus:border-transparent">
                            <option value="">Select Language</option>
                            <option value="en" {{ old('locale') === 'en' ? 'selected' : '' }}>English</option>
                            <option value="dv" {{ old('locale') === 'dv' ? 'selected' : '' }}>Dhivehi</option>
                        </select>
                        @error('locale')
                            <p class="mt-1 text-sm text-error">{{ $message }}</p>
                        @enderror
                    </div>
                    
                    <div>
                        <label for="step_number" class="block text-sm font-medium text-gray-700 mb-2">Step Number</label>
                        <input type="number" name="step_number" id="step_number" min="1" value="{{ old('step_number') }}" required
                               class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:outline-none focus:ring-2 focus:ring-wine-500 focus:border-transparent">
                        @error('step_number')
                            <p class="mt-1 text-sm text-error">{{ $message }}</p>
                        @enderror
                    </div>
                </div>
                
                <div class="mt-6">
                    <label for="title" class="block text-sm font-medium text-gray-700 mb-2">Title</label>
                    <input type="text" name="title" id="title" maxlength="120" value="{{ old('title') }}" required
                           class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:outline-none focus:ring-2 focus:ring-wine-500 focus:border-transparent"
                           placeholder="e.g., Intention (Niyyah)">
                    <p class="mt-1 text-sm text-gray-500">Maximum 120 characters</p>
                    @error('title')
                        <p class="mt-1 text-sm text-error">{{ $message }}</p>
                    @enderror
                </div>
                
                <div class="mt-6">
                    <label for="summary" class="block text-sm font-medium text-gray-700 mb-2">Summary *</label>
                    <textarea name="summary" id="summary" rows="3" required
                              class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:outline-none focus:ring-2 focus:ring-wine-500 focus:border-transparent"
                              placeholder="Short summary of this step (1-2 paragraphs)...">{{ old('summary') }}</textarea>
                    @error('summary')
                        <p class="mt-1 text-sm text-error">{{ $message }}</p>
                    @enderror
                </div>
                
                <div class="mt-6">
                    <label for="details" class="block text-sm font-medium text-gray-700 mb-2">Details (Optional)</label>
                    <textarea name="details" id="details" rows="4" 
                              class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:outline-none focus:ring-2 focus:ring-wine-500 focus:border-transparent"
                              placeholder="Expanded content and additional details...">{{ old('details') }}</textarea>
                    @error('details')
                        <p class="mt-1 text-sm text-error">{{ $message }}</p>
                    @enderror
                </div>
                
                <div class="mt-6">
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
                
                <div class="space-y-6">
                    <div>
                        <label for="dua_text" class="block text-sm font-medium text-gray-700 mb-2">Du'a Text (Optional)</label>
                        <textarea name="dua_text" id="dua_text" rows="3" 
                                  class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:outline-none focus:ring-2 focus:ring-wine-500 focus:border-transparent"
                                  placeholder="Supplication text for this step...">{{ old('dua_text') }}</textarea>
                        @error('dua_text')
                            <p class="mt-1 text-sm text-error">{{ $message }}</p>
                        @enderror
                    </div>
                    
                    <div>
                        <label for="reference_text" class="block text-sm font-medium text-gray-700 mb-2">Reference (Optional)</label>
                        <textarea name="reference_text" id="reference_text" rows="2"
                                  class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:outline-none focus:ring-2 focus:ring-wine-500 focus:border-transparent"
                                  placeholder="Qur'an and hadith this step is based on...">{{ old('reference_text') }}</textarea>
                        @error('reference_text')
                            <p class="mt-1 text-sm text-error">{{ $message }}</p>
                        @enderror
                    </div>

                    <div>
                        <label for="fiqh_notes" class="block text-sm font-medium text-gray-700 mb-2">Fiqh Notes (Optional)</label>
                        <textarea name="fiqh_notes" id="fiqh_notes" rows="3" 
                                  class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:outline-none focus:ring-2 focus:ring-wine-500 focus:border-transparent"
                                  placeholder="One note per line, e.g. Hanafi: ...">{{ implode("\n", (array) old('fiqh_notes', [])) }}</textarea>
                        <p class="mt-1 text-xs text-gray-500">One note per line — each is shown as a separate point.</p>
                        @error('fiqh_notes')
                            <p class="mt-1 text-sm text-error">{{ $message }}</p>
                        @enderror
                    </div>
                    
                    <div>
                        <label for="video_url" class="block text-sm font-medium text-gray-700 mb-2">Video URL (Optional)</label>
                        <input type="url" name="video_url" id="video_url" 
                               class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:outline-none focus:ring-2 focus:ring-wine-500 focus:border-transparent"
                               placeholder="https://youtube.com/watch?v=... or https://vimeo.com/...">
                        <p class="mt-1 text-sm text-gray-500">YouTube or Vimeo URL for step demonstration</p>
                        @error('video_url')
                            <p class="mt-1 text-sm text-error">{{ $message }}</p>
                        @enderror
                    </div>
                </div>
            </div>

            <div class="bg-white rounded-lg shadow-sm border border-gray-200 p-6">
                <h2 class="text-lg font-semibold text-gray-900 mb-6">Checklist & Notes</h2>
                
                <div class="space-y-6">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-3">Checklist Items (Optional)</label>
                        <div id="checklist-container" class="space-y-3">
                            <div class="flex gap-3">
                                <input type="text" name="checklist[]" 
                                       class="flex-1 border border-gray-300 rounded-lg px-3 py-2 focus:outline-none focus:ring-2 focus:ring-wine-500 focus:border-transparent"
                                       placeholder="Checklist item...">
                                <button type="button" onclick="removeChecklistItem(this)" 
                                        class="px-3 py-2 text-error hover:text-error-dark transition-colors duration-200">
                                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"></path>
                                    </svg>
                                </button>
                            </div>
                        </div>
                        <button type="button" onclick="addChecklistItem()" 
                                class="mt-3 inline-flex items-center px-3 py-2 border border-gray-300 rounded-lg text-sm font-medium text-gray-700 bg-white hover:bg-gray-50 transition-colors duration-200">
                            <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6"></path>
                            </svg>
                            Add Checklist Item
                        </button>
                    </div>
                    
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-3">Fiqh Notes (Optional)</label>
                        <div id="fiqh-notes-container" class="space-y-3">
                            <div class="flex gap-3">
                                <input type="text" name="fiqh_notes[]" 
                                       class="flex-1 border border-gray-300 rounded-lg px-3 py-2 focus:outline-none focus:ring-2 focus:ring-wine-500 focus:border-transparent"
                                       placeholder="Fiqh note (e.g., Hanafi • Shafi'i differences)...">
                                <button type="button" onclick="removeFiqhNote(this)" 
                                        class="px-3 py-2 text-error hover:text-error-dark transition-colors duration-200">
                                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"></path>
                                    </svg>
                                </button>
                            </div>
                        </div>
                        <button type="button" onclick="addFiqhNote()" 
                                class="mt-3 inline-flex items-center px-3 py-2 border border-gray-300 rounded-lg text-sm font-medium text-gray-700 bg-white hover:bg-gray-50 transition-colors duration-200">
                            <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6"></path>
                            </svg>
                            Add Fiqh Note
                        </button>
                    </div>
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

<script>
function addChecklistItem() {
    const container = document.getElementById('checklist-container');
    const newItem = document.createElement('div');
    newItem.className = 'flex gap-3';
    newItem.innerHTML = `
        <input type="text" name="checklist[]" 
               class="flex-1 border border-gray-300 rounded-lg px-3 py-2 focus:outline-none focus:ring-2 focus:ring-wine-500 focus:border-transparent"
               placeholder="Checklist item...">
        <button type="button" onclick="removeChecklistItem(this)" 
                class="px-3 py-2 text-error hover:text-error-dark transition-colors duration-200">
            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"></path>
            </svg>
        </button>
    `;
    container.appendChild(newItem);
}

function removeChecklistItem(button) {
    button.closest('.flex').remove();
}

function addFiqhNote() {
    const container = document.getElementById('fiqh-notes-container');
    const newItem = document.createElement('div');
    newItem.className = 'flex gap-3';
    newItem.innerHTML = `
        <input type="text" name="fiqh_notes[]" 
               class="flex-1 border border-gray-300 rounded-lg px-3 py-2 focus:outline-none focus:ring-2 focus:ring-wine-500 focus:border-transparent"
               placeholder="Fiqh note (e.g., Hanafi • Shafi'i differences)...">
        <button type="button" onclick="removeFiqhNote(this)" 
                class="px-3 py-2 text-error hover:text-error-dark transition-colors duration-200">
            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"></path>
            </svg>
        </button>
    `;
    container.appendChild(newItem);
}

function removeFiqhNote(button) {
    button.closest('.flex').remove();
}
</script>
@endsection
