{{-- A company's offline page (Phase 4d): kept by its service worker and
     shown when there is no signal. Self-contained — no stylesheet to fetch,
     because there is no network to fetch it from. --}}
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $company->name }}</title>
    <meta name="theme-color" content="{{ $site->accent() }}">
    <style>
        body { margin: 0; font-family: system-ui, -apple-system, 'Segoe UI', Roboto, sans-serif; background: #FFFFFF; color: #1E162E; }
        header { background: {{ $site->accent() }}; color: {{ $site->textOnAccent() }}; padding: 1.25rem 1rem; font-weight: 600; font-size: 1.125rem; }
        main { padding: 2rem 1rem; max-width: 36rem; margin: 0 auto; }
        h1 { font-size: 1.5rem; margin: 0 0 .75rem; }
        p { line-height: 1.5; }
    </style>
</head>
<body>
    <header dir="auto">{{ $company->name }}</header>
    <main>
        <h1>You are offline</h1>
        <p>There is no connection right now. This page will work again as soon as your phone is back online.</p>
        @if ($company->phone)
            <p>To reach us: {{ $company->phone }}</p>
        @endif
    </main>
</body>
</html>
