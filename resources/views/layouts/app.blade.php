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
    <meta property="og:image" content="@yield('og_image', asset('images/rihla-social.png'))">
    <meta property="og:site_name" content="Rihla Travels">
    <meta property="og:locale" content="{{ str_replace('_', '-', app()->getLocale()) }}">

    <!-- Twitter -->
    <meta property="twitter:card" content="summary_large_image">
    <meta property="twitter:url" content="{{ url()->current() }}">
    <meta property="twitter:title" content="@yield('twitter_title', config('app.name', 'Rihla Travels'))">
    <meta property="twitter:description" content="@yield('twitter_description', 'Islamic travel services, Umrah packages, and spiritual journeys to Makkah and Madinah.')">
    <meta property="twitter:image" content="@yield('twitter_image', asset('images/rihla-social.png'))">

    <!-- Canonical URL -->
    <link rel="canonical" href="{{ url()->current() }}">

    {{-- Each language has its own URL, so tell crawlers they are translations
         of one page rather than duplicates competing with each other. Emitted
         only for locale-prefixed URLs; admin and auth pages have no alternate
         and claiming one would point at a page that does not exist. --}}
    @php($seoAlternates = \App\Support\Seo::alternates(request()))
    @if ($seoAlternates)
        @foreach ($seoAlternates as $seoLocale => $seoUrl)
            <link rel="alternate" hreflang="{{ $seoLocale }}" href="{{ $seoUrl }}">
        @endforeach
        <link rel="alternate" hreflang="x-default" href="{{ $seoAlternates['en'] }}">
    @endif

    <!-- Structured data -->
    <script type="application/ld+json">{!! \App\Support\Seo::json(\App\Support\Seo::organization()) !!}</script>
    @stack('schema')

    <!-- Favicon -->
    {{-- SVG first: browsers that understand it take it and stay sharp at any
         pixel density, and it is 589 bytes. The PNGs and the .ico below are
         the fallback for those that do not. --}}
    <link rel="icon" type="image/svg+xml" href="{{ asset('favicon.svg') }}">
    <link rel="icon" type="image/x-icon" href="{{ asset('favicon.ico') }}">
    <link rel="apple-touch-icon" sizes="180x180" href="{{ asset('apple-touch-icon.png') }}">
    <link rel="icon" type="image/png" sizes="32x32" href="{{ asset('favicon-32x32.png') }}">
    <link rel="icon" type="image/png" sizes="16x16" href="{{ asset('favicon-16x16.png') }}">

    {{-- Installable app. The manifest was never linked from any page, so the
         site could not be installed at all, and it declared a scope of /guide
         which would have covered one page of it. --}}
    <link rel="manifest" href="{{ asset('manifest.json') }}">
    <meta name="theme-color" content="#2E2245">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <meta name="apple-mobile-web-app-title" content="Rihla">

    <!-- Fonts -->
    {{-- Five stylesheet requests used to sit here. Two asked Google Fonts for
         "Faruma", which does not exist there and returned 400 every time — one
         of them from an @import inside a <style> block, which blocks rendering
         until it fails. Tajawal loaded seven weights that no view used. Cairo
         loaded nine for the same reason.

         What is left: Inter for Latin, self-hosted A_Faruma for Thaana, and
         Cairo in two weights, now actually applied to du'a text. --}}
    {{-- The wordmark face, 3.8 KB and on every page because the logo is.
         Preloaded so the lockup does not reflow when it arrives: Lighthouse
         gates cumulative layout shift as an error at 0.1, and a late webfont
         on nine letter-spaced letters is exactly what moves it. --}}
    <link rel="preload" href="{{ asset('fonts/montserrat-wordmark.woff2') }}" as="font" type="font/woff2" crossorigin>

    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=inter:300,400,500,600,700" rel="stylesheet">

    {{-- Arabic, for supplications. Two weights, not nine. --}}
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;700&display=swap">

    @if (app()->getLocale() === 'dv')
        {{-- Only on Dhivehi pages: an English page never renders a Thaana
             character, so the face's unicode-range means it would not be
             fetched anyway — preloading it there would be a wasted request. --}}
        <link rel="preload" href="{{ asset('fonts/A_faruma.woff2') }}" as="font" type="font/woff2" crossorigin>
    @endif

    <!-- Scripts -->
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    
    <!-- Video Player Script -->
    <script src="{{ asset('js/video-player.js') }}"></script>

    @stack('styles')
</head>
<body class="font-sans antialiased overflow-x-hidden">
    <!-- Skip to main content link for accessibility -->
    <a href="#main-content" class="sr-only focus:not-sr-only focus:absolute focus:top-4 focus:left-4 bg-ink text-white px-4 py-2 rounded-lg z-50">
        {{ __('Skip to main content') }}
    </a>

    {{-- Signed-in staff see eight navigation items; a visitor now sees seven.
         Eight need 1280px; seven need 1024. Sharing one breakpoint meant staff
         on anything narrower than 1280 lost links off the right edge of a
         container that clips rather than scrolls: no scrollbar, nothing to
         drag, the links simply gone.

         The visitor breakpoint was md until Packages became a seventh item,
         at which point Login sat past the right edge at exactly 768px —
         measured, not guessed, and the same failure this note was written
         about. NavigationFitTest measures it now, because a note is not a
         guard.

         Each class string is written out in full. Tailwind scans source text
         for literal class names, so building one by interpolating a variable
         into it compiles to no CSS at all - the same way w-50 did.

         Note for anyone editing this file: it uses the inline form of the raw
         PHP directive throughout, and mixing that with the block form breaks
         Blade's matching - the opener is left alone while the closer becomes a
         bare tag, and every page 500s. Keep to one form. For the same reason,
         do not write Blade directives inside a Blade comment; they compile. --}}
    @php($navDesktop = Auth::check() ? 'hidden xl:flex' : 'hidden lg:flex')
    @php($navToggle = Auth::check() ? 'xl:hidden' : 'lg:hidden')

    {{-- The customer call-to-actions and the floating WhatsApp buttons are for
         visitors. On the admin panel they are noise, and the floating stack
         sits on top of the dashboard's own cards.

         The Tour Leader Portal is the same case with teeth: the floating
         stack sat directly over the "Excused" button on one row of the head
         count, so a leader at a coach door would tap WhatsApp instead of
         marking somebody. Found by rendering the page at phone width. --}}
    @php($hidesVisitorCallsToAction = request()->routeIs('admin.*') || request()->routeIs('leader.*'))

    <div class="min-h-screen bg-gray-50 overflow-x-hidden">
        <!-- Topbar -->
        <div class="bg-ink text-white py-2 overflow-x-hidden">
            <div class="container mx-auto px-4 flex items-center justify-between">
                <div class="flex items-center gap-2">
                    <span class="text-sm font-medium">REG NO: C11452023</span>
                </div>
                <div class="flex items-center gap-2 md:gap-4">
                    <!-- Language Toggle -->
                    <div class="flex items-center gap-2">
                        <a href="{{ route('locale.switch', 'en') }}" 
                           class="text-sm hover:text-gold-500 transition-colors focus:outline-none focus:ring-2 focus:ring-gold-600 focus:ring-offset-2 focus:ring-offset-ink rounded {{ app()->getLocale() === 'en' ? 'text-gold-500 font-medium' : '' }}">
                            EN
                        </a>
                        <span class="text-gray-400">|</span>
                        <a href="{{ route('locale.switch', 'dv') }}" 
                           class="text-sm hover:text-gold-500 transition-colors focus:outline-none focus:ring-2 focus:ring-gold-600 focus:ring-offset-2 focus:ring-offset-ink rounded {{ app()->getLocale() === 'dv' ? 'text-gold-500 font-medium' : '' }}">
                            ދިވެހި
                        </a>
                    </div>

                    @unless ($hidesVisitorCallsToAction)
                        <!-- WhatsApp CTA - Always visible -->
                        <a href="{{ \App\Support\Contact::whatsappUrl() }}"
                           target="_blank"
                           rel="noopener"
                           aria-label="{{ __('Message us') }}"
                           class="bg-gold-500 hover:bg-gold-600 text-ink text-sm px-2 md:px-4 py-2 rounded-lg transition-colors flex items-center gap-2 focus:outline-none focus:ring-2 focus:ring-gold-600 focus:ring-offset-2 focus:ring-offset-ink">
                            <svg aria-hidden="true" focusable="false" class="w-4 h-4" fill="currentColor" viewBox="0 0 24 24">
                                <path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347m-5.421 7.403h-.004a9.87 9.87 0 01-5.031-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86 9.86 0 01-1.51-5.26c.001-5.45 4.436-9.884 9.888-9.884 2.64 0 5.122 1.03 6.988 2.898a9.825 9.825 0 012.893 6.994c-.003 5.45-4.437 9.884-9.885 9.884m8.413-18.297A11.815 11.815 0 0012.05 0C5.495 0 .16 5.335.157 11.892c0 2.096.547 4.142 1.588 5.945L.057 24l6.305-1.654a11.882 11.882 0 005.683 1.448h.005c6.554 0 11.89-5.335 11.893-11.893A11.821 11.821 0 0020.885 3.488"/>
                            </svg>
                            <span class="hidden sm:inline">{{ __('Message us') }}</span>
                        </a>

                        <!-- Call CTA - Always visible -->
                        <a href="{{ \App\Support\Contact::telUrl() }}"
                           aria-label="{{ __('Call us') }}"
                           class="bg-wine-500 hover:bg-wine-600 text-white text-sm px-2 md:px-4 py-2 rounded-lg transition-colors flex items-center gap-2 focus:outline-none focus:ring-2 focus:ring-wine-500 focus:ring-offset-2 focus:ring-offset-ink">
                            <svg aria-hidden="true" focusable="false" class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 5a2 2 0 012-2h3.28a1 1 0 01.948.684l1.498 4.493a1 1 0 01-.502 1.21l-2.257 1.13a11.042 11.042 0 005.516 5.516l1.13-2.257a1 1 0 011.21-.502l4.493 1.498a1 1 0 01.684.949V19a2 2 0 01-2 2h-1C9.716 21 3 14.284 3 6V5z"></path>
                            </svg>
                            <span class="hidden sm:inline">{{ __('Call us') }}</span>
                        </a>

@endunless
                </div>
            </div>
        </div>

        <!-- Header -->
        <header class="bg-white shadow-sm overflow-x-hidden">
            <div class="container mx-auto px-4 py-6">
                <div class="flex items-center justify-between">
                    <!-- Logo -->
                    <div class="flex items-center">
                        <a href="{{ route('home') }}" class="flex items-center focus:outline-none focus:ring-2 focus:ring-gold-600 focus:ring-offset-2 rounded">
                            <!-- Logo Image -->
                            {{-- Was `h-20 w-50`. Tailwind's spacing scale has no 50,
                                 so `w-50` compiled to nothing at all and the logo
                                 fell back to its intrinsic width. `w-auto` is what
                                 it was already doing, said out loud. --}}
                            <x-brand-logo class="w-20" />
                        </a>
                    </div>

                    <!-- Navigation -->
                    <nav class="{{ $navDesktop }} items-center gap-8" role="navigation" aria-label="Main navigation">
                        @if(Auth::check())
                            <!-- User is logged in - show admin navigation -->
                            <div class="flex items-center gap-6">
                                <a href="{{ route('admin.dashboard') }}" 
                                   class="text-ink hover:text-wine-500 transition-colors font-medium focus:outline-none focus:ring-2 focus:ring-wine-500 focus:ring-offset-2 rounded px-3 py-1">
                                    {{ __('Dashboard') }}
                                </a>
                                <a href="{{ route('admin.trips.index') }}" 
                                   class="text-ink hover:text-wine-500 transition-colors font-medium focus:outline-none focus:ring-2 focus:ring-wine-500 focus:ring-offset-2 rounded px-3 py-1">
                                    {{ __('Manage Trips') }}
                                </a>
                                <a href="{{ route('admin.media.index') }}" 
                                   class="text-ink hover:text-wine-500 transition-colors font-medium focus:outline-none focus:ring-2 focus:ring-wine-500 focus:ring-offset-2 rounded px-3 py-1">
                                    {{ __('Manage Media') }}
                                </a>
                                <a href="{{ route('admin.hero-banners.index') }}" 
                                   class="text-ink hover:text-wine-500 transition-colors font-medium focus:outline-none focus:ring-2 focus:ring-wine-500 focus:ring-offset-2 rounded px-3 py-1">
                                    {{ __('Hero Banners') }}
                                </a>
                                <a href="{{ route('admin.why-sections.index') }}" 
                                   class="text-ink hover:text-wine-500 transition-colors font-medium focus:outline-none focus:ring-2 focus:ring-wine-500 focus:ring-offset-2 rounded px-3 py-1">
                                    {{ __('Why Section') }}
                                </a>
                                <a href="{{ route('admin.guide-steps.index') }}" 
                                   class="text-ink hover:text-wine-500 transition-colors font-medium focus:outline-none focus:ring-2 focus:ring-wine-500 focus:ring-offset-2 rounded px-3 py-1">
                                    {{ __('Guide Steps') }}
                                </a>
                                <a href="{{ route('admin.settings.index') }}" 
                                   class="text-ink hover:text-wine-500 transition-colors font-medium focus:outline-none focus:ring-2 focus:ring-wine-500 focus:ring-offset-2 rounded px-3 py-1">
                                    {{ __('Settings') }}
                                </a>
                                {{-- The Filament panel. New modules are built there;
                                     these screens move across in Phase 3. --}}
                                <a href="{{ url(\App\Providers\Filament\StaffPanelProvider::PATH) }}"
                                   class="text-ink hover:text-wine-500 transition-colors font-medium focus:outline-none focus:ring-2 focus:ring-wine-500 focus:ring-offset-2 rounded px-3 py-1">
                                    {{ __('Staff') }}
                                </a>
                                <a href="{{ route('home') }}" 
                                   class="text-ink hover:text-wine-500 transition-colors font-medium focus:outline-none focus:ring-2 focus:ring-wine-500 focus:ring-offset-2 rounded px-3 py-1">
                                    {{ __('View Site') }}
                                </a>
                                <form method="POST" action="{{ route('logout') }}" class="inline">
                                    @csrf
                                    <button type="submit" 
                                            class="text-error hover:text-error-dark transition-colors font-medium focus:outline-none focus:ring-2 focus:ring-error focus:ring-offset-2 rounded px-3 py-1 border border-error/20 hover:border-error/40">
                                        {{ __('Logout') }}
                                    </button>
                                </form>
                            </div>
                        @else
                            <!-- User is not logged in - show regular website navigation -->
                            <x-site-nav-link href="{{ route('packages.index') }}" :active="request()->routeIs('packages.*')">
                                {{ __('Packages') }}
                            </x-site-nav-link>
                            <x-site-nav-link href="{{ route('trips.index') }}" :active="request()->routeIs('trips.*')">
                                {{ __('Trips') }}
                            </x-site-nav-link>
                            <x-site-nav-link href="{{ route('guide') }}" :active="request()->routeIs('guide')">
                                {{ __('Umrah Guide') }}
                            </x-site-nav-link>
                            <x-site-nav-link href="{{ route('gallery') }}" :active="request()->routeIs('gallery')">
                                {{ __('Gallery') }}
                            </x-site-nav-link>
                            <x-site-nav-link href="{{ route('social') }}" :active="request()->routeIs('social')">
                                {{ __('Social') }}
                            </x-site-nav-link>
                            <x-site-nav-link href="{{ route('contact') }}" :active="request()->routeIs('contact')">
                                {{ __('Contact') }}
                            </x-site-nav-link>
                            <a href="{{ route('login') }}" 
                               class="text-ink hover:text-wine-500 transition-colors font-medium cursor-pointer border border-transparent hover:border-wine-500 px-3 py-1 rounded focus:outline-none focus:ring-2 focus:ring-wine-500 focus:ring-offset-2">
                                {{ __('Login') }}
                            </a>
                        @endif
                    </nav>

                    <!-- Mobile menu button -->
                    <button type="button" 
                            class="{{ $navToggle }} p-2 rounded-md text-ink hover:text-wine-500 hover:bg-gray-100 focus:outline-none focus:ring-2 focus:ring-wine-500 focus:ring-offset-2"
                            data-click="toggleMobileMenu"
                            aria-label="{{ __('Menu') }}"
                            aria-controls="mobile-menu"
                            aria-expanded="false">
                        <svg aria-hidden="true" focusable="false" class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"></path>
                        </svg>
                    </button>
                </div>

                <!-- Mobile Navigation -->
                <div id="mobile-menu" class="{{ $navToggle }} hidden mt-4 pb-4 border-t border-gray-200">
                    <div class="flex flex-col space-y-3 pt-4">
                        @if(Auth::check())
                            <!-- User is logged in - show admin navigation -->
                            <a href="{{ route('admin.dashboard') }}" 
                               class="text-left text-ink hover:text-wine-500 transition-colors font-medium focus:outline-none focus:ring-2 focus:ring-wine-500 focus:ring-offset-2 rounded px-2 py-2">
                                {{ __('Dashboard') }}
                            </a>
                            <a href="{{ route('admin.trips.index') }}" 
                               class="text-left text-ink hover:text-wine-500 transition-colors font-medium focus:outline-none focus:ring-2 focus:ring-wine-500 focus:ring-offset-2 rounded px-2 py-2">
                                {{ __('Manage Trips') }}
                            </a>
                            <a href="{{ route('admin.media.index') }}" 
                               class="text-left text-ink hover:text-wine-500 transition-colors font-medium focus:outline-none focus:ring-2 focus:ring-wine-500 focus:ring-offset-2 rounded px-2 py-2">
                                {{ __('Manage Media') }}
                            </a>
                            <a href="{{ route('admin.hero-banners.index') }}" 
                               class="text-left text-ink hover:text-wine-500 transition-colors font-medium focus:outline-none focus:ring-2 focus:ring-wine-500 focus:ring-offset-2 rounded px-2 py-2">
                                {{ __('Hero Banners') }}
                            </a>
                            <a href="{{ route('admin.guide-steps.index') }}" 
                               class="text-left text-ink hover:text-wine-500 transition-colors font-medium focus:outline-none focus:ring-2 focus:ring-wine-500 focus:ring-offset-2 rounded px-2 py-2">
                                {{ __('Guide Steps') }}
                            </a>
                            <a href="{{ route('admin.settings.index') }}" 
                               class="text-left text-ink hover:text-wine-500 transition-colors font-medium focus:outline-none focus:ring-2 focus:ring-wine-500 focus:ring-offset-2 rounded px-2 py-2">
                                {{ __('Settings') }}
                            </a>
                            <a href="{{ url(\App\Providers\Filament\StaffPanelProvider::PATH) }}"
                               class="text-left text-ink hover:text-wine-500 transition-colors font-medium focus:outline-none focus:ring-2 focus:ring-wine-500 focus:ring-offset-2 rounded px-2 py-2">
                                {{ __('Staff') }}
                            </a>
                            <a href="{{ route('home') }}" 
                               class="text-left text-ink hover:text-wine-500 transition-colors font-medium focus:outline-none focus:ring-2 focus:ring-wine-500 focus:ring-offset-2 rounded px-2 py-2">
                                {{ __('View Site') }}
                            </a>
                            <form method="POST" action="{{ route('logout') }}" class="inline">
                                @csrf
                                <button type="submit" 
                                        class="w-full text-left text-error hover:text-error-dark transition-colors font-medium focus:outline-none focus:ring-2 focus:ring-error focus:ring-offset-2 rounded px-2 py-2 border border-error/20 hover:border-error/40">
                                    {{ __('Logout') }}
                                </button>
                            </form>
                        @else
                            <!-- User is not logged in - show regular website navigation -->
                            <a href="{{ route('packages.index') }}"
                               class="text-left text-ink hover:text-wine-500 transition-colors font-medium focus:outline-none focus:ring-2 focus:ring-wine-500 focus:ring-offset-2 rounded px-2 py-2">
                                {{ __('Packages') }}
                            </a>
                            <a href="{{ route('trips.index') }}" 
                               class="text-left text-ink hover:text-wine-500 transition-colors font-medium focus:outline-none focus:ring-2 focus:ring-wine-500 focus:ring-offset-2 rounded px-2 py-2">
                                {{ __('Trips') }}
                            </a>
                            <a href="{{ route('guide') }}" 
                               class="text-left text-ink hover:text-wine-500 transition-colors font-medium focus:outline-none focus:ring-2 focus:ring-wine-500 focus:ring-offset-2 rounded px-2 py-2">
                                {{ __('Umrah Guide') }}
                            </a>
                            <a href="{{ route('gallery') }}" 
                               class="text-left text-ink hover:text-wine-500 transition-colors font-medium focus:outline-none focus:ring-2 focus:ring-wine-500 focus:ring-offset-2 rounded px-2 py-2">
                                {{ __('Gallery') }}
                            </a>
                            <a href="{{ route('social') }}" 
                               class="text-left text-ink hover:text-wine-500 transition-colors font-medium focus:outline-none focus:ring-2 focus:ring-wine-500 focus:ring-offset-2 rounded px-2 py-2">
                                {{ __('Social') }}
                            </a>
                            <a href="{{ route('contact') }}" 
                               class="text-left text-ink hover:text-wine-500 transition-colors font-medium focus:outline-none focus:ring-2 focus:ring-wine-500 focus:ring-offset-2 rounded px-2 py-2">
                                {{ __('Contact') }}
                            </a>
                            <a href="{{ route('login') }}" 
                               class="text-left text-ink hover:text-wine-500 transition-colors font-medium cursor-pointer border border-transparent hover:border-wine-500 px-2 py-2 rounded focus:outline-none focus:ring-2 focus:ring-wine-500 focus:ring-offset-2">
                                {{ __('Login') }}
                            </a>
                        @endif
                        
                        @unless ($hidesVisitorCallsToAction)
                            <!-- Mobile CTA Buttons -->
                            <div class="flex flex-col space-y-2 pt-2">
                                <a href="{{ \App\Support\Contact::whatsappUrl() }}" target="_blank" rel="noopener"
                                   class="bg-gold-500 hover:bg-gold-600 text-ink text-sm px-4 py-2 rounded-lg transition-colors flex items-center justify-center gap-2 focus:outline-none focus:ring-2 focus:ring-gold-600 focus:ring-offset-2">
                                    <svg aria-hidden="true" focusable="false" class="w-4 h-4" fill="currentColor" viewBox="0 0 24 24">
                                        <path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347m-5.421 7.403h-.004a9.87 9.87 0 01-5.031-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86 9.86 0 01-1.51-5.26c.001-5.45 4.436-9.884 9.888-9.884 2.64 0 5.122 1.03 6.988 2.898a9.825 9.825 0 012.893 6.994c-.003 5.45-4.437 9.884-9.885 9.884m8.413-18.297A11.815 11.815 0 0012.05 0C5.495 0 .16 5.335.157 11.892c0 2.096.547 4.142 1.588 5.945L.057 24l6.305-1.654a11.882 11.882 0 005.683 1.448h.005c6.554 0 11.89-5.335 11.893-11.893A11.821 11.821 0 0020.885 3.488"/>
                                    </svg>
                                    <span>{{ __('Message us') }}</span>
                                </a>
                                <a href="{{ \App\Support\Contact::telUrl() }}"
                                   class="bg-wine-500 hover:bg-wine-600 text-white text-sm px-4 py-2 rounded-lg transition-colors flex items-center justify-center gap-2 focus:outline-none focus:ring-2 focus:ring-wine-500 focus:ring-offset-2">
                                    <svg aria-hidden="true" focusable="false" class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 5a2 2 0 012-2h3.28a1 1 0 01.948.684l1.498 4.493a1 1 0 01-.502 1.21l-2.257 1.13a11.042 11.042 0 005.516 5.516l1.13-2.257a1 1 0 011.21-.502l4.493 1.498a1 1 0 01.684.949V19a2 2 0 01-2 2h-1C9.716 21 3 14.284 3 6V5z"></path>
                                    </svg>
                                    <span>{{ __('Call us') }}</span>
                                </a>
                                </div>
                        @endunless
                    </div>
                </div>
            </div>
        </header>

        <!-- Main Content -->
        <main id="main-content" class="flex-1" role="main">
            {{-- Flashed messages, rendered in one place for every page.

                 Nothing rendered `session('status')` outside the Breeze auth
                 views, so two redirects that flash it said nothing at all to
                 the person who triggered them: joining the waiting list, and
                 being told that a lapsed seat hold had released your seats.
                 Both looked like the form had done nothing. Found by
                 submitting the form and reading the page that came back —
                 the tests asserted the session key, which passes while the
                 visitor sees silence. --}}
            @if(session('status'))
                <div class="container mx-auto px-4 pt-6" role="status" aria-live="polite">
                    <p dir="auto" class="rounded-xl border border-wine-500 bg-wine-50 p-4 text-ink">
                        {{ session('status') }}
                    </p>
                </div>
            @endif

            {{-- Most views @extends this layout and fill @section('content').
                 The Breeze views (dashboard, profile) render it as
                 <x-app-layout> and pass their body as $slot, which nothing
                 echoed, so those pages came out blank. Both are supported. --}}
            @yield('content')

            @isset($header)
                <div class="bg-white shadow">
                    <div class="max-w-7xl mx-auto py-6 px-4 sm:px-6 lg:px-8">
                        {{ $header }}
                    </div>
                </div>
            @endisset

            {{ $slot ?? '' }}
        </main>

        <!-- Footer -->
        <footer class="bg-ink text-white py-12 pb-52 md:pb-12 overflow-x-hidden">
            <div class="container mx-auto px-4">
                <div class="grid grid-cols-1 md:grid-cols-4 gap-8">
                    <!-- Company Info -->
                    <div class="col-span-1 md:col-span-2">
                        <div class="flex items-center mb-4">
                            <x-brand-logo class="w-16" loading="lazy" on="dark" />
                        </div>
                        {{-- dir="auto" because this sentence has no Dhivehi
                             translation and falls back to English. Inside the
                             RTL footer the browser gave it the paragraph's
                             own direction, and bidi rule N1 dragged the final
                             full stop to the front: "and Madinah." rendered
                             as ".and Madinah". dir="auto" makes the browser
                             judge by the first strong character, so the
                             English reads as English and a real Dhivehi
                             translation would still read as Dhivehi. --}}
                        <p dir="auto" class="text-gray-300 mb-4 max-w-md">
                            {{ __('Rihla Travels provides exceptional Islamic travel services, specializing in Umrah packages and spiritual journeys to the holy cities of Makkah and Madinah.') }}
                        </p>
                        <div class="flex items-center gap-4">
                            <x-social-icons size="md" />
                        </div>
                    </div>

                    <!-- Quick Links -->
                    <div>
                        {{-- h2, not h3. These are top-level sections of the
                             footer and sit under no h2 of their own, so an h3
                             here skips a level. On a page whose content has no
                             h2 at all — the packages list is one — a screen
                             reader navigating by heading goes straight from the
                             page's h1 to an h3 and is told a section is missing.
                             Lighthouse flags it as `heading-order`; the visual
                             size is set by the class, not the tag. --}}
                        <h2 class="text-lg font-semibold mb-4 text-gold-500">{{ __('Quick Links') }}</h2>
                        <ul class="space-y-2">
                            <li>
                                <a href="{{ route('packages.index') }}"
                                   class="text-gray-300 hover:text-gold-500 transition-colors focus:outline-none focus:ring-2 focus:ring-gold-600 focus:ring-offset-2 focus:ring-offset-ink rounded px-1">
                                    {{ __('Packages') }}
                                </a>
                            </li>
                            <li>
                                <a href="{{ route('trips.index') }}" 
                                   class="text-gray-300 hover:text-gold-500 transition-colors focus:outline-none focus:ring-2 focus:ring-gold-600 focus:ring-offset-2 focus:ring-offset-ink rounded px-1">
                                    {{ __('Our Trips') }}
                                </a>
                            </li>
                            {{-- In the footer rather than the header: the
                                 desktop nav is at seven links and an eighth
                                 would need the xl breakpoint, pushing every
                                 visitor on a laptop onto the hamburger to
                                 gain one page. --}}
                            <li>
                                <a href="{{ route('people.index') }}"
                                   class="text-gray-300 hover:text-gold-500 transition-colors focus:outline-none focus:ring-2 focus:ring-gold-600 focus:ring-offset-2 focus:ring-offset-ink rounded px-1">
                                    {{ __('messages.Who travels with you') }}
                                </a>
                            </li>
                            {{-- Footer, again: the desktop nav is full at
                                 seven. See NavigationFitTest. --}}
                            <li>
                                <a href="{{ route('articles.index') }}"
                                   class="text-gray-300 hover:text-gold-500 transition-colors focus:outline-none focus:ring-2 focus:ring-gold-600 focus:ring-offset-2 focus:ring-offset-ink rounded px-1">
                                    {{ __('messages.Articles') }}
                                </a>
                            </li>
                            <li>
                                <a href="{{ route('gallery') }}" 
                                   class="text-gray-300 hover:text-gold-500 transition-colors focus:outline-none focus:ring-2 focus:ring-gold-600 focus:ring-offset-2 focus:ring-offset-ink rounded px-1">
                                    {{ __('Gallery') }}
                                </a>
                            </li>
                            <li>
                                <a href="{{ route('contact') }}" 
                                   class="text-gray-300 hover:text-gold-500 transition-colors focus:outline-none focus:ring-2 focus:ring-gold-600 focus:ring-offset-2 focus:ring-offset-ink rounded px-1">
                                    {{ __('Contact Us') }}
                                </a>
                            </li>
                        </ul>
                    </div>

                    <!-- Contact Info -->
                    <div>
                        <h2 class="text-lg font-semibold mb-4 text-gold-500">{{ __('Contact Info') }}</h2>
                        <div class="space-y-3">
                            <div class="flex items-start gap-3">
                                <svg aria-hidden="true" focusable="false" class="w-5 h-5 text-gold-500 mt-0.5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z"></path>
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 11a3 3 0 11-6 0 3 3 0 016 0z"></path>
                                </svg>
                                <div>
                                    <p class="text-gray-300">{{ __('Malé, Maldives') }}</p>
                                    {{-- A registration number is Latin in
                                         both languages, and the colon is a
                                         neutral that bidi moves. --}}
                                    <p dir="ltr" class="text-sm text-gray-400">{{ __('REG NO: C11452023') }}</p>
                                </div>
                            </div>
                            <div class="flex items-center gap-3">
                                <svg aria-hidden="true" focusable="false" class="w-5 h-5 text-gold-500 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 5a2 2 0 012-2h3.28a1 1 0 01.948.684l1.498 4.493a1 1 0 01-.502 1.21l-2.257 1.13a11.042 11.042 0 005.516 5.516l1.13-2.257a1 1 0 011.21-.502l4.493 1.498a1 1 0 01.684.949V19a2 2 0 01-2 2h-1C9.716 21 3 14.284 3 6V5z"></path>
                                </svg>
                                <a href="{{ \App\Support\Contact::telUrl() }}"
                                   class="text-gray-300 hover:text-gold-500 transition-colors focus:outline-none focus:ring-2 focus:ring-gold-600 focus:ring-offset-2 focus:ring-offset-ink rounded px-1">
                                    {{ \App\Support\Contact::displayNumber() }}
                                </a>
                            </div>
                            <div class="flex items-center gap-3">
                                <svg aria-hidden="true" focusable="false" class="w-5 h-5 text-gold-500 flex-shrink-0" fill="currentColor" viewBox="0 0 24 24">
                                    <path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347m-5.421 7.403h-.004a9.87 9.87 0 01-5.031-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86 9.86 0 01-1.51-5.26c.001-5.45 4.436-9.884 9.888-9.884 2.64 0 5.122 1.03 6.988 2.898a9.825 9.825 0 012.893 6.994c-.003 5.45-4.437 9.884-9.885 9.884m8.413-18.297A11.815 11.815 0 0012.05 0C5.495 0 .16 5.335.157 11.892c0 2.096.547 4.142 1.588 5.945L.057 24l6.305-1.654a11.882 11.882 0 005.683 1.448h.005c6.554 0 11.89-5.335 11.893-11.893A11.821 11.821 0 0020.885 3.488"/>
                                </svg>
                                <a href="{{ \App\Support\Contact::whatsappUrl() }}"
                                   target="_blank"
                                   rel="noopener"
                                   class="text-gray-300 hover:text-gold-500 transition-colors focus:outline-none focus:ring-2 focus:ring-gold-600 focus:ring-offset-2 focus:ring-offset-ink rounded px-1">
                                    {{ __('WhatsApp') }}
                                </a>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="border-t border-gray-500 mt-8 pt-8 text-center">
                    {{-- Was text-gray-600, which is 1.96:1 against bg-ink — not
                         dim, unreadable. gray-400 is 5.84:1. --}}
                    <p class="text-gray-400 mb-2">{{ __('messages.footer_tagline') }}</p>
                    {{-- Same bidi problem, and worse because it is on every
                         page: "© 2026 Rihla Travels. All rights reserved."
                         came out as ".Rihla Travels. All rights reserved 2026 ©".
                         The year and the company name are Latin, so the line
                         is English whatever the page language, and dir="ltr"
                         says so outright. --}}
                    <p dir="ltr" class="text-gray-400">
                        &copy; {{ date('Y') }} {{ config('app.name', 'Rihla Travels') }}. {{ __('All rights reserved.') }}
                    </p>
                </div>
            </div>
        </footer>
    </div>

    <!-- Floating WhatsApp FAB -->
    @unless ($hidesVisitorCallsToAction)
        @include('components.whatsapp-fab')
    @endunless

    <!-- JavaScript -->
    <script nonce="@cspNonce">
        function toggleMobileMenu() {
            const menu = document.getElementById('mobile-menu');
            const button = document.querySelector('[data-click="toggleMobileMenu"]');
            const isExpanded = button.getAttribute('aria-expanded') === 'true';
            
            menu.classList.toggle('hidden');
            button.setAttribute('aria-expanded', !isExpanded);
        }

        // Close mobile menu when clicking outside
        document.addEventListener('click', function(event) {
            const menu = document.getElementById('mobile-menu');
            const button = document.querySelector('[data-click="toggleMobileMenu"]');
            
            if (!menu.contains(event.target) && !button.contains(event.target)) {
                menu.classList.add('hidden');
                button.setAttribute('aria-expanded', 'false');
            }
        });

        // Close mobile menu on escape key
        document.addEventListener('keydown', function(event) {
            if (event.key === 'Escape') {
                const menu = document.getElementById('mobile-menu');
                const button = document.querySelector('[data-click="toggleMobileMenu"]');
                
                menu.classList.add('hidden');
                button.setAttribute('aria-expanded', 'false');
            }
        });
    </script>
    @stack('scripts')

    {{-- Registered here rather than on /guide, so the whole site is available
         offline and the install prompt can appear anywhere. The registration
         on the guide page was pushed to a 'scripts' stack that no layout
         rendered, so it never ran at all. --}}
    <script nonce="@cspNonce">
        if ('serviceWorker' in navigator) {
            window.addEventListener('load', function () {
                navigator.serviceWorker.register('{{ asset('sw.js') }}')
                    .catch(function (error) {
                        console.error('Service worker registration failed:', error);
                    });
            });
        }
    </script>
</body>
</html>
