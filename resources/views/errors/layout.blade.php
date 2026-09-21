{{--
    Deliberately self-contained: no parent layout, no bundled CSS, no
    database.

    layouts.app emits the Organization JSON-LD, which reads the settings
    table. An error page that extends it would query the database to explain
    that the database is down, and throw a second time inside the handler for
    the first. Everything here is inline and static, for the same reason
    offline.html is.
--}}
<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}" dir="{{ app()->getLocale() === 'dv' ? 'rtl' : 'ltr' }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>@yield('title') — {{ config('app.name') }}</title>
    <link rel="icon" type="image/png" sizes="32x32" href="/favicon-32x32.png">
    <style>
        /* A copy of the Thaana face from resources/css/dhivehi-fonts.css.
           Nothing here loads the compiled bundle, so without this a Dhivehi
           error page would fall back to whatever the browser had — the exact
           bug that was just fixed on the rest of the site. The font file is a
           12 KB static asset; serving it needs no application.

           Note for anyone editing the comments in this file: Blade compiles
           directives inside CSS comments too. Writing the name of the asset
           helper here with its at-sign in front of it made this page call it,
           with no arguments, and every error became a 500. */
        @font-face {
            font-family: 'A_Faruma';
            font-style: normal;
            font-weight: 400;
            font-display: swap;
            src: local('A_faruma'),
                 url('/fonts/A_faruma.woff2') format('woff2'),
                 url('/fonts/A_faruma.ttf') format('truetype');
            unicode-range: U+0780-07BF, U+FDF2;
        }
        :root {
            --wine: #5F498A;
            --gold: #EFD34D;
            --ink: #2E2245;
            --ink-muted: #6B6080;
            --cream: #FFFDF0;
        }
        * { box-sizing: border-box; }
        body {
            margin: 0;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 24px;
            background: var(--cream);
            color: var(--ink);
            font-family: 'A_Faruma', 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
            line-height: 1.6;
        }
        /* Thaana carries vowel marks above and below the base letter, which
           collide at line heights that suit Latin. Same values as the site. */
        [lang='dv'] body { line-height: 1.8; letter-spacing: 0.01em; }
        [lang='dv'] h1 { line-height: 1.4; letter-spacing: 0; }
        .panel { max-width: 34rem; text-align: center; }
        .mark { width: 72px; height: 72px; margin: 0 auto 24px; display: block; }
        .code {
            font-size: 0.875rem;
            letter-spacing: 0.08em;
            text-transform: uppercase;
            color: var(--ink-muted);
            margin: 0 0 8px;
        }
        h1 {
            font-size: 1.875rem;
            line-height: 1.2;
            margin: 0 0 16px;
            color: var(--wine);
        }
        p { color: var(--ink-muted); margin: 0 0 28px; }
        .rule {
            width: 56px;
            height: 3px;
            background: var(--gold);
            border: 0;
            margin: 0 auto 24px;
        }
        .actions { display: flex; gap: 12px; justify-content: center; flex-wrap: wrap; }
        a.button {
            display: inline-block;
            padding: 12px 24px;
            border-radius: 8px;
            text-decoration: none;
            font-weight: 500;
            background: var(--wine);
            color: #fff;
        }
        a.button.secondary {
            background: transparent;
            color: var(--ink);
            border: 1px solid #DBD3CE;
        }
        a.button:focus-visible { outline: 3px solid var(--gold); outline-offset: 2px; }
        @media (max-width: 480px) { h1 { font-size: 1.5rem; } }
    </style>
</head>
<body>
    <main class="panel">
        <img src="/images/icon-192.png" alt="{{ config('app.name') }}" class="mark" width="72" height="72"
         loading="lazy"
         decoding="async">

        <p class="code">@yield('code')</p>
        <h1>@yield('title')</h1>
        <hr class="rule">
        <p>@yield('message')</p>

        <div class="actions">
            {{-- Literal paths, deliberately. Named-route generation reads
                 URL::defaults(), which SetLocale populates; a failure early in
                 the request reaches this page before that has run, and the
                 helper would then throw inside the handler for the first
                 throw. --}}
            <a class="button" href="/{{ app()->getLocale() }}">{{ __('messages.error_home') }}</a>
            <a class="button secondary" href="/{{ app()->getLocale() }}/trips">{{ __('messages.Trips') }}</a>
        </div>
    </main>
</body>
</html>
