{{-- A company's own site — companies plan, Phase 4a. `layouts.app` hands
     every page here when the request arrived on a company's address, so the
     pages themselves (packages, contact) are unchanged and only the frame
     around them is the company's.

     Nothing of Rihla's: no Rihla header, footer, logo, social links,
     WhatsApp button, organisation data, manifest or service worker. The one
     exception is the "Powered by Rihla" line, and only when Rihla's admin
     has switched it on for this company (decision 8). --}}
@php
    $siteCompany = app(\App\Support\CurrentSite::class)->company();
    $site = app(\App\Support\CurrentSite::class)->site();
    $siteName = $siteCompany->name;
    $siteTagline = $site->getTranslation('tagline', app()->getLocale()) ?: $site->getTranslation('tagline', 'en');
    $siteWhatsapp = \App\Support\Contact::whatsappUrlFor(\App\Support\Contact::companyWhatsapp());
    $siteAlternates = \App\Support\Seo::alternates(request());
@endphp
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" dir="{{ \App\Http\Middleware\SetLocale::isRtl(app()->getLocale()) ? 'rtl' : 'ltr' }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <script nonce="@cspNonce">document.documentElement.classList.add('js')</script>

    <title>@hasSection('title')@yield('title') — {{ $siteName }}@else{{ $siteName }}@endif</title>
    <meta name="description" content="@yield('description', $siteTagline ?: $siteName)">
    <meta property="og:type" content="website">
    <meta property="og:url" content="{{ url()->current() }}">
    <meta property="og:site_name" content="{{ $siteName }}">
    <meta property="og:title" content="@yield('og_title', $siteName)">
    @if ($site->coverUrl())
        <meta property="og:image" content="{{ url($site->coverUrl()) }}">
    @endif
    <link rel="canonical" href="{{ url()->current() }}">
    @if ($siteAlternates)
        @foreach ($siteAlternates as $seoLocale => $seoUrl)
            <link rel="alternate" hreflang="{{ $seoLocale }}" href="{{ $seoUrl }}">
        @endforeach
        <link rel="alternate" hreflang="x-default" href="{{ $siteAlternates['en'] }}">
    @endif
    @stack('schema')

    {{-- The company's own installable app (Phase 4d). --}}
    <link rel="icon" type="image/png" href="{{ route('site.icon', ['size' => 192]) }}">
    <link rel="apple-touch-icon" href="{{ route('site.icon', ['size' => 192]) }}">
    <link rel="manifest" href="{{ route('site.manifest') }}">
    <meta name="theme-color" content="{{ $site->accent() }}">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-title" content="{{ mb_strimwidth($siteName, 0, 12, '') }}">

    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=inter:300,400,500,600,700" rel="stylesheet">
    @if (app()->getLocale() === 'dv')
        <link rel="preload" href="{{ asset('fonts/A_faruma.woff2') }}" as="font" type="font/woff2" crossorigin>
    @endif

    @vite(['resources/css/app.css', 'resources/js/app.js'])
    {{-- Rihla's palette classes in the company's colours (Phase 4b). After
         the built CSS and unlayered, so it wins without !important. --}}
    <style>{!! (new \App\Support\SitePalette($site))->css() !!}</style>
    @stack('styles')
</head>
<body class="antialiased overflow-x-hidden bg-white text-ink"
      style="--site-primary: {{ $site->primary() }}; --site-accent: {{ $site->accent() }}; --site-on-accent: {{ $site->textOnAccent() }}; font-family: {{ $site->fontStack() }};">
    <a href="#main-content" class="sr-only focus:not-sr-only focus:absolute focus:z-50 focus:bg-white focus:p-3">{{ __('messages.Skip to content') }}</a>

    <div class="flex min-h-screen flex-col">
        {{-- The header is the one part of the frame each theme draws its
             own way. Literal class strings, so Tailwind compiles each. --}}
        @php
            $siteHeader = match ($site->theme) {
                \App\Models\CompanySite::MINIMAL => 'bg-white text-[color:var(--site-primary)] border-b-4 border-[color:var(--site-accent)]',
                \App\Models\CompanySite::BOLD => 'bg-[var(--site-accent)] text-[color:var(--site-on-accent)] text-center',
                default => 'bg-[var(--site-accent)] text-[color:var(--site-on-accent)]',
            };
            $siteHeaderInner = $site->theme === \App\Models\CompanySite::BOLD
                ? 'container mx-auto flex flex-col items-center gap-3 px-4 py-6'
                : 'container mx-auto flex flex-wrap items-center justify-between gap-4 px-4 py-4';
        @endphp
        <header class="{{ $siteHeader }}">
            <div class="{{ $siteHeaderInner }}">
                <a href="{{ route('home') }}" class="flex items-center gap-3 font-semibold {{ $site->theme === \App\Models\CompanySite::BOLD ? 'text-2xl' : 'text-lg' }}" dir="auto">
                    @if ($site->logoUrl())
                        <img src="{{ $site->logoUrl() }}" alt="{{ $siteName }}" class="h-12 w-auto rounded bg-white p-1">
                    @else
                        <span>{{ $siteName }}</span>
                    @endif
                </a>
                <nav aria-label="{{ __('messages.Main') }}" class="flex flex-wrap items-center gap-x-5 gap-y-2 text-sm font-medium">
                    <a href="{{ route('home') }}" class="py-2 hover:underline" @if (request()->routeIs('home')) aria-current="page" @endif>{{ __('messages.Home') }}</a>
                    <a href="{{ route('packages.index') }}" class="py-2 hover:underline" @if (request()->routeIs('packages.*')) aria-current="page" @endif>{{ __('messages.Packages') }}</a>
                    <a href="{{ route('contact') }}" class="py-2 hover:underline" @if (request()->routeIs('contact')) aria-current="page" @endif>{{ __('messages.Contact') }}</a>
                    @if ($siteAlternates)
                        @foreach ($siteAlternates as $seoLocale => $seoUrl)
                            @continue($seoLocale === app()->getLocale())
                            <a href="{{ $seoUrl }}" hreflang="{{ $seoLocale }}" lang="{{ $seoLocale }}" class="py-2 hover:underline">{{ ['en' => 'English', 'dv' => 'ދިވެހި', 'ar' => 'العربية'][$seoLocale] ?? $seoLocale }}</a>
                        @endforeach
                    @endif
                </nav>
            </div>
        </header>

        <main id="main-content" class="flex-1" role="main">
            @if (session('status'))
                <div class="container mx-auto px-4 pt-6" role="status" aria-live="polite">
                    <p dir="auto" class="rounded-xl border border-[color:var(--site-accent)] bg-white p-4 text-ink">{{ session('status') }}</p>
                </div>
            @endif

            @yield('content')
        </main>

        <footer class="border-t border-gray-200 bg-gray-50 py-10 text-sm text-ink">
            <div class="container mx-auto grid gap-6 px-4 md:grid-cols-2">
                <div>
                    <p class="text-base font-semibold" dir="auto">{{ $siteName }}</p>
                    @if ($siteCompany->address)
                        <p class="mt-2" dir="auto">{{ $siteCompany->address }}</p>
                    @endif
                    @if ($siteCompany->registration_number)
                        <p class="mt-1 text-ink-muted">{{ __('messages.Registration') }}: {{ $siteCompany->registration_number }}</p>
                    @endif
                </div>
                <ul class="space-y-2 md:text-end">
                    @if ($siteCompany->phone)
                        <li><a class="hover:underline" href="{{ \App\Support\Contact::telUrlFor($siteCompany->phone) }}">{{ $siteCompany->phone }}</a></li>
                    @endif
                    @if ($siteCompany->email)
                        <li><a class="hover:underline" href="mailto:{{ $siteCompany->email }}">{{ $siteCompany->email }}</a></li>
                    @endif
                    @if ($siteWhatsapp)
                        <li><a class="hover:underline" href="{{ $siteWhatsapp }}" rel="noopener" target="_blank">WhatsApp</a></li>
                    @endif
                    @if ($site->instagram)
                        <li><a class="hover:underline" href="{{ $site->instagram }}" rel="noopener" target="_blank">Instagram</a></li>
                    @endif
                    @if ($site->facebook)
                        <li><a class="hover:underline" href="{{ $site->facebook }}" rel="noopener" target="_blank">Facebook</a></li>
                    @endif
                </ul>
            </div>
            <div class="container mx-auto mt-8 flex flex-wrap justify-between gap-2 px-4 text-ink-muted">
                <p>&copy; {{ now()->year }} {{ $siteName }}</p>
                @if ($siteCompany->powered_by_rihla)
                    <p><a class="hover:underline" href="https://rihla.mv" rel="noopener">{{ __('messages.Powered by Rihla') }}</a></p>
                @endif
            </div>
        </footer>
    </div>

    @stack('scripts')
    <script nonce="@cspNonce">
        if ('serviceWorker' in navigator) {
            window.addEventListener('load', () => navigator.serviceWorker.register('{{ route('site.worker') }}', { scope: '/' }).catch(() => {}));
        }
    </script>
</body>
</html>
