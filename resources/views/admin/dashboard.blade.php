@extends('layouts.app')

@section('content')
<div class="container mx-auto px-4 py-8">
    <!-- Header with Logout -->
    <div class="flex justify-between items-center mb-8">
        <h1 class="text-3xl font-bold text-gray-800">{{ __('Admin Dashboard') }}</h1>
        <form method="POST" action="{{ route('logout') }}" class="inline">
            @csrf
            <button type="submit" 
                    class="bg-error hover:bg-error-dark text-white font-medium py-2 px-4 rounded-lg transition-colors duration-200 flex items-center space-x-2">
                <svg aria-hidden="true" focusable="false" class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1"></path>
                </svg>
                <span>{{ __('Logout') }}</span>
            </button>
        </form>
    </div>
    
    <!-- Stats Cards -->
    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6 mb-8">
        <div class="card text-center">
            <div class="w-16 h-16 bg-wine-500 rounded-full flex items-center justify-center mx-auto mb-4">
                <svg aria-hidden="true" focusable="false" class="w-8 h-8 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 20l-5.447-2.724A1 1 0 013 16.382V5.618a1 1 0 011.447-.894L9 7m0 13l6-3m-6 3V7m6 10l4.553 2.276A1 1 0 0021 18.382V7.618a1 1 0 00-1.447-.894L15 4m0 13V4m0 0L9 7"></path>
                </svg>
            </div>
            <h3 class="text-xl font-semibold text-gray-800 mb-2">{{ __('Total Trips') }}</h3>
            <p class="text-3xl font-bold text-wine-500">{{ $tripCount }}</p>
        </div>
        
        <div class="card text-center">
            <div class="w-16 h-16 bg-gold-500 rounded-full flex items-center justify-center mx-auto mb-4">
                <svg aria-hidden="true" focusable="false" class="w-8 h-8 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"></path>
                </svg>
            </div>
            <h3 class="text-xl font-semibold text-gray-800 mb-2">{{ __('Total Media') }}</h3>
            <p class="text-3xl font-bold text-gold-700">{{ $mediaCount }}</p>
        </div>
        
        <div class="card text-center">
            <div class="w-16 h-16 bg-wine-500 rounded-full flex items-center justify-center mx-auto mb-4">
                <svg aria-hidden="true" focusable="false" class="w-8 h-8 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path>
                </svg>
            </div>
            <h3 class="text-xl font-semibold text-gray-800 mb-2">{{ __('Guide Steps') }}</h3>
            <p class="text-3xl font-bold text-wine-500">{{ $guideStepCount }}</p>
        </div>
    </div>
    
    <!-- Main Content -->
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-8">
        <!-- Quick Actions -->
        <div class="card">
            <h3 class="text-xl font-bold text-gray-800 mb-6">{{ __('Quick Actions') }}</h3>
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <a href="{{ route('admin.trips.create') }}" 
                   class="btn-primary text-center py-4 px-6 flex items-center justify-center space-x-2 hover:opacity-90 transition-all duration-200">
                    <svg aria-hidden="true" focusable="false" class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6"></path>
                    </svg>
                    <span>{{ __('Add New Trip') }}</span>
                </a>
                
                <a href="{{ route('admin.media.create') }}" 
                   class="btn-secondary text-center py-4 px-6 flex items-center justify-center space-x-2 hover:opacity-90 transition-all duration-200">
                    <svg aria-hidden="true" focusable="false" class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"></path>
                    </svg>
                    <span>{{ __('Add New Media') }}</span>
                </a>
                
                <a href="{{ route('admin.settings.index') }}" 
                   class="bg-gray-600 hover:bg-gray-700 text-white font-medium py-4 px-6 rounded-2xl transition-colors duration-200 text-center flex items-center justify-center space-x-2">
                    <svg aria-hidden="true" focusable="false" class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"></path>
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"></path>
                    </svg>
                    <span>{{ __('Manage Settings') }}</span>
                </a>
                
                <a href="{{ \App\Filament\Resources\GuideSteps\GuideStepResource::getUrl('index') }}" 
                   class="bg-wine-500 hover:bg-wine-700 text-white font-medium py-4 px-6 rounded-2xl transition-colors duration-200 text-center flex items-center justify-center space-x-2">
                    <svg aria-hidden="true" focusable="false" class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path>
                    </svg>
                    <span>{{ __('Manage Guide Steps') }}</span>
                </a>
                
                <a href="{{ \App\Filament\Resources\WhySections\WhySectionResource::getUrl('index') }}" 
                   class="bg-wine-500 hover:bg-wine-600 text-white font-medium py-4 px-6 rounded-2xl transition-colors duration-200 text-center flex items-center justify-center space-x-2">
                    <svg aria-hidden="true" focusable="false" class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9.663 17h4.673M12 3v1m6.364 1.636l-.707.707M21 12h-1M4 12H3m3.343-5.657l-.707-.707m2.828 9.9a5 5 0 117.072 0l-.548.547A3.374 3.374 0 0014 18.469V19a2 2 0 11-4 0v-.531c0-.895-.356-1.754-.988-2.386l-.548-.547z"></path>
                    </svg>
                    <span>{{ __('Manage Why Section') }}</span>
                </a>
            </div>
        </div>
        
        <!-- Recent Activity -->
        <div class="card">
            <h3 class="text-xl font-bold text-gray-800 mb-6">{{ __('Recent Activity') }}</h3>
            <div class="space-y-4">
                <div class="flex items-start space-x-3">
                    <div class="w-8 h-8 bg-wine-500 rounded-full flex items-center justify-center flex-shrink-0">
                        <svg aria-hidden="true" focusable="false" class="w-4 h-4 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                        </svg>
                    </div>
                    <div>
                        <p class="text-gray-800 font-medium">{{ __('Welcome to your admin dashboard!') }}</p>
                        <p class="text-gray-600 text-sm">{{ __('Use the quick actions to manage your trips and media content.') }}</p>
                    </div>
                </div>
                
                <div class="flex items-start space-x-3">
                    <div class="w-8 h-8 bg-gold-500 rounded-full flex items-center justify-center flex-shrink-0">
                        <svg aria-hidden="true" focusable="false" class="w-4 h-4 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                        </svg>
                    </div>
                    <div>
                        <p class="text-gray-800 font-medium">{{ __('Quick Access') }}</p>
                        <p class="text-gray-600 text-sm">{{ __('All your admin tools are just one click away.') }}</p>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
@endsection
