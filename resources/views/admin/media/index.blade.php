@extends('layouts.app')

@section('content')
<div class="container mx-auto px-4 py-8">
    <div class="flex justify-between items-center mb-8">
        <h1 class="text-3xl font-bold text-gray-800">{{ __('Manage Media') }}</h1>
        <a href="{{ route('admin.media.create') }}" class="btn-primary">
            {{ __('Add New Media') }}
        </a>
    </div>

    @if(session('success'))
        <div class="bg-success/10 border border-success/40 text-success-dark px-4 py-3 rounded mb-6">
            {{ session('success') }}
        </div>
    @endif

    @if(session('error'))
        <div class="bg-error/10 border border-error/40 text-error-dark px-4 py-3 rounded mb-6">
            {{ session('error') }}
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

    <div class="card">
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                            {{ __('Media') }}
                        </th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                            {{ __('Type') }}
                        </th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                            {{ __('Trip') }}
                        </th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                            {{ __('Published') }}
                        </th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                            {{ __('Actions') }}
                        </th>
                    </tr>
                </thead>
                <tbody class="bg-white divide-y divide-gray-200">
                    @foreach($media as $item)
                    <tr>
                        <td class="px-6 py-4 whitespace-nowrap">
                            <div class="flex items-center">
                                @if($item->type === 'photo')
                                    <div class="flex-shrink-0 h-16 w-16">
                                        <img class="h-16 w-16 rounded-lg object-cover" src="{{ Storage::url($item->thumb_path ?? $item->file_path) }}" alt="{{ $item->title }}">
                                    </div>
                                @else
                                    <div class="flex-shrink-0 h-16 w-16 bg-gray-200 rounded-lg flex items-center justify-center">
                                        <svg aria-hidden="true" focusable="false" class="w-8 h-8 text-gray-400" fill="currentColor" viewBox="0 0 24 24">
                                            <path d="M8 5v14l11-7z"/>
                                        </svg>
                                    </div>
                                @endif
                                <div class="ml-4">
                                    <div class="text-sm font-medium text-gray-900">{{ $item->title ?: 'Untitled' }}</div>
                                    @if($item->caption)
                                        <div class="text-sm text-gray-500">{{ Str::limit($item->caption, 50) }}</div>
                                    @endif
                                </div>
                            </div>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap">
                            <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full 
                                @if($item->type === 'photo') bg-wine-50 text-wine-600
                                @else bg-error/10 text-error-dark
                                @endif">
                                {{ ucfirst($item->type) }}
                            </span>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                            @if($item->trip)
                                <a href="{{ route('admin.trips.edit', $item->trip) }}" class="text-wine-500 hover:text-wine-600">
                                    {{ $item->trip->title }}
                                </a>
                            @else
                                <span class="text-gray-500">{{ __('Standalone') }}</span>
                            @endif
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                            @if($item->is_published)
                                <span class="text-success">{{ __('Yes') }}</span>
                            @else
                                <span class="text-error">{{ __('No') }}</span>
                            @endif
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-sm font-medium">
                            <div class="flex space-x-2">
                                <a href="{{ route('admin.media.edit', $item) }}" class="text-wine-500 hover:text-wine-600">
                                    {{ __('Edit') }}
                                </a>
                                <a href="{{ route('admin.media.show', $item) }}" class="text-wine-500 hover:text-wine-600">
                                    {{ __('View') }}
                                </a>
                                <form action="{{ route('admin.media.destroy', $item) }}" method="POST" class="inline" style="display: inline;" id="delete-form-{{ $item->id }}">
                                    @csrf
                                    <input type="hidden" name="_method" value="DELETE">
                                    <button type="submit" 
                                            class="text-error hover:text-error-dark focus:outline-none focus:ring-2 focus:ring-error focus:ring-offset-2 rounded px-2 py-1"
                                            onclick="return confirmAndSubmit(event, {{ $item->id }}, '{{ $item->title ?: 'this media item' }}')">
                                        {{ __('Delete') }}
                                    </button>
                                </form>
                            </div>
                        </td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        
        <div class="mt-6">
            {{ $media->links() }}
        </div>
    </div>
</div>

<script>
function confirmAndSubmit(event, itemId, itemName) {
    event.preventDefault();
    
    console.log('Delete clicked for item:', itemId, itemName);
    
    if (confirm('Are you sure you want to delete "' + itemName + '"? This action cannot be undone.')) {
        const form = document.getElementById('delete-form-' + itemId);
        console.log('Form found:', form);
        console.log('Form action:', form.action);
        console.log('Form method:', form.method);
        
        // Check all form inputs
        const formData = new FormData(form);
        console.log('Form data:');
        for (let [key, value] of formData.entries()) {
            console.log(key + ':', value);
        }
        
        // Submit the form
        console.log('Submitting form...');
        form.submit();
        
        return true;
    }
    
    return false;
}

// Debug CSRF token and forms on page load
document.addEventListener('DOMContentLoaded', function() {
    console.log('CSRF Token:', document.querySelector('meta[name="csrf-token"]')?.getAttribute('content'));
    
    const deleteForms = document.querySelectorAll('form[method="POST"]');
    console.log('Found delete forms:', deleteForms.length);
    
    deleteForms.forEach((form, index) => {
        console.log('Form ' + index + ':', form.action);
        const csrfInput = form.querySelector('input[name="_token"]');
        const methodInputs = form.querySelectorAll('input[name="_method"]');
        console.log('Form ' + index + ' - CSRF input:', csrfInput ? 'Found (' + csrfInput.value + ')' : 'Missing');
        console.log('Form ' + index + ' - Method inputs:', methodInputs.length, methodInputs.length > 0 ? Array.from(methodInputs).map(input => input.value) : 'None');
    });
});
</script>

@endsection
