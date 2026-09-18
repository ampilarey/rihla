@extends('layouts.app')

@section('content')
<div class="container mx-auto px-4 py-8">
    <div class="max-w-4xl mx-auto">
        <h1 class="text-3xl font-bold text-gray-800 mb-8">{{ __('Manage Settings') }}</h1>

        @if(session('success'))
            <div class="bg-success/10 border border-success/40 text-success-dark px-4 py-3 rounded mb-6">
                {{ session('success') }}
            </div>
        @endif

        <div class="card">
            <form action="{{ route('admin.settings.update') }}" method="POST">
                @csrf
                
                <h2 class="text-xl font-semibold text-gray-800 mb-6">{{ __('Social Media Links') }}</h2>
                
                <div class="grid grid-cols-1 md:grid-cols-2 gap-6 mb-8">
                    <div>
                        <label for="facebook_url" class="block text-sm font-medium text-gray-700 mb-2">
                            {{ __('Facebook URL') }}
                        </label>
                        <input type="url" name="facebook_url" id="facebook_url" value="{{ $socialSettings['facebook_url'] ?? '' }}"
                               placeholder="https://facebook.com/rihlatravels"
                               class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-wine-500 focus:border-transparent">
                    </div>

                    <div>
                        <label for="instagram_url" class="block text-sm font-medium text-gray-700 mb-2">
                            {{ __('Instagram URL') }}
                        </label>
                        <input type="url" name="instagram_url" id="instagram_url" value="{{ $socialSettings['instagram_url'] ?? '' }}"
                               placeholder="https://instagram.com/rihlatravels"
                               class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-wine-500 focus:border-transparent">
                    </div>

                    <div>
                        <label for="tiktok_url" class="block text-sm font-medium text-gray-700 mb-2">
                            {{ __('TikTok URL') }}
                        </label>
                        <input type="url" name="tiktok_url" id="tiktok_url" value="{{ $socialSettings['tiktok_url'] ?? '' }}"
                               placeholder="https://tiktok.com/@rihlatravels"
                               class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-wine-500 focus:border-transparent">
                    </div>

                    <div>
                        <label for="viber_url" class="block text-sm font-medium text-gray-700 mb-2">
                            {{ __('Viber URL') }}
                        </label>
                        <input type="url" name="viber_url" id="viber_url" value="{{ $socialSettings['viber_url'] ?? '' }}"
                               placeholder="https://viber.com/rihlatravels"
                               class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-wine-500 focus:border-transparent">
                    </div>
                </div>

                <h2 class="text-xl font-semibold text-gray-800 mb-6">{{ __('Communication Settings') }}</h2>
                
                <div class="grid grid-cols-1 md:grid-cols-2 gap-6 mb-8">
                    <div>
                        <label for="whatsapp_number" class="block text-sm font-medium text-gray-700 mb-2">
                            {{ __('WhatsApp Number') }} *
                        </label>
                        <input type="text" name="whatsapp_number" id="whatsapp_number" value="{{ $socialSettings['whatsapp_number'] ?? '9607972434' }}" required
                               placeholder="9607972434"
                               class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-wine-500 focus:border-transparent">
                        {{-- The old hint said "without + or country code" while the
                             placeholder beside it showed 9607972434, which is the
                             country code followed by the number. Anyone who followed
                             the hint would have broken every WhatsApp link on the
                             site. --}}
                        <p class="text-sm text-gray-500 mt-1">{{ __('Digits only, including the country code — for example 9607972434.') }}</p>
                    </div>

                    <div>
                        <label for="youtube_playlist_id" class="block text-sm font-medium text-gray-700 mb-2">
                            {{ __('YouTube Playlist ID') }}
                        </label>
                        <input type="text" name="youtube_playlist_id" id="youtube_playlist_id" value="{{ $socialSettings['youtube_playlist_id'] ?? '' }}"
                               placeholder="PLxxxxxxxxxx"
                               class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-wine-500 focus:border-transparent">
                        <p class="text-sm text-gray-500 mt-1">{{ __('Found in YouTube playlist URL after "list="') }}</p>
                    </div>
                </div>

                <div class="border-t border-gray-200 pt-6">
                    <div class="flex justify-end">
                        <button type="submit" class="btn-primary">
                            {{ __('Save Settings') }}
                        </button>
                    </div>
                </div>
            </form>
        </div>

        <!-- Help Section -->
        <div class="card mt-8">
            <h3 class="text-lg font-semibold text-gray-800 mb-4">{{ __('How to Find These Values') }}</h3>
            <div class="space-y-4 text-sm text-gray-600">
                <div>
                    <strong>{{ __('Facebook URL:') }}</strong> {{ __('Go to your Facebook page and copy the URL from the address bar.') }}
                </div>
                <div>
                    <strong>{{ __('Instagram URL:') }}</strong> {{ __('Go to your Instagram profile and copy the URL from the address bar.') }}
                </div>
                <div>
                    <strong>{{ __('TikTok URL:') }}</strong> {{ __('Go to your TikTok profile and copy the URL from the address bar.') }}
                </div>
                <div>
                    <strong>{{ __('Viber URL:') }}</strong> {{ __('Go to your Viber profile and copy the URL from the address bar.') }}
                </div>
                <div>
                    <strong>{{ __('YouTube Playlist ID:') }}</strong> {{ __('In a YouTube playlist URL like "https://www.youtube.com/playlist?list=PLxxxxxxxxxx", the ID is "PLxxxxxxxxxx"') }}
                </div>
            </div>
        </div>
    </div>
</div>
@endsection
