<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <script>
            (function () {
                try {
                    var t = localStorage.getItem('maildesk_theme');
                    if (t !== 'light' && t !== 'dark') {
                        t = window.matchMedia('(prefers-color-scheme: light)').matches
                            ? 'light'
                            : 'dark';
                    }
                    document.documentElement.classList.add(t);
                    document.documentElement.style.colorScheme = t;
                } catch (e) {
                    document.documentElement.classList.add('dark');
                }
            })();
        </script>

        <title inertia>{{ config('app.name', 'Laravel') }}</title>

        <meta name="csrf-token" content="{{ csrf_token() }}">

        <!-- Fonts -->
        <link rel="preconnect" href="https://fonts.bunny.net">
        <link href="https://fonts.bunny.net/css?family=inter:400,500,600,700|figtree:400,500,600&display=swap" rel="stylesheet" />

        <!-- Scripts -->
        @routes
        @vite(['resources/js/app.js', "resources/js/Pages/{$page['component']}.vue"])
        @inertiaHead
    </head>
    <body class="font-sans antialiased">
        @inertia
    </body>
</html>
