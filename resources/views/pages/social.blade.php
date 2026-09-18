@extends('layouts.app')

@section('content')
<div class="container mx-auto px-4 py-8">
    <h1 class="text-4xl font-bold text-center mb-12 text-gray-800">{{ __('section_memories') }}</h1>
    
    <!-- Social Media Buttons -->
    <div class="max-w-4xl mx-auto mb-16">
        <div class="text-center mb-12">
            <h2 class="text-2xl font-bold text-gray-800 mb-4">{{ __('Connect With Us') }}</h2>
            <p class="text-gray-600 max-w-2xl mx-auto">
                {{ __('social_follow') }}
            </p>
        </div>
        
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
            @if($socialSettings['facebook_url'])
            <a href="{{ $socialSettings['facebook_url'] }}" target="_blank" class="group">
                <div class="card hover:shadow-lg transition-all duration-300 hover:-translate-y-1 text-center">
                    <div class="w-16 h-16 bg-wine-500 rounded-full flex items-center justify-center mx-auto mb-4 group-hover:scale-110 transition-transform duration-300">
                        <svg class="w-8 h-8 text-white" fill="currentColor" viewBox="0 0 24 24">
                            <path d="M24 12.073c0-6.627-5.373-12-12-12s-12 5.373-12 12c0 5.99 4.388 10.954 10.125 11.854v-8.385H7.078v-3.47h3.047V9.43c0-3.007 1.792-4.669 4.533-4.669 1.312 0 2.686.235 2.686.235v2.953H15.83c-1.491 0-1.956.925-1.956 1.874v2.25h3.328l-.532 3.47h-2.796v8.385C19.612 23.027 24 18.062 24 12.073z"/>
                        </svg>
                    </div>
                    <h3 class="text-xl font-semibold text-gray-800 mb-2">{{ __('Facebook') }}</h3>
                    <p class="text-gray-600">{{ __('Follow us on Facebook') }}</p>
                </div>
            </a>
            @endif
            
            @if($socialSettings['instagram_url'])
            <a href="{{ $socialSettings['instagram_url'] }}" target="_blank" class="group">
                <div class="card hover:shadow-lg transition-all duration-300 hover:-translate-y-1 text-center">
                    <div class="w-16 h-16 bg-gradient-to-br from-purple-500 to-pink-500 rounded-full flex items-center justify-center mx-auto mb-4 group-hover:scale-110 transition-transform duration-300">
                        <svg class="w-8 h-8 text-white" fill="currentColor" viewBox="0 0 24 24">
                            <path d="M12.017 0C5.396 0 .029 5.367.029 11.987c0 6.62 5.367 11.987 11.988 11.987 6.62 0 11.987-5.367 11.987-11.987C24.014 5.367 18.637.001 12.017.001zM8.449 16.988c-1.297 0-2.448-.49-3.323-1.297C4.198 14.895 3.708 13.744 3.708 12.447s.49-2.448 1.297-3.323c.875-.807 2.026-1.297 3.323-1.297s2.448.49 3.323 1.297c.807.875 1.297 2.026 1.297 3.323s-.49 2.448-1.297 3.323c-.875.807-2.026 1.297-3.323 1.297zm7.718-1.297c-.49.49-1.078.807-1.766.807-.688 0-1.276-.317-1.766-.807-.49-.49-.807-1.078-.807-1.766s.317-1.276.807-1.766c.49-.49 1.078-.807 1.766-.807.688 0 1.276.317 1.766.807.49.49.807 1.078.807 1.766s-.317 1.276-.807 1.766zm-7.718-6.62c-1.078 0-1.766.688-1.766 1.766s.688 1.766 1.766 1.766 1.766-.688 1.766-1.766-.688-1.766-1.766-1.766z"/>
                        </svg>
                    </div>
                    <h3 class="text-xl font-semibold text-gray-800 mb-2">{{ __('Instagram') }}</h3>
                    <p class="text-gray-600">{{ __('Follow us on Instagram') }}</p>
                </div>
            </a>
            @endif
            
            @if($socialSettings['tiktok_url'])
            <a href="{{ $socialSettings['tiktok_url'] }}" target="_blank" class="group">
                <div class="card hover:shadow-lg transition-all duration-300 hover:-translate-y-1 text-center">
                    <div class="w-16 h-16 bg-black rounded-full flex items-center justify-center mx-auto mb-4 group-hover:scale-110 transition-transform duration-300">
                        <svg class="w-8 h-8 text-white" fill="currentColor" viewBox="0 0 24 24">
                            <path d="M12.525.02c1.31-.02 2.61-.01 3.91-.02.08 1.53.63 3.09 1.75 4.17 1.12 1.11 2.7 1.62 4.24 1.79v4.03c-1.44-.05-2.89-.35-4.2-.97-.57-.26-1.1-.59-1.62-.93-.01 2.92.01 5.84-.02 8.75-.08 1.4-.54 2.79-1.35 3.94-1.31 1.92-3.58 3.17-5.91 3.21-1.43.08-2.86-.31-4.08-1.03-2.02-1.19-3.44-3.37-3.65-5.71-.02-.5-.03-1-.01-1.49.18-1.9 1.12-3.72 2.58-4.96 1.66-1.44 3.98-2.13 6.15-1.72.02 1.48-.04 2.96-.04 4.44-.99-.32-2.15-.23-3.02.37-.63.41-1.11 1.04-1.36 1.75-.21.51-.15 1.07-.14 1.61.24 1.64 1.82 3.02 3.5 2.87 1.12-.01 2.19-.66 2.77-1.61.19-.33.4-.67.41-1.06.1-1.79.06-3.57.07-5.36.01-4.03-.01-8.05.02-12.07z"/>
                        </svg>
                    </div>
                    <h3 class="text-xl font-semibold text-gray-800 mb-2">{{ __('TikTok') }}</h3>
                    <p class="text-gray-600">{{ __('Follow us on TikTok') }}</p>
                </div>
            </a>
            @endif
            
            @if($socialSettings['viber_url'])
            <a href="{{ $socialSettings['viber_url'] }}" target="_blank" class="group">
                <div class="card hover:shadow-lg transition-all duration-300 hover:-translate-y-1 text-center">
                    <div class="w-16 h-16 bg-purple-600 rounded-full flex items-center justify-center mx-auto mb-4 group-hover:scale-110 transition-transform duration-300">
                        <svg class="w-8 h-8 text-white" fill="currentColor" viewBox="0 0 24 24">
                            <path d="M23.155 12.17c0-6.68-5.475-12.155-12.155-12.155S-1.155 5.49-1.155 12.17C-1.155 18.85 5.475 24.325 12.155 24.325c6.68 0 12.155-5.475 12.155-12.155zm-5.568 5.568c-.425.425-1.105.425-1.53 0l-3.105-3.105c-.425-.425-.425-1.105 0-1.53.425-.425 1.105-.425 1.53 0l3.105 3.105c.425.425.425 1.105 0 1.53z"/>
                        </svg>
                    </div>
                    <h3 class="text-xl font-semibold text-gray-800 mb-2">{{ __('Viber') }}</h3>
                    <p class="text-gray-600">{{ __('Connect on Viber') }}</p>
                </div>
            </a>
            @endif
            
            <!-- WhatsApp Button -->
            <a href="https://wa.me/{{ $socialSettings['whatsapp_number'] ?? '9607972434' }}" target="_blank" class="group">
                <div class="card hover:shadow-lg transition-all duration-300 hover:-translate-y-1 text-center">
                    <div class="w-16 h-16 bg-wine-500 rounded-full flex items-center justify-center mx-auto mb-4 group-hover:scale-110 transition-transform duration-300">
                        <svg class="w-8 h-8 text-white" fill="currentColor" viewBox="0 0 24 24">
                            <path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347m-5.421 7.403h-.004a9.87 9.87 0 01-5.031-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86 9.86 0 01-1.51-5.26c.001-5.45 4.436-9.884 9.888-9.884 2.64 0 5.122 1.03 6.988 2.898a9.825 9.825 0 012.893 6.994c-.003 5.45-4.437 9.884-9.885 9.884m8.413-18.297A11.815 11.815 0 0012.05 0C5.495 0 .16 5.335.157 11.892c0 2.096.547 4.142 1.588 5.945L.057 24l6.305-1.654a11.882 11.882 0 005.683 1.448h.005c6.554 0 11.89-5.335 11.893-11.893A11.821 11.821 0 0020.885 3.488"/>
                        </svg>
                    </div>
                    <h3 class="text-xl font-semibold text-gray-800 mb-2">{{ __('WhatsApp') }}</h3>
                    <p class="text-gray-600">{{ __('Message us directly') }}</p>
                </div>
            </a>
        </div>
    </div>
    
    <!-- YouTube Playlist -->
    @if($socialSettings['youtube_playlist_id'])
    <div class="max-w-4xl mx-auto">
        <div class="text-center mb-8">
            <h2 class="text-2xl font-bold text-gray-800 mb-4">{{ __('Our Videos') }}</h2>
            <p class="text-gray-600">{{ __('Watch our latest videos and trip highlights on YouTube.') }}</p>
        </div>
        
        <div class="card">
            <div class="aspect-video rounded-2xl overflow-hidden">
                <iframe 
                    src="https://www.youtube.com/embed/videoseries?list={{ $socialSettings['youtube_playlist_id'] }}" 
                    title="Rihla Travels Videos"
                    frameborder="0" 
                    allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture" 
                    allowfullscreen
                    class="w-full h-full">
                </iframe>
            </div>
        </div>
    </div>
    @endif
    
    <!-- CTA Section -->
    <div class="max-w-4xl mx-auto mt-16 text-center">
        <div class="card">
            <h3 class="text-2xl font-bold text-gray-800 mb-4">{{ __('Stay Connected') }}</h3>
            <p class="text-gray-600 mb-6">{{ __('Follow us on social media to get the latest updates, behind-the-scenes content, and exclusive offers.') }}</p>
            <a href="https://wa.me/{{ $socialSettings['whatsapp_number'] ?? '9607972434' }}" 
               target="_blank" 
               class="btn-primary">
                {{ __('Start a Conversation') }}
            </a>
        </div>
    </div>
</div>
@endsection
