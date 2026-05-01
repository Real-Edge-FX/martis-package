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
        // v1.7.0 — custom accent colours. The parser validates each
        // entry; invalid ones fall through silently with a Log::warning.
        $customAccents = \Martis\Preferences\CustomAccentsParser::parse(
            (string) (config('martis.preferences.custom_accents') ?? ''),
        );
        $prefsConfig = [
            'enabled' => $prefsEnabled,
            'allowBrandColor' => (bool) config('martis.preferences.allowBrandColor', false),
            'localeLabels' => (array) config('martis.preferences.locale_labels', []),
            'initial' => $prefsPayload,
            'customAccents' => array_map(
                static fn (string $name, string $color): array => ['name' => $name, 'color' => $color],
                array_keys($customAccents),
                array_values($customAccents),
            ),
        ];

        // v1.7.0 — clamp the per-surface logo heights to safe ranges so
        // a typo in .env cannot break the layout.
        $menuLogoHeight = max(20, min(56, (int) (config('martis.brand.logo_height.menu') ?? 40)));
        $authLogoHeight = max(24, min(80, (int) (config('martis.brand.logo_height.auth') ?? 48)));
    @endphp
    <style>
        /* v1.7.0 — brand asset sizing knobs. The CSS rules in martis.css
           read these variables, so the consumer tunes the asset height
           by editing .env without touching the bundled CSS. Safe to
           load BEFORE the bundle: no rule in martis.css declares
           these variables, so cascade order is irrelevant. */
        :root {
            --martis-brand-logo-height-menu: {{ $menuLogoHeight }}px;
            --martis-brand-logo-height-auth: {{ $authLogoHeight }}px;
        }
    </style>
    <script>
        window.MartisConfig = {
            basePath: "/{{ $basePath }}",
            locale: "{{ $prefsPayload['locale'] ?? config('martis.locale', config('app.locale', 'en')) }}",
            preferences: {!! json_encode($prefsConfig) !!},
            brand: "{{ config('martis.brand.name', 'Martis') }}",
            logo: {!! json_encode(config('martis.brand.logo')) !!},
            logoDark: {!! json_encode(config('martis.brand.logo_dark')) !!},
            icon: {!! json_encode(config('martis.brand.icon')) !!},
            iconDark: {!! json_encode(config('martis.brand.icon_dark')) !!},
            logoHeight: {!! json_encode([
                'menu' => (int) (config('martis.brand.logo_height.menu') ?? 40),
                'auth' => (int) (config('martis.brand.logo_height.auth') ?? 48),
            ]) !!},
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
                // null disables compaction (always full digits); a positive
                // integer is the threshold above which the badge switches
                // to compact notation (10K, 1.2M). Default 10000.
                'countCompactThreshold' => config('martis.navigation.counts.compact_threshold') === null
                    ? null
                    : (int) config('martis.navigation.counts.compact_threshold', 10000),
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
            auth: {!! json_encode(array_merge(
                config('martis.auth', [
                    'sso' => ['enabled' => false, 'url' => null],
                    'google' => ['enabled' => false, 'url' => null],
                    'passwordReset' => ['enabled' => false, 'url' => null],
                    'registration' => ['enabled' => false, 'url' => null],
                    'controls' => ['theme' => true, 'locale' => true],
                ]),
                [
                    // v1.8.5 — `auth.copy.*` accepts strings OR
                    // `array<locale, string>` per entry. The blade
                    // exposes the entries verbatim; the React helper
                    // `useAuthCopy()` resolves the active locale at
                    // render time (i18n.language) so the copy
                    // re-renders when the user flips the language
                    // picker, without a server round-trip.
                    'copy' => config('martis.auth.copy', [
                        'login' => ['title' => null, 'subtitle' => null, 'subtitle_with_sso' => null],
                        'register' => ['title' => null, 'subtitle' => null],
                        'forgot_password' => ['title' => null, 'subtitle' => null],
                        'reset_password' => ['title' => null, 'subtitle' => null],
                    ]),
                ]
            )) !!},
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
        //
        // Priority depends on whether the request has a real persisted
        // payload (`source` is `user` or `preset`). When yes, the
        // server-injected values win — they describe what THIS user
        // actually saved server-side and they should beat any older
        // localStorage row from a different account on the same browser.
        //
        // For guests (`source` === 'default'), the server payload is
        // just the config defaults — always dark / en / comfortable.
        // localStorage wins so that a guest who picked light + pt_PT
        // before signing in keeps that choice across refreshes.
        (function() {
            var root = document.documentElement;
            var initial = (window.MartisConfig.preferences && window.MartisConfig.preferences.initial) || {};
            var hasPersisted = initial.source === 'user' || initial.source === 'preset';
            var cached = {};
            try {
                var raw = localStorage.getItem('martis-preferences');
                if (raw) cached = JSON.parse(raw) || {};
            } catch (e) {}

            // Effective preference resolver: persisted payload first
            // (when it exists), otherwise the browser cache, otherwise
            // the server-supplied defaults.
            var pick = function (key) {
                if (hasPersisted && initial[key] !== undefined && initial[key] !== null) {
                    return initial[key];
                }
                if (cached[key] !== undefined && cached[key] !== null) {
                    return cached[key];
                }
                return initial[key];
            };

            var theme = pick('theme') || window.MartisConfig.theme.default || 'dark';
            var accent = pick('accent') || 'martis';
            var density = pick('density') || 'comfortable';
            var reducedMotion = pick('reducedMotion') || false;

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
    {{-- v1.7.2 — Custom-accent rules MUST load AFTER app.css. The bundle
         declares `html:not(.dark) { --martis-accent: ... }` and
         `html.dark { --martis-accent: ... }` as theme defaults. Those
         selectors share specificity (1 type + 1 attr/class = 11) with
         our `html[data-accent="<name>"]` rule, so cascade order is the
         only tie-breaker. Loading our block last guarantees a clicked
         custom accent actually re-tints the UI instead of being
         silently overridden by the bundle defaults. --}}
    @if(!empty($customAccents))
        <style>
            @foreach($customAccents as $accentName => $accentHex)
            /* v1.7.0 — custom accent ‘{{ $accentName }}’ ({{ $accentHex }}).
               Mirrors the variable set defined for bundled accents
               (--martis-accent / -hover / -active / -bg-light / -bg /
               --martis-focus-ring). One rule applies to both themes. */
            html[data-accent="{{ $accentName }}"] {
                --martis-accent:          {{ $accentHex }};
                --martis-accent-hover:    color-mix(in srgb, {{ $accentHex }} 88%, black);
                --martis-accent-active:   color-mix(in srgb, {{ $accentHex }} 78%, black);
                --martis-accent-bg-light: color-mix(in srgb, {{ $accentHex }} 14%, transparent);
                --martis-accent-bg:       color-mix(in srgb, {{ $accentHex }} 24%, transparent);
                --martis-focus-ring:      color-mix(in srgb, {{ $accentHex }} 45%, transparent);
            }
            @endforeach
        </style>
    @endif
</head>
<body>
    <div id="martis-root"></div>
</body>
</html>
