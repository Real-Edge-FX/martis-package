<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Martis Base Path
    |--------------------------------------------------------------------------
    | The path where the Martis admin panel will be accessible.
    */
    'path' => env('MARTIS_PATH', 'martis'),

    /*
    |--------------------------------------------------------------------------
    | Martis Authentication Guard
    |--------------------------------------------------------------------------
    | null = use Laravel's default guard (auth.php default).
    */
    'guard' => env('MARTIS_GUARD', null),

    /*
    |--------------------------------------------------------------------------
    | Base Middleware
    |--------------------------------------------------------------------------
    | Applied to all Martis routes (public and protected).
    */
    'middleware' => ['web'],

    /*
    |--------------------------------------------------------------------------
    | Auth Middleware
    |--------------------------------------------------------------------------
    | Applied to protected Martis routes (everything except login/logout).
    */
    'auth_middleware' => ['martis.auth'],

    /*
    |--------------------------------------------------------------------------
    | Brand
    |--------------------------------------------------------------------------
    */
    'brand' => [
        'name' => env('MARTIS_BRAND_NAME', 'Martis'),
        'logo' => null,
        'favicon' => env('MARTIS_FAVICON', null),

        /*
         | The browser tab title shown in `<title>`. Accepts:
         |   - null     → use the bundled translation "{brand} — Admin Control"
         |   - string   → literal title, e.g. "Acme Back Office"
         |   - callable → invokable class or array callable that returns a string
         |                and receives the current Request
         |
         | For per-route titles (callback with request inspection), register
         | via `Martis::pageTitleUsing(fn (Request $r) => ...)` from the
         | application's service provider instead — closures cannot live in
         | config files because `php artisan config:cache` fails to serialise
         | them.
         */
        'page_title' => env('MARTIS_PAGE_TITLE'),

        /*
         | Optional version string printed in the sidebar footer. Useful to
         | surface the tenant's deployed build (e.g. "v0.7.0-beta", "2025.11.04").
         | Null hides the version segment.
         */
        'version' => env('MARTIS_BRAND_VERSION'),

        /*
         | Optional docs link rendered on the right-hand side of the sidebar
         | footer. Can be an external URL or an in-app path. Null hides it.
         */
        'docs_url' => env('MARTIS_BRAND_DOCS_URL'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Footer
    |--------------------------------------------------------------------------
    | Configure the default footer displayed at the bottom of the admin panel.
    | Set enabled to false to hide the footer entirely.
    | When text is null, the footer displays: "© {brand.name} · Powered by Martis"
    */
    'footer' => [
        'enabled' => true,
        'text' => null,
    ],

    /*
    |--------------------------------------------------------------------------
    | Layout
    |--------------------------------------------------------------------------
    | Choose the global layout preset for the admin panel.
    | Available presets: "sidebar", "topnav", "minimal", "custom"
    */
    'layout' => [
        'preset' => env('MARTIS_LAYOUT', 'sidebar'),

        /*
         | Swap individual shell pieces by registry key, without ejecting
         | the bundled layout entirely. Each value must be a key that the
         | consumer registered via `componentRegistry.register(...)` in
         | `resources/js/martis/boot.ts`. Null keeps the bundled component.
         |
         |   'components' => [
         |       'shell'   => 'my-shell',       // whole shell; skips grid + drawer
         |       'sidebar' => 'my-sidebar',     // just the left column
         |       'topbar'  => 'my-topbar',      // just the top bar
         |       'footer'  => 'my-footer',      // just the page footer
         |   ],
         |
         | The frontend also honours direct keys — `layout:sidebar`,
         | `layout:topbar`, `layout:footer`, `layout:shell` — so apps that
         | only touch JS can register under those names and skip this
         | config entirely.
         */
        'components' => [
            'shell' => null,
            'sidebar' => null,
            'topbar' => null,
            'footer' => null,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Navigation
    |--------------------------------------------------------------------------
    | Tweaks for the sidebar and top-nav menus.
    |
    | counts.enabled
    |     Master switch for the resource count badge ("Users 1,284"). When
    |     true (default), every resource that doesn't opt out publishes a
    |     count. Set to false to silence all badges globally without
    |     touching individual resources.
    */
    'navigation' => [
        'counts' => [
            'enabled' => env('MARTIS_NAV_COUNTS', true),
        ],

        /*
         | How often (in milliseconds) the sidebar and top-nav re-fetch the
         | navigation endpoint while a tab is focused. Keeps count badges
         | in sync when a second user mutates data in parallel.
         | Set to 0 to disable polling entirely.
         */
        'poll_interval' => (int) env('MARTIS_NAV_POLL_MS', 60000),
    ],

    /*
    |--------------------------------------------------------------------------
    | Localisation
    |--------------------------------------------------------------------------
    | Default locale for the Martis admin panel.
    | Override per user by setting locale dynamically or publish lang files.
    */
    'locale' => env('MARTIS_LOCALE', env('APP_LOCALE', 'en')),

    /*
    |--------------------------------------------------------------------------
    | Throttling
    |--------------------------------------------------------------------------
    | Rate limits for Martis routes. Two distinct buckets:
    |   - `api` — protects general authenticated API routes (resource CRUD,
    |     dashboards, metrics). Default 120 req/min is generous because the
    |     SPA is chatty on navigation.
    |   - `login` — brute-force protection on the login form, 2FA challenge,
    |     and API login endpoint. Tight by design (20 req/min) but loose
    |     enough that a typo-prone human doesn't get locked out.
    | Set `api.enabled = false` to disable throttling on API routes entirely.
    */
    'throttle' => [
        'enabled' => env('MARTIS_THROTTLE_ENABLED', true),
        'max_attempts' => (int) env('MARTIS_THROTTLE_MAX', 120),
        'decay_minutes' => (int) env('MARTIS_THROTTLE_DECAY', 1),
        'login_attempts' => (int) env('MARTIS_LOGIN_THROTTLE_ATTEMPTS', 20),
        'login_minutes' => (int) env('MARTIS_LOGIN_THROTTLE_MINUTES', 1),
    ],

    /*
    |--------------------------------------------------------------------------
    | Theme
    |--------------------------------------------------------------------------
    | Configure the default theme and whether users can toggle between themes.
    | 'default' => 'dark' or 'light'
    | 'allowToggle' => true/false — shows the toggle in the user menu
    */
    'theme' => [
        'default' => env('MARTIS_THEME', 'dark'),
        'allowToggle' => true,
        'name' => env('MARTIS_THEME_NAME', null),
    ],

    /*
    |--------------------------------------------------------------------------
    | User Preferences (Task 07.1 ⭐ D2)
    |--------------------------------------------------------------------------
    | Runtime UI preferences (theme, accent, density, locale, reduced-motion)
    | persisted per-user in `martis_user_preferences`. Disable with
    | 'enabled' => false to fall back to stateless defaults everywhere.
    |
    | Presets: named bundles applied via ?preset=<name> in the URL. Useful
    | for role-based shareable links (exec dashboards, ops compact mode).
    */
    'preferences' => [
        'enabled' => env('MARTIS_PREFERENCES_ENABLED', true),

        'defaults' => [
            'theme' => 'dark',
            'accent' => 'martis',
            'brandColor' => null,
            'density' => 'comfortable',
            'locale' => env('MARTIS_DEFAULT_LOCALE', 'en'),
            'reducedMotion' => false,
        ],

        // Locales the UI exposes in the language picker. Null = use the
        // three bundled by the package (en, pt_PT, pt_BR). Add any code
        // here once you ship translations for it under
        // resources/lang/{locale}/ (or lang/vendor/martis/{locale}/).
        'locales' => ['en', 'pt_PT', 'pt_BR'],

        // Human-readable labels rendered in the language dropdown. Any
        // locale missing here falls back to its code (e.g. "fr_CA").
        // The code itself is what gets persisted / sent to the API.
        'locale_labels' => [
            'en' => 'English',
            'pt_PT' => 'Português (PT)',
            'pt_BR' => 'Português (BR)',
        ],

        // Allow users to set an arbitrary brand hex (⭐ D1). Off by default —
        // apps opt in via env or config override when multi-tenant branding
        // is a real requirement.
        'allowBrandColor' => env('MARTIS_ALLOW_BRAND_COLOR', false),

        // Named presets. Apply via `/resources/...?preset=<name>`.
        'presets' => [
            'exec-comfort' => [
                'accent' => 'violet',
                'density' => 'comfortable',
            ],
            'ops-compact' => [
                'accent' => 'teal',
                'density' => 'dense',
                'reducedMotion' => true,
            ],
            'focus-amber' => [
                'theme' => 'dark',
                'accent' => 'amber',
                'density' => 'comfortable',
            ],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | User Menu
    |--------------------------------------------------------------------------
    | Configure what appears in the user profile context menu.
    | Set any option to false to hide it.
    | showProfile controls the Profile link in the dropdown.
    | 'customItems' allows you to add custom links/actions to the user menu.
    | Each item can have: label, icon (PrimeIcons class), url (route/external).
    | Use ['separator' => true] to add a divider between groups.
    |
    | Example:
    |   'customItems' => [
    |       ['label' => 'My Profile', 'icon' => 'pi pi-user', 'url' => '/profile'],
    |       ['label' => 'Settings', 'icon' => 'pi pi-cog', 'url' => '/settings'],
    |       ['separator' => true],
    |       ['label' => 'Documentation', 'icon' => 'pi pi-book', 'url' => 'https://docs.example.com'],
    |   ],
    */
    'user_menu' => [
        'showThemeToggle' => true,
        'showProfile' => env('MARTIS_SHOW_PROFILE_MENU', true),
        // 'customItems' => [],
    ],

    /*
    |--------------------------------------------------------------------------
    | Global Search
    |--------------------------------------------------------------------------
    | Configure the search bar in the topbar.
    */
    'search' => [
        'enabled' => true,
        'placeholder' => null, // null = use i18n default "Press / to search"
        'mode' => env('MARTIS_SEARCH_MODE', 'bar'), // bar, icon, disabled
        'mobileMode' => env('MARTIS_SEARCH_MOBILE_MODE', 'icon'), // bar, icon, disabled
    ],

    /*
    |--------------------------------------------------------------------------
    | Dashboard
    |--------------------------------------------------------------------------
    |
    | Configure the dashboard page layout and visible sections.
    |
    | showGreeting      - Show the personalised greeting ("Hello, {name}") at
    |                     the top of the dashboard. Set to false to hide it.
    |
    | showWelcome       - Show the welcome subtitle below the greeting
    |                     ("Welcome to Martis Admin Engine."). Set to false
    |                     to hide just the subtitle while keeping the greeting.
    |
    | showMetrics       - Show the summary metrics row at the top of the
    |                     dashboard (total resources, groups, active count).
    |                     Set to false to hide the entire metrics section.
    |
    | showResourceCards - Show the grid of resource quick-access cards below
    |                     the metrics. Each card links to the resource index.
    |                     Set to false to hide the resource cards section.
    |
    | Note: The dashboard currently displays navigation-derived metadata.
    | Future versions will support custom metrics via Resource::metrics()
    | and user-defined dashboard widgets/cards.
    |
    */
    'dashboard' => [
        'showGreeting' => env('MARTIS_DASHBOARD_SHOW_GREETING', true),
        'showWelcome' => env('MARTIS_DASHBOARD_SHOW_WELCOME', true),
        'showMetrics' => true,
        'showResourceCards' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | Cache — Martis Extension
    |--------------------------------------------------------------------------
    |
    | Global cache TTL defaults for different Martis subsystems.
    | Individual metrics can override via cacheFor() on the class.
    | Set to null to disable caching for that area.
    |
    | TTL values are in minutes.
    |
    */

    'cache' => [
        'metrics' => env('MARTIS_CACHE_METRICS', 5),
        'dashboards' => env('MARTIS_CACHE_DASHBOARDS', null),
        'navigation' => env('MARTIS_CACHE_NAVIGATION', 1),
        'schema' => env('MARTIS_CACHE_SCHEMA', null),
    ],

    /*
    |--------------------------------------------------------------------------
    | Toast Notifications
    |--------------------------------------------------------------------------
    | Configure the position of toast notifications.
    | Options: 'top-right', 'top-left', 'bottom-right', 'bottom-left',
    |          'top-center', 'bottom-center'
    */
    'toast' => [
        'position' => env('MARTIS_TOAST_POSITION', 'bottom-right'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Index (Resource Listing)
    |--------------------------------------------------------------------------
    | Defaults for resource index pages and for the inline tables rendered by
    | `HasMany`, `MorphMany`, `BelongsToMany`, `MorphToMany` fields.
    |
    | default_row_actions.enabled
    |     Master kill-switch for the View/Edit/Delete (and Restore/ForceDelete
    |     when soft-deletes apply) actions column. When `true`, Martis renders
    |     these actions gated by per-row policies — authorized actions show
    |     enabled, unauthorized ones show disabled (greyed-out, non-clickable).
    |     When `false`, Martis never renders the default actions anywhere
    |     (custom resource actions still appear).
    |
    |     Per-action visibility is NOT configurable here — it is determined by
    |     the per-row authorization plus optional per-instance overrides on
    |     relationship fields (e.g. `HasMany::make()->hideDeleteAction()`),
    |     plus per-resource via the `defaultRowActions(Request)` method:
    |
    |         public function defaultRowActions(Request $request): bool|array
    |         {
    |             return ['view', 'edit']; // allowed subset
    |             // return false;         // opt out entirely
    |         }
    |
    | row_click_opens_detail
    |     When true (default), clicking a row opens the detail view. When
    |     false, rows are informational and users must use the View action.
    |     Override per resource via `rowClickOpensDetail(Request): bool`.
    |
    | default_trashed_filter
    |     Starting value of the "Incluir apagados" filter on resources that
    |     use soft deletes. Valid values:
    |         - 'active'  (default) : list only non-deleted records.
    |         - 'with'              : include deleted records alongside live.
    |         - 'only'              : only deleted records.
    */
    'index' => [
        'default_row_actions' => [
            'enabled' => env('MARTIS_DEFAULT_ROW_ACTIONS', true),
        ],

        'row_click_opens_detail' => env('MARTIS_ROW_CLICK_OPENS_DETAIL', true),

        'default_trashed_filter' => env('MARTIS_DEFAULT_TRASHED_FILTER', 'active'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Pagination
    |--------------------------------------------------------------------------
    */
    'pagination' => [
        'default_per_page' => 25,
        'max_per_page' => 100,
    ],

    /*
    |--------------------------------------------------------------------------
    | Storage
    |--------------------------------------------------------------------------
    | Configure the default storage disk for all Martis file operations.
    | This acts as the global fallback when no disk is explicitly specified
    | on a field, resource, or profile section.
    |
    | disk - Default filesystem disk (e.g. 'public', 'local', 's3').
    |        Individual sections (avatar.disk, attachments) fall back to this
    |        value when they are not explicitly configured.
    */
    'storage' => [
        'disk' => env('MARTIS_STORAGE_DISK', 'public'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Resources Path
    |--------------------------------------------------------------------------
    | Where auto-discovery looks for Martis resource classes in the app.
    */
    'resources_path' => app_path('Martis'),

    /*
    |--------------------------------------------------------------------------
    | Policy Namespace
    |--------------------------------------------------------------------------
    | Namespace for auto-discovery of Martis resource policies.
    | Override per-resource via the $policy static property on the Resource class.
    */
    'policy_namespace' => 'App\\Martis\\Policies',

    /*
    |--------------------------------------------------------------------------
    | Extensions Path
    |--------------------------------------------------------------------------
    | Directory where custom React components are created by martis:component.
    | Relative to the application's resource_path().
    | The vite build must point MARTIS_USER_DIR to this same directory.
    */
    'extensions_path' => env('MARTIS_EXTENSIONS_PATH', 'martis-extensions'),

    /*
    |--------------------------------------------------------------------------
    | Attachments
    |--------------------------------------------------------------------------
    | Configure allowed MIME types and disks for Trix/Markdown file uploads.
    | Add or remove extensions to control what can be uploaded inline.
    | Allowed disks restricts which storage disks the upload endpoint accepts.
    |*/
    'attachments' => [
        'allowed_mimes' => explode(',', env('MARTIS_ATTACHMENT_MIMES', 'jpg,jpeg,png,gif,webp,pdf,doc,docx,xls,xlsx,ppt,pptx,txt,csv,zip,mp4,mp3')),
        'allowed_disks' => ['public', 'local'],
        'max_size' => (int) env('MARTIS_ATTACHMENT_MAX_SIZE', 10240),
    ],

    /*
    |--------------------------------------------------------------------------
    | Action Events (Audit Log)
    |--------------------------------------------------------------------------
    | Configure the built-in action event logging system.
    |
    | enabled  - When false, no action events are recorded to the database.
    |            Individual actions can still opt out via withoutActionEvents().
    | resource - When true, the ActionEvent resource is registered in the admin
    |            panel sidebar so users can browse the audit log.
    */
    'action_events' => [
        'enabled' => (bool) env('MARTIS_ACTION_EVENTS_ENABLED', true),
        'resource' => (bool) env('MARTIS_ACTION_EVENTS_RESOURCE', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | Profile
    |--------------------------------------------------------------------------
    | Configure the user profile page (accessible via the user menu).
    |
    | enabled        - Set false to disable the profile page entirely.
    | resource       - FQCN of a custom ProfileResource class (null = default).
    | menu.label     - Label shown in the user dropdown menu.
    | menu.icon      - Phosphor icon name for the menu item.
    | avatar.enabled - Show/hide the avatar upload section.
    | avatar.disk    - Filesystem disk to store uploaded avatars.
    | avatar.path    - Sub-directory within the disk.
    | avatar.max_size_kb - Maximum upload size in kilobytes.
    | avatar.column  - Column on the users table that stores the avatar path.
    | avatar.url_resolver - Optional callable to generate the public URL.
    | two_factor.enabled  - Show/hide the 2FA section.
    | two_factor.recovery_codes - Number of one-time recovery codes generated.
    | sections       - Array of section keys to render (customize order/visibility).
    |                  Supported: 'account', 'password', 'avatar', 'security'
    */
    'profile' => [
        'enabled' => env('MARTIS_PROFILE_ENABLED', true),
        'resource' => null,
        'menu' => [
            'label' => null, // null = use i18n default
            'icon' => 'user',
        ],
        'avatar' => [
            'enabled' => env('MARTIS_AVATAR_ENABLED', true),
            'disk' => env('MARTIS_AVATAR_DISK', 'public'),
            'path' => env('MARTIS_AVATAR_PATH', 'avatars'),
            'max_size_kb' => (int) env('MARTIS_AVATAR_MAX_SIZE', 2048),
            'column' => env('MARTIS_AVATAR_COLUMN', 'profile_picture'),
            'url_resolver' => null,
        ],
        'two_factor' => [
            'enabled' => env('MARTIS_2FA_ENABLED', true),
            'recovery_codes' => (int) env('MARTIS_2FA_RECOVERY_CODES', 8),
        ],
        'sections' => ['avatar', 'account', 'password', 'security'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Loader
    |--------------------------------------------------------------------------
    | Configure the built-in loading indicator (MartisLoader).
    |
    | message        - Default loading text. null = use i18n default ('Loading...').
    | icon           - Phosphor icon name to replace the spinner (e.g. 'spinner').
    |                  When set, the named icon spins instead of the default SpinnerGap.
    | logo           - URL to a logo/image shown instead of the spinner.
    |                  Takes precedence over 'icon'.
    | spinnerColor   - CSS color for the spinner. Default: var(--martis-accent).
    | overlayOpacity - Overlay background opacity (0.0–1.0). Default: 0.6.
    | overlayColor   - CSS color for the overlay background. Default: var(--martis-bg).
    | disabled       - Set to true to globally disable all loaders.
    | disableOn      - Granular opt-out per context.
    |   table        - Disable the refetch overlay on index tables.
    |   search       - Disable the loader on search refetch.
    |   components   - Disable loaders inside other components.
    */
    'loader' => [
        'message' => null,
        'icon' => null,
        'logo' => null,
        'spinnerColor' => null,
        'overlayOpacity' => null,
        'overlayColor' => null,
        'disabled' => false,
        'disableOn' => [
            'table' => false,
            'search' => false,
            'components' => false,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Drawer overrides
    |--------------------------------------------------------------------------
    |
    | Package-wide defaults applied to every DrawerOverride (create, update,
    | detail) that does not override them explicitly. Setting the values
    | here — instead of chaining ->width('...') on every resource — keeps
    | the three drawers visually consistent by construction.
    */

    'drawer' => [
        'width' => '520px',
        'expanded_width' => '800px',
    ],

];
