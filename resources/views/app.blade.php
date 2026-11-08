<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="theme-color" content="#070908">

        <script>
            (function () {
                var theme = 'dark';

                try {
                    if (localStorage.getItem('theme') === 'light') {
                        theme = 'light';
                    }
                } catch (error) {
                    // Storage can be unavailable; fall back to the dark theme.
                }

                document.documentElement.classList.add(theme);

                if (theme === 'light') {
                    document
                        .querySelector('meta[name="theme-color"]')
                        .setAttribute('content', '#f6f8f7');
                }
            })();
        </script>

        <title inertia>{{ config('app.name', 'Laravel') }}</title>

        <!-- Fonts -->
        <link rel="preconnect" href="https://fonts.bunny.net">
        <link href="https://fonts.bunny.net/css?family=figtree:400,500,600,700,800&display=swap" rel="stylesheet" />

        <!-- Scripts -->
        @routes

        @if (! empty($page['props']['og']))
            <meta property="og:type" content="website">
            <meta property="og:site_name" content="{{ config('app.name', 'Spotify Stats') }}">
            <meta property="og:title" content="{{ $page['props']['og']['title'] }}">
            <meta property="og:description" content="{{ $page['props']['og']['description'] }}">
            <meta property="og:image" content="{{ $page['props']['og']['image'] }}">
            <meta property="og:image:width" content="1200">
            <meta property="og:image:height" content="630">
            <meta name="twitter:card" content="summary_large_image">
            <meta name="twitter:title" content="{{ $page['props']['og']['title'] }}">
            <meta name="twitter:description" content="{{ $page['props']['og']['description'] }}">
            <meta name="twitter:image" content="{{ $page['props']['og']['image'] }}">
        @endif

        @vite(['resources/js/app.js', "resources/js/pages/{$page['component']}.vue"])
        @inertiaHead
    </head>
    <body class="font-sans antialiased">
        @inertia
    </body>
</html>
