<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="dark">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ app(\Martis\MartisManager::class)->resolvePageTitle(request()) }}</title>
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
    @php
        // Task 07.1 ⭐ D2 — resolve user preferences server-side and inject
        // them BEFORE first paint so theme/accent/density apply without a flash.
        $prefsEnabled = (bool) config('martis.preferences.enabled', true);
        $prefsPayload = null;
        if ($prefsEnabled) {
            try {
                /** @var \Martis\Preferences\PreferencesResolver $resolver */
                $resolver = app(\Martis\Preferences\PreferencesResolver::class);
                $prefsPayload = $resolver->resolve(request());
            } catch (\Throwable) {
                $prefsPayload = null;
            }
        }
        $prefsConfig = [
            'enabled' => $prefsEnabled,
            'allowBrandColor' => (bool) config('martis.preferences.allowBrandColor', false),
            'localeLabels' => (array) config('martis.preferences.locale_labels', []),
            'initial' => $prefsPayload,
        ];
    @endphp
    <script>
        window.MartisConfig = {
            basePath: "/{{ $basePath }}",
            locale: "{{ $prefsPayload['locale'] ?? config('martis.locale', config('app.locale', 'en')) }}",
            preferences: {!! json_encode($prefsConfig) !!},
            brand: "{{ config('martis.brand.name', 'Martis') }}",
            logo: {!! json_encode(config('martis.brand.logo')) !!},
            version: {!! json_encode(app(\Martis\MartisManager::class)->version()) !!},
            docsUrl: {!! json_encode(config('martis.brand.docs_url')) !!},
            theme: {!! json_encode(config('martis.theme', ['default' => 'dark', 'allowToggle' => true])) !!},
            keyboardShortcuts: {!! json_encode(config('martis.keyboard_shortcuts', ['enabled' => true, 'helpOverlay' => true])) !!},
            userMenu: {!! json_encode(config('martis.user_menu', ['showThemeToggle' => true, 'showProfile' => true, 'showNotifications' => true])) !!},
            search: {!! json_encode(config('martis.search', ['enabled' => true])) !!},
            dashboard: {!! json_encode(config('martis.dashboard', ['showGreeting' => true, 'showWelcome' => true, 'showWelcomeCard' => true, 'showMetrics' => true, 'showResourceCards' => true])) !!},
            welcome: {!! json_encode(config('martis.welcome', ['heading' => null, 'description' => null])) !!},
            toast: {!! json_encode(config('martis.toast', ['position' => 'bottom-right'])) !!},
            footer: {!! json_encode(config('martis.footer', ['enabled' => true, 'text' => null])) !!},
            drawer: {!! json_encode([
                'width' => config('martis.drawer.width', '560px'),
                'expandedWidth' => config('martis.drawer.expanded_width', '800px'),
                'expandable' => (bool) config('martis.drawer.expandable', true),
            ]) !!},
            layout: {!! json_encode(config('martis.layout', ['preset' => 'sidebar'])) !!},
            navigation: {!! json_encode([
                'pollInterval' => (int) config('martis.navigation.poll_interval', 60000),
            ]) !!},
            loader: {!! json_encode(config('martis.loader', ['disabled' => false])) !!},
            notifications: {!! json_encode(config('martis.notifications', [
                'enabled' => true,
                'poll_interval' => 60000,
                'max_in_dropdown' => 10,
            ])) !!},
            stickyViews: {!! json_encode(config('martis.sticky_views', [
                'enabled' => true,
                'scope' => 'session',
                'persist' => [
                    'filters' => true,
                    'sorting' => true,
                    'pagination' => true,
                    'per_page' => true,
                    'columns' => true,
                    'scroll' => false,
                ],
            ])) !!},
            auth: {!! json_encode(config('martis.auth', [
                'sso' => ['enabled' => false, 'url' => null],
                'google' => ['enabled' => false, 'url' => null],
                'passwordReset' => ['enabled' => false, 'url' => null],
                'registration' => ['enabled' => false, 'url' => null],
                'controls' => ['theme' => true, 'locale' => true],
            ])) !!},
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
            ]) !!},
            impersonation: {!! json_encode([
                // Master switch surfaced at boot so the React banner
                // can short-circuit its `/api/impersonation/status`
                // poll when impersonation is disabled. Without this
                // the banner fires one round-trip per page mount even
                // when the feature is off, which adds ~1s to every
                // navigation under a cold cache.
                'enabled' => (bool) config('martis.impersonation.enabled', false),
            ]) !!}
        };
        // Apply preferences BEFORE first paint to prevent any flash.
        // Priority: server-injected payload > localStorage cache > defaults.
        (function() {
            var root = document.documentElement;
            var prefs = (window.MartisConfig.preferences && window.MartisConfig.preferences.initial) || {};
            var cached = {};
            try {
                var raw = localStorage.getItem('martis-preferences');
                if (raw) cached = JSON.parse(raw) || {};
            } catch (e) {}

            var theme = prefs.theme || cached.theme || window.MartisConfig.theme.default || 'dark';
            var accent = prefs.accent || cached.accent || 'martis';
            var density = prefs.density || cached.density || 'comfortable';
            var reducedMotion = prefs.reducedMotion || cached.reducedMotion || false;

            // `theme = system` honours the OS preference at paint time.
            if (theme === 'system') {
                theme = window.matchMedia && window.matchMedia('(prefers-color-scheme: light)').matches
                    ? 'light' : 'dark';
            }

            if (theme === 'dark') root.classList.add('dark'); else root.classList.remove('dark');
            root.setAttribute('data-theme', theme);
            root.setAttribute('data-accent', accent);
            root.setAttribute('data-density', density);
            if (reducedMotion) root.setAttribute('data-reduced-motion', 'true');
            else root.removeAttribute('data-reduced-motion');
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
