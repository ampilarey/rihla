@extends('layouts.app')

@section('content')
<div class="container mx-auto px-4 py-8">
    <h1 class="text-4xl font-bold text-center mb-12 text-gray-800">{{ __('messages.join_next_title') }}</h1>
    
    <div class="max-w-6xl mx-auto">
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-12">
            <!-- Contact Information -->
            <div>
                <div class="card mb-8">
                    <h2 class="text-2xl font-bold text-gray-800 mb-6">{{ __('Get in Touch') }}</h2>
                    <p class="text-gray-600 mb-8">
                        {{ __('messages.contact_prompt') }}
                    </p>
                    
                    <div class="space-y-6">
                        <div class="flex items-start">
                            <div class="w-12 h-12 bg-wine-500 rounded-full flex items-center justify-center mr-4 flex-shrink-0">
                                <svg aria-hidden="true" focusable="false" class="w-6 h-6 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z"></path>
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 11a3 3 0 11-6 0 3 3 0 016 0z"></path>
                                </svg>
                            </div>
                            <div>
                                <h3 class="font-semibold text-gray-800 mb-1">{{ __('Location') }}</h3>
                                <p class="text-gray-600">📍 Malé, Maldives</p>
                            </div>
                        </div>
                        
                        <div class="flex items-start">
                            <div class="w-12 h-12 bg-wine-500 rounded-full flex items-center justify-center mr-4 flex-shrink-0">
                                <svg aria-hidden="true" focusable="false" class="w-6 h-6 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                                </svg>
                            </div>
                            <div>
                                <h3 class="font-semibold text-gray-800 mb-1">{{ __('Business Hours') }}</h3>
                                <p class="text-gray-600">{{ __('Monday - Friday: 9:00 AM - 6:00 PM') }}</p>
                                <p class="text-gray-600">{{ __('Saturday: 10:00 AM - 4:00 PM') }}</p>
                                <p class="text-gray-600">{{ __('Sunday: Closed') }}</p>
                            </div>
                        </div>
                        
                        <div class="flex items-start">
                            <div class="w-12 h-12 bg-wine-500 rounded-full flex items-center justify-center mr-4 flex-shrink-0">
                                <svg aria-hidden="true" focusable="false" class="w-6 h-6 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 8l7.89 4.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"></path>
                                </svg>
                            </div>
                            <div>
                                <h3 class="font-semibold text-gray-800 mb-1">{{ __('Email') }}</h3>
                                <a href="mailto:info@rihlatravels.mv" class="text-wine-500 hover:text-wine-600 transition-colors">
                                    info@rihlatravels.mv
                                </a>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Social Media Links -->
                <div class="card">
                    <h3 class="text-xl font-bold text-gray-800 mb-4">{{ __('Follow Us') }}</h3>
                    <div class="flex space-x-4">
                        @if($socialSettings['facebook_url'])
                        <a href="{{ $socialSettings['facebook_url'] }}" target="_blank" rel="noopener noreferrer" aria-label="Facebook" class="text-wine-500 hover:text-wine-600 transition-colors">
                            <svg aria-hidden="true" focusable="false" class="w-8 h-8" fill="currentColor" viewBox="0 0 24 24">
                                <path d="M24 12.073c0-6.627-5.373-12-12-12s-12 5.373-12 12c0 5.99 4.388 10.954 10.125 11.854v-8.385H7.078v-3.47h3.047V9.43c0-3.007 1.792-4.669 4.533-4.669 1.312 0 2.686.235 2.686.235v2.953H15.83c-1.491 0-1.956.925-1.956 1.874v2.25h3.328l-.532 3.47h-2.796v8.385C19.612 23.027 24 18.062 24 12.073z"/>
                            </svg>
                        </a>
                        @endif
                        
                        @if($socialSettings['instagram_url'])
                        <a href="{{ $socialSettings['instagram_url'] }}" target="_blank" rel="noopener noreferrer" aria-label="Instagram" class="text-purple-600 hover:text-purple-700 transition-colors">
                            <svg aria-hidden="true" focusable="false" class="w-8 h-8" fill="currentColor" viewBox="0 0 24 24">
                                <path d="M12.017 0C5.396 0 .029 5.367.029 11.987c0 6.62 5.367 11.987 11.988 11.987 6.62 0 11.987-5.367 11.987-11.987C24.014 5.367 18.637.001 12.017.001zM8.449 16.988c-1.297 0-2.448-.49-3.323-1.297C4.198 14.895 3.708 13.744 3.708 12.447s.49-2.448 1.297-3.323c.875-.807 2.026-1.297 3.323-1.297s2.448.49 3.323 1.297c.807.875 1.297 2.026 1.297 3.323s-.49 2.448-1.297 3.323c-.875.807-2.026 1.297-3.323 1.297zm7.718-1.297c-.49.49-1.078.807-1.766.807-.688 0-1.276-.317-1.766-.807-.49-.49-.807-1.078-.807-1.766s.317-1.276.807-1.766c.49-.49 1.078-.807 1.766-.807.688 0 1.276.317 1.766.807.49.49.807 1.078.807 1.766s-.317 1.276-.807 1.766zm-7.718-6.62c-1.078 0-1.766.688-1.766 1.766s.688 1.766 1.766 1.766 1.766-.688 1.766-1.766-.688-1.766-1.766-1.766z"/>
                            </svg>
                        </a>
                        @endif
                        
                        @if($socialSettings['tiktok_url'])
                        <a href="{{ $socialSettings['tiktok_url'] }}" target="_blank" rel="noopener noreferrer" aria-label="TikTok" class="text-black hover:text-gray-700 transition-colors">
                            <svg aria-hidden="true" focusable="false" class="w-8 h-8" fill="currentColor" viewBox="0 0 24 24">
                                <path d="M12.525.02c1.31-.02 2.61-.01 3.91-.02.08 1.53.63 3.09 1.75 4.17 1.12 1.11 2.7 1.62 4.24 1.79v4.03c-1.44-.05-2.89-.35-4.2-.97-.57-.26-1.1-.59-1.62-.93-.01 2.92.01 5.84-.02 8.75-.08 1.4-.54 2.79-1.35 3.94-1.31 1.92-3.58 3.17-5.91 3.21-1.43.08-2.86-.31-4.08-1.03-2.02-1.19-3.44-3.37-3.65-5.71-.02-.5-.03-1-.01-1.49.18-1.9 1.12-3.72 2.58-4.96 1.66-1.44 3.98-2.13 6.15-1.72.02 1.48-.04 2.96-.04 4.44-.99-.32-2.15-.23-3.02.37-.63.41-1.11 1.04-1.36 1.75-.21.51-.15 1.07-.14 1.61.24 1.64 1.82 3.02 3.5 2.87 1.12-.01 2.19-.66 2.77-1.61.19-.33.4-.67.41-1.06.1-1.79.06-3.57.07-5.36.01-4.03-.01-8.05.02-12.07z"/>
                            </svg>
                        </a>
                        @endif
                    </div>
                </div>
            </div>
            
            <!-- WhatsApp CTA -->
            <div class="text-center">
                <div class="card h-full flex flex-col justify-center">
                    <div class="w-24 h-24 bg-wine-500 rounded-full flex items-center justify-center mx-auto mb-6">
                        <svg aria-hidden="true" focusable="false" class="w-12 h-12 text-white" fill="currentColor" viewBox="0 0 24 24">
                            <path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347m-5.421 7.403h-.004a9.87 9.87 0 01-5.031-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86 9.86 0 01-1.51-5.26c.001-5.45 4.436-9.884 9.888-9.884 2.64 0 5.122 1.03 6.988 2.898a9.825 9.825 0 012.893 6.994c-.003 5.45-4.437 9.884-9.885 9.884m8.413-18.297A11.815 11.815 0 0012.05 0C5.495 0 .16 5.335.157 11.892c0 2.096.547 4.142 1.588 5.945L.057 24l6.305-1.654a11.882 11.882 0 005.683 1.448h.005c6.554 0 11.89-5.335 11.893-11.893A11.821 11.821 0 0020.885 3.488"/>
                        </svg>
                    </div>
                    
                    <h3 class="text-2xl font-bold text-gray-800 mb-4">{{ __('Quick Response') }}</h3>
                    <p class="text-gray-600 mb-6">
                        {{ __('Get instant answers to your questions and start planning your trip right away. We typically respond within minutes!') }}
                    </p>
                    
                    <a href="{{ \App\Support\Contact::whatsappUrl() }}" 
                       target="_blank" rel="noopener noreferrer" 
                       class="btn-primary text-lg px-8 py-4 w-full">
                        {{ __('Message on WhatsApp') }}
                    </a>
                    
                    <p class="text-sm text-gray-500 mt-4">
                        {{ __('WhatsApp Number:') }} {{ \App\Support\Contact::displayNumber() }}
                    </p>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Additional Info -->
    <div class="max-w-4xl mx-auto mt-16">
        <div class="card text-center">
            <h3 class="text-2xl font-bold text-gray-800 mb-4">{{ __('Why Choose Rihla Travels?') }}</h3>
            <div class="grid grid-cols-1 md:grid-cols-3 gap-8 mt-8">
                <div>
                    <div class="w-16 h-16 bg-wine-500 rounded-full flex items-center justify-center mx-auto mb-4">
                        <svg aria-hidden="true" focusable="false" class="w-8 h-8 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                        </svg>
                    </div>
                    <h4 class="font-semibold text-gray-800 mb-2">{{ __('Local Expertise') }}</h4>
                    <p class="text-gray-600">{{ __('Born and raised in the Maldives, we know the best spots and hidden gems.') }}</p>
                </div>
                
                <div>
                    <div class="w-16 h-16 bg-wine-500 rounded-full flex items-center justify-center mx-auto mb-4">
                        <svg aria-hidden="true" focusable="false" class="w-8 h-8 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4.318 6.318a4.5 4.5 0 000 6.364L12 20.364l7.682-7.682a4.5 4.5 0 00-6.364-6.364L12 7.636l-1.318-1.318a4.5 4.5 0 00-6.364 0z"></path>
                        </svg>
                    </div>
                    <h4 class="font-semibold text-gray-800 mb-2">{{ __('Personalized Service') }}</h4>
                    <p class="text-gray-600">{{ __('Every trip is customized to your preferences and travel style.') }}</p>
                </div>
                
                <div>
                    <div class="w-16 h-16 bg-wine-500 rounded-full flex items-center justify-center mx-auto mb-4">
                        <svg aria-hidden="true" focusable="false" class="w-8 h-8 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                        </svg>
                    </div>
                    <h4 class="font-semibold text-gray-800 mb-2">{{ __('24/7 Support') }}</h4>
                    <p class="text-gray-600">{{ __('We\'re here for you before, during, and after your trip.') }}</p>
                </div>
            </div>
        </div>
    </div>
</div>
@endsection
