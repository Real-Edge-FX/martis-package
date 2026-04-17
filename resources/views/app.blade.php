<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="dark">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ config('martis.brand.name', 'Martis') }} Admin</title>
    @php
        $faviconPath = config('martis.brand.favicon');
        $basePath = config('martis.path', 'martis');
    @endphp
    @if($faviconPath)
        <link rel="icon" type="image/x-icon" href="{{ asset($faviconPath) }}">
        <link rel="icon" type="image/png" sizes="32x32" href="{{ asset($faviconPath) }}">
    @else
        <link rel="icon" type="image/x-icon" href="/{{ $basePath }}/favicon.ico">
    @endif
    <script>
        window.MartisConfig = {
            basePath: "/{{ $basePath }}",
            locale: "{{ config('martis.locale', 'en') }}",
            brand: "{{ config('martis.brand.name', 'Martis') }}",
            logo: {!! json_encode(config('martis.brand.logo')) !!},
            theme: {!! json_encode(config('martis.theme', ['default' => 'dark', 'allowToggle' => true])) !!},
            userMenu: {!! json_encode(config('martis.user_menu', ['showThemeToggle' => true, 'showProfile' => true, 'showNotifications' => true])) !!},
            search: {!! json_encode(config('martis.search', ['enabled' => true])) !!},
            dashboard: {!! json_encode(config('martis.dashboard', ['showGreeting' => true, 'showWelcome' => true, 'showMetrics' => true, 'showResourceCards' => true])) !!},
            toast: {!! json_encode(config('martis.toast', ['position' => 'bottom-right'])) !!},
            footer: {!! json_encode(config('martis.footer', ['enabled' => true, 'text' => null])) !!},
            layout: {!! json_encode(config('martis.layout', ['preset' => 'sidebar'])) !!},
            loader: {!! json_encode(config('martis.loader', ['disabled' => false])) !!},
            profile: {!! json_encode([
                'enabled' => (bool) config('martis.profile.enabled', true),
                'sections' => array_values(array_intersect(
                    config('martis.profile.sections', ['account', 'password', 'avatar', 'security']),
                    ['account', 'password', 'avatar', 'security']
                )),
                'menu' => [
                    'label' => config('martis.profile.menu.label'),
                    'icon' => config('martis.profile.menu.icon', 'user'),
                ],
                'avatar' => [
                    'enabled' => (bool) config('martis.profile.avatar.enabled', true),
                ],
                'two_factor' => [
                    'enabled' => (bool) config('martis.profile.two_factor.enabled', true),
                ],
            ]) !!}
        };
        // Apply saved theme before first paint to prevent flash
        (function() {
            var t = localStorage.getItem('martis-theme') || window.MartisConfig.theme.default || 'dark';
            if (t === 'dark') document.documentElement.classList.add('dark');
            else document.documentElement.classList.remove('dark');
        })();
    </script>
    @php
        $themeName = config('martis.theme.name');
        if ($themeName && ! preg_match('/^[a-zA-Z0-9_-]+$/', $themeName)) {
            $themeName = null;
        }
    @endphp
    @php
        $hotFile = public_path('vendor/martis/hot');
        $manifestPath = public_path('vendor/martis/manifest.json');
    @endphp

    @if(file_exists($hotFile))
        @php
            $hotUrl = rtrim((string) file_get_contents($hotFile), '/');
        @endphp
        <script type="module" src="{{ $hotUrl }}/@vite/client"></script>
        <script type="module" src="{{ $hotUrl }}/resources/js/app.tsx"></script>
    @elseif(file_exists($manifestPath))
        @php
            $manifest = json_decode((string) file_get_contents($manifestPath), true) ?: [];
            $entry = $manifest['resources/js/app.tsx'] ?? null;
            $cssFiles = is_array($entry['css'] ?? null) ? $entry['css'] : [];
            $entryFile = is_array($entry) ? (string) ($entry['file'] ?? '') : '';
        @endphp

        @if($entry)
            @foreach($cssFiles as $cssFile)
                <link rel="stylesheet" href="{{ asset('vendor/martis/' . ltrim($cssFile, '/')) }}">
            @endforeach
            <script type="module" src="{{ asset('vendor/martis/' . ltrim($entryFile, '/')) }}"></script>
        @endif
    @endif
    {{-- Theme overrides must load AFTER app.css to win CSS specificity --}}
    @if(!empty($themeName))
        <link rel="stylesheet" href="{{ asset('vendor/martis/themes/' . $themeName . '.css') }}">
    @endif
</head>
<body>
    <div id="martis-root"></div>
</body>
</html>
