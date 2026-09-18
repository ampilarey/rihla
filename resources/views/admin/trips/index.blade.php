@extends('layouts.app')

@section('content')
<div class="container mx-auto px-4 py-8">
    <div class="flex justify-between items-center mb-8">
        <div class="flex items-center space-x-4">
            <a href="{{ route('admin.dashboard') }}" class="text-wine-500 hover:text-wine-600 flex items-center space-x-2">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"></path>
                </svg>
                <span>{{ __('Back to Dashboard') }}</span>
            </a>
            <h1 class="text-3xl font-bold text-gray-800">{{ __('Manage Trips') }}</h1>
        </div>
        <a href="{{ route('admin.trips.create') }}" class="btn-primary">
            {{ __('Add New Trip') }}
        </a>
    </div>

    @if(session('success'))
        <div class="bg-success/10 border border-success/40 text-success-dark px-4 py-3 rounded mb-6">
            {{ session('success') }}
        </div>
    @endif

    <div class="card">
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                            {{ __('Trip') }}
                        </th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                            {{ __('Dates') }}
                        </th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                            {{ __('Status') }}
                        </th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                            {{ __('Language') }}
                        </th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-xs font-medium text-gray-500 uppercase tracking-wider">
                            {{ __('Published') }}
                        </th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                            {{ __('Actions') }}
                        </th>
                    </tr>
                </thead>
                <tbody class="bg-white divide-y divide-gray-200">
                    @foreach($trips as $trip)
                    <tr>
                        <td class="px-6 py-4 whitespace-nowrap">
                            <div class="flex items-center">
                                @if($trip->cover_image)
                                <div class="flex-shrink-0 h-12 w-12">
                                    <img class="h-12 w-12 rounded-lg object-cover" src="{{ Storage::url($trip->cover_image) }}" alt="{{ $trip->title }}">
                                </div>
                                @endif
                                <div class="ml-4">
                                    <div class="text-sm font-medium text-gray-900">{{ $trip->title }}</div>
                                    <div class="text-sm text-gray-500">{{ $trip->location }}</div>
                                </div>
                            </div>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                            {{ $trip->date_start->format('M d, Y') }} - {{ $trip->date_end->format('M d, Y') }}
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap">
                            <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full 
                                @if($trip->status === 'current') bg-success/10 text-success-dark
                                @elseif($trip->status === 'upcoming') bg-warning/10 text-warning-dark
                                @else bg-gray-100 text-gray-800
                                @endif">
                                {{ ucfirst($trip->status) }}
                            </span>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap">
                            <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full bg-wine-50 text-wine-600">
                                {{ strtoupper($trip->locale) }}
                            </span>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                            @if($trip->is_published)
                                <span class="text-success">{{ __('Yes') }}</span>
                            @else
                                <span class="text-error">{{ __('No') }}</span>
                            @endif
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-sm font-medium">
                            <div class="flex space-x-2">
                                <a href="{{ route('admin.trips.edit', $trip) }}" class="text-wine-500 hover:text-wine-600">
                                    {{ __('Edit') }}
                                </a>
                                <a href="{{ route('admin.trips.show', $trip) }}" class="text-wine-500 hover:text-wine-600">
                                    {{ __('View') }}
                                </a>
                                <form action="{{ route('admin.trips.destroy', $trip) }}" method="POST" class="inline" onsubmit="return confirm('{{ __('Are you sure you want to delete this trip?') }}')">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" class="text-error hover:text-error-dark">
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
            {{ $trips->links() }}
        </div>
    </div>
</div>
@endsection
