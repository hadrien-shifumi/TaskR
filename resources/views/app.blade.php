<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">
        <link rel="icon" type="image/svg+xml" href="{{ asset('assets/taskr-app-icon.svg') }}">
        <title>{{ config('app.name', 'Taskr') }}</title>
        <link rel="preconnect" href="https://fonts.bunny.net">
        @if (Vite::isRunningHot())
            <script type="module">
                import RefreshRuntime from 'http://localhost:5173/@react-refresh'

                RefreshRuntime.injectIntoGlobalHook(window)
                window.$RefreshReg$ = () => {}
                window.$RefreshSig$ = () => (type) => type
            </script>
        @endif
        @vite(['resources/css/app.css', 'resources/js/app.tsx'])
        @routes
    </head>
    <body>
        @inertia
    </body>
</html>
