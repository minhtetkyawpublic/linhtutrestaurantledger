<!doctype html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <meta name="theme-color" content="#fff7ed" />
    <meta name="description" content="Lin Htut Restaurant Ledger" />
    <meta name="csrf-token" content="{{ csrf_token() }}" />
    @php
        $appBasePath = parse_url(url('/'), PHP_URL_PATH) ?: '/';
        $appBasePath = $appBasePath === '/' ? '' : $appBasePath;
    @endphp
    <script>
        window.__APP_BASE_PATH = @json($appBasePath);
    </script>

    <title>{{ config('app.name', 'Lin Htut Restaurant Ledger') }}</title>
    <link rel="manifest" href="{{ asset('manifest.webmanifest') }}">
    <link rel="icon" type="image/png" sizes="48x48" href="{{ asset('icon-48.png') }}">
    <link rel="apple-touch-icon" sizes="180x180" href="{{ asset('icon-180.png') }}">

    @viteReactRefresh
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body>
    <div id="app-root"></div>
    <noscript>Enable JavaScript to use this application. ဤအက်ပ်ကို အသုံးပြုရန် JavaScript ဖွင့်ပါ။</noscript>
</body>
</html>
