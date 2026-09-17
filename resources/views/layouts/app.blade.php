<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" dir="{{ app()->getLocale() === 'dv' ? 'rtl' : 'ltr' }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">

    <title>{{ config('app.name', 'Rihla Travels') }} - @yield('title', 'Islamic Travel & Umrah Services')</title>
    <meta name="description" content="@yield('description', 'Rihla Travels offers Islamic travel services, Umrah packages, and spiritual journeys to Makkah and Madinah. Professional travel services for Muslims.')">
    <meta name="keywords" content="Umrah, Islamic travel, Makkah, Madinah, Muslim travel, spiritual journey, Rihla Travels, Maldives">
    <meta name="author" content="Rihla Travels">
    <meta name="robots" content="index, follow">

    <!-- Open Graph / Facebook -->
    <meta property="og:type" content="website">
    <meta property="og:url" content="{{ url()->current() }}">
    <meta property="og:title" content="@yield('og_title', config('app.name', 'Rihla Travels'))">
    <meta property="og:description" content="@yield('og_description', 'Islamic travel services, Umrah packages, and spiritual journeys to Makkah and Madinah.')">
    <meta property="og:image" content="@yield('og_image', asset('images/rihla-logo.png'))">
    <meta property="og:site_name" content="Rihla Travels">
    <meta property="og:locale" content="{{ str_replace('_', '-', app()->getLocale()) }}">

    <!-- Twitter -->
    <meta property="twitter:card" content="summary_large_image">
    <meta property="twitter:url" content="{{ url()->current() }}">
    <meta property="twitter:title" content="@yield('twitter_title', config('app.name', 'Rihla Travels'))">
    <meta property="twitter:description" content="@yield('twitter_description', 'Islamic travel services, Umrah packages, and spiritual journeys to Makkah and Madinah.')">
    <meta property="twitter:image" content="@yield('twitter_image', asset('images/rihla-logo.png'))">

    <!-- Canonical URL -->
    <link rel="canonical" href="{{ url()->current() }}">

    <!-- Favicon -->
    <link rel="icon" type="image/x-icon" href="{{ asset('favicon.ico') }}">
    <link rel="apple-touch-icon" sizes="180x180" href="{{ asset('apple-touch-icon.png') }}">
    <link rel="icon" type="image/png" sizes="32x32" href="{{ asset('favicon-32x32.png') }}">
    <link rel="icon" type="image/png" sizes="16x16" href="{{ asset('favicon-16x16.png') }}">

    <!-- Fonts -->
    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=inter:300,400,500,600,700" rel="stylesheet" />
    
    <!-- Dhivehi Fonts - Faruma (Maldives Native) + Premium Fallbacks -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    
    <!-- Primary: Faruma (Beautiful Dhivehi Font from Maldives) -->
    <link href="https://fonts.googleapis.com/css2?family=Faruma:wght@400&display=swap" rel="stylesheet">
    
    <!-- Alternative: Direct font loading -->
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Faruma:wght@400&display=swap');
    </style>
    
    <!-- Fallback 1: MV Waheed (Traditional Maldivian Font) -->
    <!-- Note: MV Waheed is a system font available on most Maldivian devices -->
    
    <!-- Fallback 2: Cairo (Modern Arabic Support) -->
    <link href="https://fonts.googleapis.com/css2?family=Cairo:wght@200;300;400;500;600;700;800;900&display=swap" rel="stylesheet">
    
    <!-- Fallback 3: Tajawal (Professional Arabic) -->
    <link href="https://fonts.googleapis.com/css2?family=Tajawal:wght@200;300;400;500;700;800;900&display=swap" rel="stylesheet">

    <!-- Scripts -->
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    
    <!-- Video Player Script -->
    <script src="{{ asset('js/video-player.js') }}"></script>
</head>
<body class="font-sans antialiased overflow-x-hidden">
    <!-- Skip to main content link for accessibility -->
    <a href="#main-content" class="sr-only focus:not-sr-only focus:absolute focus:top-4 focus:left-4 bg-brand-dark-grey text-white px-4 py-2 rounded-lg z-50">
        {{ __('Skip to main content') }}
    </a>

    {{-- TEMP auto-deploy proof on TEST only — remove after confirmation. Inline styles so it renders without an asset rebuild. --}}
    @if(str_contains((string) config('app.url'), 'test.rihla.mv'))
    <div id="auto-deploy-live-test" style="position:relative;z-index:9999;background:#7f1d1d;color:#fee2e2;text-align:center;padding:0.7rem 1rem;font:600 0.95rem/1.3 system-ui,sans-serif;">
        RED HOOK · CODE <span style="color:#fca5a5;">RED-HOOK-0917</span> · auto-deploy check {{ now()->toDateString() }}
    </div>
    @endif

    <div class="min-h-screen bg-gray-50 overflow-x-hidden">
        <!-- Topbar -->
        <div class="bg-brand-dark-grey text-white py-2 overflow-x-hidden">
            <div class="container mx-auto px-4 flex items-center justify-between">
                <div class="flex items-center gap-2">
                    <span class="text-sm font-medium">REG NO: C11452023</span>
                </div>
                <div class="flex items-center gap-2 md:gap-4">
                    <!-- Language Toggle -->
                    <div class="flex items-center gap-2">
                        <a href="{{ route('locale.switch', 'en') }}" 
                           class="text-sm hover:text-brand-gold transition-colors focus:outline-none focus:ring-2 focus:ring-brand-gold focus:ring-offset-2 focus:ring-offset-brand-dark-grey rounded {{ app()->getLocale() === 'en' ? 'text-brand-gold font-medium' : '' }}">
                            EN
                        </a>
                        <span class="text-gray-400">|</span>
                        <a href="{{ route('locale.switch', 'dv') }}" 
                           class="text-sm hover:text-brand-gold transition-colors focus:outline-none focus:ring-2 focus:ring-brand-gold focus:ring-offset-2 focus:ring-offset-brand-dark-grey rounded {{ app()->getLocale() === 'dv' ? 'text-brand-gold font-medium' : '' }}">
                            ދިވެހި
                        </a>
                    </div>

                    <!-- WhatsApp CTA - Always visible -->
                    <a href="https://wa.me/9607972434"
                       target="_blank"
                       rel="noopener"
                       class="bg-brand-gold hover:bg-amber-600 text-white text-sm px-2 md:px-4 py-2 rounded-lg transition-colors flex items-center gap-2 focus:outline-none focus:ring-2 focus:ring-brand-gold focus:ring-offset-2 focus:ring-offset-brand-dark-grey">
                        <svg class="w-4 h-4" fill="currentColor" viewBox="0 0 24 24">
                            <path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347m-5.421 7.403h-.004a9.87 9.87 0 01-5.031-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86 9.86 0 01-1.51-5.26c.001-5.45 4.436-9.884 9.888-9.884 2.64 0 5.122 1.03 6.988 2.898a9.825 9.825 0 012.893 6.994c-.003 5.45-4.437 9.884-9.885 9.884m8.413-18.297A11.815 11.815 0 0012.05 0C5.495 0 .16 5.335.157 11.892c0 2.096.547 4.142 1.588 5.945L.057 24l6.305-1.654a11.882 11.882 0 005.683 1.448h.005c6.554 0 11.89-5.335 11.893-11.893A11.821 11.821 0 0020.885 3.488"/>
                        </svg>
                        <span class="hidden sm:inline">{{ __('Message us') }}</span>
                    </a>

                    <!-- Call CTA - Always visible -->
                    <a href="tel:9607972434"
                       class="bg-brand-sky-blue hover:bg-blue-600 text-white text-sm px-2 md:px-4 py-2 rounded-lg transition-colors flex items-center gap-2 focus:outline-none focus:ring-2 focus:ring-brand-sky-blue focus:ring-offset-2 focus:ring-offset-brand-dark-grey">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 5a2 2 0 012-2h3.28a1 1 0 01.948.684l1.498 4.493a1 1 0 01-.502 1.21l-2.257 1.13a11.042 11.042 0 005.516 5.516l1.13-2.257a1 1 0 011.21-.502l4.493 1.498a1 1 0 01.684.949V19a2 2 0 01-2 2h-1C9.716 21 3 14.284 3 6V5z"></path>
                        </svg>
                        <span class="hidden sm:inline">{{ __('Call us') }}</span>
                    </a>

                    <!-- WhatsApp Catalog CTA - Always visible -->
                    <a href="https://wa.me/c/9607972434"
                       target="_blank"
                       rel="noopener"
                       class="bg-brand-emerald hover:bg-emerald-700 text-white text-sm px-2 md:px-4 py-2 rounded-lg transition-colors flex items-center gap-2 focus:outline-none focus:ring-2 focus:ring-brand-emerald focus:ring-offset-2 focus:ring-offset-brand-dark-grey">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10"></path>
                        </svg>
                        <span class="hidden sm:inline">{{ __('Browse Catalog') }}</span>
                    </a>
                </div>
            </div>
        </div>

        <!-- Header -->
        <header class="bg-white shadow-sm overflow-x-hidden">
            <div class="container mx-auto px-4 py-6">
                <div class="flex items-center justify-between">
                    <!-- Logo -->
                    <div class="flex items-center">
                        <a href="{{ route('home') }}" class="flex items-center focus:outline-none focus:ring-2 focus:ring-brand-gold focus:ring-offset-2 rounded">
                            <!-- Logo Image -->
                            <img src="{{ asset('images/rihla-logo.png') }}" 
                                 alt="Rihla Travels Logo" 
                                 class="h-20 w-50">
                        </a>
                    </div>

                    <!-- Navigation -->
                    <nav class="hidden md:flex items-center gap-8" role="navigation" aria-label="Main navigation">
                        @if(Auth::check())
                            <!-- User is logged in - show admin navigation -->
                            <div class="flex items-center gap-6">
                                <a href="{{ route('admin.dashboard') }}" 
                                   class="text-brand-dark-grey hover:text-brand-sky-blue transition-colors font-medium focus:outline-none focus:ring-2 focus:ring-brand-sky-blue focus:ring-offset-2 rounded px-3 py-1">
                                    {{ __('Dashboard') }}
                                </a>
                                <a href="{{ route('admin.trips.index') }}" 
                                   class="text-brand-dark-grey hover:text-brand-sky-blue transition-colors font-medium focus:outline-none focus:ring-2 focus:ring-brand-sky-blue focus:ring-offset-2 rounded px-3 py-1">
                                    {{ __('Manage Trips') }}
                                </a>
                                <a href="{{ route('admin.media.index') }}" 
                                   class="text-brand-dark-grey hover:text-brand-sky-blue transition-colors font-medium focus:outline-none focus:ring-2 focus:ring-brand-sky-blue focus:ring-offset-2 rounded px-3 py-1">
                                    {{ __('Manage Media') }}
                                </a>
                                <a href="{{ route('admin.hero-banners.index') }}" 
                                   class="text-brand-dark-grey hover:text-brand-sky-blue transition-colors font-medium focus:outline-none focus:ring-2 focus:ring-brand-sky-blue focus:ring-offset-2 rounded px-3 py-1">
                                    {{ __('Hero Banners') }}
                                </a>
                                <a href="{{ route('admin.why-sections.index') }}" 
                                   class="text-brand-dark-grey hover:text-brand-sky-blue transition-colors font-medium focus:outline-none focus:ring-2 focus:ring-brand-sky-blue focus:ring-offset-2 rounded px-3 py-1">
                                    {{ __('Why Section') }}
                                </a>
                                <a href="{{ route('admin.guide-steps.index') }}" 
                                   class="text-brand-dark-grey hover:text-brand-sky-blue transition-colors font-medium focus:outline-none focus:ring-2 focus:ring-brand-sky-blue focus:ring-offset-2 rounded px-3 py-1">
                                    {{ __('Guide Steps') }}
                                </a>
                                <a href="{{ route('admin.settings.index') }}" 
                                   class="text-brand-dark-grey hover:text-brand-sky-blue transition-colors font-medium focus:outline-none focus:ring-2 focus:ring-brand-sky-blue focus:ring-offset-2 rounded px-3 py-1">
                                    {{ __('Settings') }}
                                </a>
                                <a href="{{ route('home') }}" 
                                   class="text-brand-dark-grey hover:text-brand-sky-blue transition-colors font-medium focus:outline-none focus:ring-2 focus:ring-brand-sky-blue focus:ring-offset-2 rounded px-3 py-1">
                                    {{ __('View Site') }}
                                </a>
                                <form method="POST" action="{{ route('logout') }}" class="inline">
                                    @csrf
                                    <button type="submit" 
                                            class="text-red-600 hover:text-red-700 transition-colors font-medium focus:outline-none focus:ring-2 focus:ring-red-500 focus:ring-offset-2 rounded px-3 py-1 border border-red-200 hover:border-red-300">
                                        {{ __('Logout') }}
                                    </button>
                                </form>
                            </div>
                        @else
                            <!-- User is not logged in - show regular website navigation -->
                            <a href="{{ route('trips.index') }}" 
                               class="text-brand-dark-grey hover:text-brand-sky-blue transition-colors font-medium focus:outline-none focus:ring-2 focus:ring-brand-sky-blue focus:ring-offset-2 rounded px-2 py-1">
                                {{ __('Trips') }}
                            </a>
                            <a href="{{ route('guide') }}" 
                               class="text-brand-dark-grey hover:text-brand-sky-blue transition-colors font-medium focus:outline-none focus:ring-2 focus:ring-brand-sky-blue focus:ring-offset-2 rounded px-2 py-1">
                                {{ __('Umrah Guide') }}
                            </a>
                            <a href="{{ route('gallery') }}" 
                               class="text-brand-dark-grey hover:text-brand-sky-blue transition-colors font-medium focus:outline-none focus:ring-2 focus:ring-brand-sky-blue focus:ring-offset-2 rounded px-2 py-1">
                                {{ __('Gallery') }}
                            </a>
                            <a href="{{ route('social') }}" 
                               class="text-brand-dark-grey hover:text-brand-sky-blue transition-colors font-medium focus:outline-none focus:ring-2 focus:ring-brand-sky-blue focus:ring-offset-2 rounded px-2 py-1">
                                {{ __('Social') }}
                            </a>
                            <a href="{{ route('contact') }}" 
                               class="text-brand-dark-grey hover:text-brand-sky-blue transition-colors font-medium focus:outline-none focus:ring-2 focus:ring-brand-sky-blue focus:ring-offset-2 rounded px-2 py-1">
                                {{ __('Contact') }}
                            </a>
                            <a href="{{ route('login') }}" 
                               class="text-brand-dark-grey hover:text-brand-sky-blue transition-colors font-medium cursor-pointer border border-transparent hover:border-brand-sky-blue px-3 py-1 rounded focus:outline-none focus:ring-2 focus:ring-brand-sky-blue focus:ring-offset-2">
                                {{ __('Login') }}
                            </a>
                        @endif
                    </nav>

                    <!-- Mobile menu button -->
                    <button type="button" 
                            class="md:hidden p-2 rounded-md text-brand-dark-grey hover:text-brand-sky-blue hover:bg-gray-100 focus:outline-none focus:ring-2 focus:ring-brand-sky-blue focus:ring-offset-2"
                            onclick="toggleMobileMenu()"
                            aria-label="Toggle mobile menu"
                            aria-expanded="false">
                        <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"></path>
                        </svg>
                    </button>
                </div>

                <!-- Mobile Navigation -->
                <div id="mobile-menu" class="md:hidden hidden mt-4 pb-4 border-t border-gray-200">
                    <div class="flex flex-col space-y-3 pt-4">
                        @if(Auth::check())
                            <!-- User is logged in - show admin navigation -->
                            <a href="{{ route('admin.dashboard') }}" 
                               class="text-left text-brand-dark-grey hover:text-brand-sky-blue transition-colors font-medium focus:outline-none focus:ring-2 focus:ring-brand-sky-blue focus:ring-offset-2 rounded px-2 py-2">
                                {{ __('Dashboard') }}
                            </a>
                            <a href="{{ route('admin.trips.index') }}" 
                               class="text-left text-brand-dark-grey hover:text-brand-sky-blue transition-colors font-medium focus:outline-none focus:ring-2 focus:ring-brand-sky-blue focus:ring-offset-2 rounded px-2 py-2">
                                {{ __('Manage Trips') }}
                            </a>
                            <a href="{{ route('admin.media.index') }}" 
                               class="text-left text-brand-dark-grey hover:text-brand-sky-blue transition-colors font-medium focus:outline-none focus:ring-2 focus:ring-brand-sky-blue focus:ring-offset-2 rounded px-2 py-2">
                                {{ __('Manage Media') }}
                            </a>
                            <a href="{{ route('admin.hero-banners.index') }}" 
                               class="text-left text-brand-dark-grey hover:text-brand-sky-blue transition-colors font-medium focus:outline-none focus:ring-2 focus:ring-brand-sky-blue focus:ring-offset-2 rounded px-2 py-2">
                                {{ __('Hero Banners') }}
                            </a>
                            <a href="{{ route('admin.guide-steps.index') }}" 
                               class="text-left text-brand-dark-grey hover:text-brand-sky-blue transition-colors font-medium focus:outline-none focus:ring-2 focus:ring-brand-sky-blue focus:ring-offset-2 rounded px-2 py-2">
                                {{ __('Guide Steps') }}
                            </a>
                            <a href="{{ route('admin.settings.index') }}" 
                               class="text-left text-brand-dark-grey hover:text-brand-sky-blue transition-colors font-medium focus:outline-none focus:ring-2 focus:ring-brand-sky-blue focus:ring-offset-2 rounded px-2 py-2">
                                {{ __('Settings') }}
                            </a>
                            <a href="{{ route('home') }}" 
                               class="text-left text-brand-dark-grey hover:text-brand-sky-blue transition-colors font-medium focus:outline-none focus:ring-2 focus:ring-brand-sky-blue focus:ring-offset-2 rounded px-2 py-2">
                                {{ __('View Site') }}
                            </a>
                            <form method="POST" action="{{ route('logout') }}" class="inline">
                                @csrf
                                <button type="submit" 
                                        class="w-full text-left text-red-600 hover:text-red-700 transition-colors font-medium focus:outline-none focus:ring-2 focus:ring-red-500 focus:ring-offset-2 rounded px-2 py-2 border border-red-200 hover:border-red-300">
                                    {{ __('Logout') }}
                                </button>
                            </form>
                        @else
                            <!-- User is not logged in - show regular website navigation -->
                            <a href="{{ route('trips.index') }}" 
                               class="text-left text-brand-dark-grey hover:text-brand-sky-blue transition-colors font-medium focus:outline-none focus:ring-2 focus:ring-brand-sky-blue focus:ring-offset-2 rounded px-2 py-2">
                                {{ __('Trips') }}
                            </a>
                            <a href="{{ route('guide') }}" 
                               class="text-left text-brand-dark-grey hover:text-brand-sky-blue transition-colors font-medium focus:outline-none focus:ring-2 focus:ring-brand-sky-blue focus:ring-offset-2 rounded px-2 py-2">
                                {{ __('Umrah Guide') }}
                            </a>
                            <a href="{{ route('gallery') }}" 
                               class="text-left text-brand-dark-grey hover:text-brand-sky-blue transition-colors font-medium focus:outline-none focus:ring-2 focus:ring-brand-sky-blue focus:ring-offset-2 rounded px-2 py-2">
                                {{ __('Gallery') }}
                            </a>
                            <a href="{{ route('social') }}" 
                               class="text-left text-brand-dark-grey hover:text-brand-sky-blue transition-colors font-medium focus:outline-none focus:ring-2 focus:ring-brand-sky-blue focus:ring-offset-2 rounded px-2 py-2">
                                {{ __('Social') }}
                            </a>
                            <a href="{{ route('contact') }}" 
                               class="text-left text-brand-dark-grey hover:text-brand-sky-blue transition-colors font-medium focus:outline-none focus:ring-2 focus:ring-brand-sky-blue focus:ring-offset-2 rounded px-2 py-2">
                                {{ __('Contact') }}
                            </a>
                            <a href="{{ route('login') }}" 
                               class="text-left text-brand-dark-grey hover:text-brand-sky-blue transition-colors font-medium cursor-pointer border border-transparent hover:border-brand-sky-blue px-2 py-2 rounded focus:outline-none focus:ring-2 focus:ring-brand-sky-blue focus:ring-offset-2">
                                {{ __('Login') }}
                            </a>
                        @endif
                        
                        <!-- Mobile CTA Buttons -->
                        <div class="flex flex-col space-y-2 pt-2">
                            <a href="https://wa.me/9607972434" target="_blank" 
                               class="bg-brand-gold hover:bg-amber-600 text-white text-sm px-4 py-2 rounded-lg transition-colors flex items-center justify-center gap-2 focus:outline-none focus:ring-2 focus:ring-brand-gold focus:ring-offset-2">
                                <svg class="w-4 h-4" fill="currentColor" viewBox="0 0 24 24">
                                    <path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347m-5.421 7.403h-.004a9.87 9.87 0 01-5.031-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86 9.86 0 01-1.51-5.26c.001-5.45 4.436-9.884 9.888-9.884 2.64 0 5.122 1.03 6.988 2.898a9.825 9.825 0 012.893 6.994c-.003 5.45-4.437 9.884-9.885 9.884m8.413-18.297A11.815 11.815 0 0012.05 0C5.495 0 .16 5.335.157 11.892c0 2.096.547 4.142 1.588 5.945L.057 24l6.305-1.654a11.882 11.882 0 005.683 1.448h.005c6.554 0 11.89-5.335 11.893-11.893A11.821 11.821 0 0020.885 3.488"/>
                                </svg>
                                <span>{{ __('Message us') }}</span>
                            </a>
                            <a href="tel:9607972434" 
                               class="bg-brand-sky-blue hover:bg-blue-600 text-white text-sm px-4 py-2 rounded-lg transition-colors flex items-center justify-center gap-2 focus:outline-none focus:ring-2 focus:ring-brand-sky-blue focus:ring-offset-2">
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 5a2 2 0 012-2h3.28a1 1 0 01.948.684l1.498 4.493a1 1 0 01-.502 1.21l-2.257 1.13a11.042 11.042 0 005.516 5.516l1.13-2.257a1 1 0 011.21-.502l4.493 1.498a1 1 0 01.684.949V19a2 2 0 01-2 2h-1C9.716 21 3 14.284 3 6V5z"></path>
                                </svg>
                                <span>{{ __('Call us') }}</span>
                            </a>
                            <a href="https://wa.me/c/9607972434" target="_blank" 
                               class="bg-brand-emerald hover:bg-emerald-700 text-white text-sm px-4 py-2 rounded-lg transition-colors flex items-center justify-center gap-2 focus:outline-none focus:ring-2 focus:ring-brand-emerald focus:ring-offset-2">
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10"></path>
                                </svg>
                                <span>{{ __('Browse Catalog') }}</span>
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        </header>

        <!-- Main Content -->
        <main id="main-content" class="flex-1" role="main">
            @yield('content')
        </main>

        <!-- Footer -->
        <footer class="bg-brand-dark-grey text-white py-12 overflow-x-hidden">
            <div class="container mx-auto px-4">
                <div class="grid grid-cols-1 md:grid-cols-4 gap-8">
                    <!-- Company Info -->
                    <div class="col-span-1 md:col-span-2">
                        <div class="flex items-center mb-4">
                            <img src="{{ asset('images/rihla-logo.png') }}" 
                                 alt="Rihla Travels Logo" 
                                 class="h-16 w-auto">
                        </div>
                        <p class="text-gray-300 mb-4 max-w-md">
                            {{ __('Rihla Travels provides exceptional Islamic travel services, specializing in Umrah packages and spiritual journeys to the holy cities of Makkah and Madinah.') }}
                        </p>
                        <div class="flex items-center gap-4">
                            <x-social-icons size="md" />
                        </div>
                    </div>

                    <!-- Quick Links -->
                    <div>
                        <h3 class="text-lg font-semibold mb-4 text-brand-gold">{{ __('Quick Links') }}</h3>
                        <ul class="space-y-2">
                            <li>
                                <a href="{{ route('trips.index') }}" 
                                   class="text-gray-300 hover:text-brand-gold transition-colors focus:outline-none focus:ring-2 focus:ring-brand-gold focus:ring-offset-2 focus:ring-offset-brand-dark-grey rounded px-1">
                                    {{ __('Our Trips') }}
                                </a>
                            </li>
                            <li>
                                <a href="{{ route('gallery') }}" 
                                   class="text-gray-300 hover:text-brand-gold transition-colors focus:outline-none focus:ring-2 focus:ring-brand-gold focus:ring-offset-2 focus:ring-offset-brand-dark-grey rounded px-1">
                                    {{ __('Gallery') }}
                                </a>
                            </li>
                            <li>
                                <a href="{{ route('contact') }}" 
                                   class="text-gray-300 hover:text-brand-gold transition-colors focus:outline-none focus:ring-2 focus:ring-brand-gold focus:ring-offset-2 focus:ring-offset-brand-dark-grey rounded px-1">
                                    {{ __('Contact Us') }}
                                </a>
                            </li>
                        </ul>
                    </div>

                    <!-- Contact Info -->
                    <div>
                        <h3 class="text-lg font-semibold mb-4 text-brand-gold">{{ __('Contact Info') }}</h3>
                        <div class="space-y-3">
                            <div class="flex items-start gap-3">
                                <svg class="w-5 h-5 text-brand-gold mt-0.5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z"></path>
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 11a3 3 0 11-6 0 3 3 0 016 0z"></path>
                                </svg>
                                <div>
                                    <p class="text-gray-300">{{ __('Malé, Maldives') }}</p>
                                    <p class="text-sm text-gray-400">{{ __('REG NO: C11452023') }}</p>
                                </div>
                            </div>
                            <div class="flex items-center gap-3">
                                <svg class="w-5 h-5 text-brand-gold flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 5a2 2 0 012-2h3.28a1 1 0 01.948.684l1.498 4.493a1 1 0 01-.502 1.21l-2.257 1.13a11.042 11.042 0 005.516 5.516l1.13-2.257a1 1 0 011.21-.502l4.493 1.498a1 1 0 01.684.949V19a2 2 0 01-2 2h-1C9.716 21 3 14.284 3 6V5z"></path>
                                </svg>
                                <a href="tel:9607972434" 
                                   class="text-gray-300 hover:text-brand-gold transition-colors focus:outline-none focus:ring-2 focus:ring-brand-gold focus:ring-offset-2 focus:ring-offset-brand-dark-grey rounded px-1">
                                    +960 797 2434
                                </a>
                            </div>
                            <div class="flex items-center gap-3">
                                <svg class="w-5 h-5 text-brand-gold flex-shrink-0" fill="currentColor" viewBox="0 0 24 24">
                                    <path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347m-5.421 7.403h-.004a9.87 9.87 0 01-5.031-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86 9.86 0 01-1.51-5.26c.001-5.45 4.436-9.884 9.888-9.884 2.64 0 5.122 1.03 6.988 2.898a9.825 9.825 0 012.893 6.994c-.003 5.45-4.437 9.884-9.885 9.884m8.413-18.297A11.815 11.815 0 0012.05 0C5.495 0 .16 5.335.157 11.892c0 2.096.547 4.142 1.588 5.945L.057 24l6.305-1.654a11.882 11.882 0 005.683 1.448h.005c6.554 0 11.89-5.335 11.893-11.893A11.821 11.821 0 0020.885 3.488"/>
                                </svg>
                                <a href="https://wa.me/9607972434" 
                                   target="_blank"
                                   rel="noopener"
                                   class="text-gray-300 hover:text-brand-gold transition-colors focus:outline-none focus:ring-2 focus:ring-brand-gold focus:ring-offset-2 focus:ring-offset-brand-dark-grey rounded px-1">
                                    {{ __('WhatsApp') }}
                                </a>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="border-t border-gray-700 mt-8 pt-8 text-center">
                    <p class="text-gray-600 mb-2">{{ __('footer_tagline') }}</p>
                    <p class="text-gray-400">
                        &copy; {{ date('Y') }} {{ config('app.name', 'Rihla Travels') }}. {{ __('All rights reserved.') }}
                    </p>
                </div>
            </div>
        </footer>
    </div>

    <!-- Floating WhatsApp FAB -->
    @include('components.whatsapp-fab')

    <!-- JavaScript -->
    <script>
        function toggleMobileMenu() {
            const menu = document.getElementById('mobile-menu');
            const button = document.querySelector('[onclick="toggleMobileMenu()"]');
            const isExpanded = button.getAttribute('aria-expanded') === 'true';
            
            menu.classList.toggle('hidden');
            button.setAttribute('aria-expanded', !isExpanded);
        }

        // Close mobile menu when clicking outside
        document.addEventListener('click', function(event) {
            const menu = document.getElementById('mobile-menu');
            const button = document.querySelector('[onclick="toggleMobileMenu()"]');
            
            if (!menu.contains(event.target) && !button.contains(event.target)) {
                menu.classList.add('hidden');
                button.setAttribute('aria-expanded', 'false');
            }
        });

        // Close mobile menu on escape key
        document.addEventListener('keydown', function(event) {
            if (event.key === 'Escape') {
                const menu = document.getElementById('mobile-menu');
                const button = document.querySelector('[onclick="toggleMobileMenu()"]');
                
                menu.classList.add('hidden');
                button.setAttribute('aria-expanded', 'false');
            }
        });
    </script>
</body>
</html>
