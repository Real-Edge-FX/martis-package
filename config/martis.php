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
    | Locale extensibility
    |--------------------------------------------------------------------------
    | Knobs for the translations endpoint that consumers tweak when their
    | i18n needs go beyond the defaults shipped with Martis.
    |
    |   - `app_namespaces`: extra translation files in the host app's
    |     `lang/<locale>/<ns>.php`. Each name listed here is loaded for
    |     the requested locale and surfaced under its namespace key in
    |     the JSON payload, alongside the package's own namespaces.
    |     Default `[]` means no app-side namespaces are merged.
    |
    |   - `fallback_chain`: ordered list of locales searched when a key
    |     is missing in the requested locale. Applied in order, with
    |     `array_replace_recursive` so per-key overrides survive.
    |     Default `['en']` matches the historical behaviour. A multi-step
    |     example: `['pt_BR', 'en']` for `pt_PT` requests so European
    |     Portuguese first borrows from Brazilian, then from English.
    */
    'locales' => [
        'app_namespaces' => array_filter(
            array_map('trim', explode(',', (string) env('MARTIS_APP_LOCALE_NAMESPACES', ''))),
            static fn (string $ns): bool => $ns !== '',
        ),
        'fallback_chain' => array_filter(
            array_map('trim', explode(',', (string) env('MARTIS_LOCALE_FALLBACK_CHAIN', 'en'))),
            static fn (string $locale): bool => $locale !== '',
        ),
    ],

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
    | In-app Notifications (v0.8 — Task 12)
    |--------------------------------------------------------------------------
    | A persistent notification subsystem distinct from toasts. Backed by
    | Laravel's standard `notifications` table — any Notification class
    | that uses the `database` channel writes into the Martis bell
    | dropdown automatically, no extra wiring.
    |
    | The dropdown polls `/martis/api/notifications/unread-count` at the
    | configured interval to keep the badge in sync. Set the interval to
    | `0` to disable polling (consumers can drive refreshes manually
    | from their own code via React Query).
    */
    'notifications' => [
        'enabled' => env('MARTIS_NOTIFICATIONS_ENABLED', true),

        // Polling interval for the unread-count badge, in milliseconds.
        // Set to 0 to disable polling.
        'poll_interval' => env('MARTIS_NOTIFICATIONS_POLL_INTERVAL', 60000),

        // Maximum number of notifications shown in the dropdown panel.
        // The full list lives behind a "View all" link for users who
        // need to see older entries.
        'max_in_dropdown' => env('MARTIS_NOTIFICATIONS_MAX_DROPDOWN', 10),
    ],

    /*
    |--------------------------------------------------------------------------
    | Sticky Views (v0.8 — Task 15)
    |--------------------------------------------------------------------------
    | Persists per-user view state on resource index pages — filters,
    | sort, pagination, per-page selector and column visibility — so a
    | user who applies a filter, opens a record, and clicks back finds
    | the table exactly as they left it. URL query params remain the
    | source of truth (deep-linkable, shareable); sessionStorage is the
    | tab-scoped memory of the last state per resource.
    |
    | `scope` controls where the state is persisted:
    |   - `session` (default) — sessionStorage. Wipes on tab close.
    |   - `local`             — localStorage. Survives the tab.
    |   - `server`            — reserved for the next iteration; DB-backed.
    |
    | Per-resource opt-out via `protected static bool $stickyView = false`
    | on the Resource class. Per-page opt-out via the `persist` toggles
    | below (e.g. set `pagination` to false to keep page numbers
    | un-sticky while filters and sort persist).
    */
    'sticky_views' => [
        'enabled' => env('MARTIS_STICKY_VIEWS_ENABLED', true),
        'scope' => env('MARTIS_STICKY_VIEWS_SCOPE', 'session'),
        'persist' => [
            'filters' => true,
            'sorting' => true,
            'pagination' => true,
            'per_page' => true,
            'columns' => true,
            'scroll' => false,
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
    | showWelcomeCard   - Show the animated welcome hero card at the top of
    |                     the default dashboard. Displays the package version
    |                     resolved from the installed composer tag. Set to
    |                     false to hide the card.
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
        'showWelcomeCard' => env('MARTIS_DASHBOARD_SHOW_WELCOME_CARD', true),
        'showMetrics' => true,
        'showResourceCards' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | Authentication UI — optional flows
    |--------------------------------------------------------------------------
    |
    | Configure which alternative sign-in flows the Login page surfaces and
    | whether the self-service registration path is available. Each flow
    | follows the same shape:
    |
    |   enabled — renders the button / link on the Login page.
    |   url     — where the button / link redirects. When omitted, clicking
    |              the control shows a "not configured" toast so the
    |              programmer is reminded to wire the flow up.
    |
    | Registration gates both the `/register` route and the "Create an
    | account" link that appears underneath the Sign in button. Martis does
    | not ship a built-in registration controller — the consumer app is
    | expected to expose a POST endpoint (default convention:
    | `/martis/api/auth/register`) and pass its path / URL here if the form
    | should submit to a different location.
    |
    */
    'auth' => [
        /*
        |----------------------------------------------------------------------
        | SSO Subsystem (Task 14 ⭐ differential)
        |----------------------------------------------------------------------
        |
        | Per-provider SSO with three orthogonal configuration axes:
        |
        |   role_source        — where external roles come from
        |                        (`groups`, `app_role_assignments`, `callable`)
        |   role_strategy      — how to map external → local roles
        |                        (`column`, `config`, `callable`)
        |   permission_adapter — how to write the local roles back onto
        |                        the user (`auto`, `spatie`, `native`,
        |                        `callable`)
        |
        | Use `php artisan martis:sso azure` to scaffold a provider
        | block, or hand-craft any combination here. See `docs/sso.md`
        | for the full reference and the four canonical recipes.
        */
        'sso' => [
            'enabled' => env('MARTIS_SSO_ENABLED', false),

            'providers' => [
                // Microsoft Azure AD example block — flip MARTIS_SSO_AZURE_ENABLED
                // and fill the AZURE_* env vars to activate.
                // 'azure' => [
                //     'enabled' => env('MARTIS_SSO_AZURE_ENABLED', false),
                //     'driver' => 'azure',
                //     'label' => 'Continue with Microsoft',
                //     'icon' => 'microsoft-outlook-logo',
                //     'scopes' => [
                //         'openid', 'profile', 'email',
                //         'GroupMember.Read.All',
                //         'User.ReadBasic.All',
                //     ],
                //
                //     'role_source' => 'app_role_assignments',
                //     'resource_id' => env('AZURE_RESOURCE_ID'),
                //
                //     'role_strategy' => 'column',
                //     'role_column' => 'azure_group_name',
                //
                //     'auto_create_user' => true,
                //     'identity_match_attribute' => 'email',
                //     'sync_user_attributes' => ['name', 'email'],
                //
                //     'sync_roles' => true,
                //     'permission_adapter' => 'auto',
                //
                //     'on_no_role_match' => 'deny',
                //     'redirect_to' => null,
                // ],
            ],
        ],

        'passwordReset' => [
            'enabled' => env('MARTIS_AUTH_PASSWORD_RESET_ENABLED', false),
            'url' => env('MARTIS_AUTH_PASSWORD_RESET_URL'),
        ],
        'registration' => [
            'enabled' => env('MARTIS_AUTH_REGISTRATION_ENABLED', false),
            'url' => env('MARTIS_AUTH_REGISTRATION_URL'),
        ],
        // Compact guest-mode controls rendered in the top-right of every
        // auth surface (Login, Register, 2FA challenge, error pages).
        // Each toggle hides its widget without removing the underlying
        // preference: a hidden language picker still keeps the locale
        // applied, a hidden theme button still respects the configured
        // default. Set both to false on single-locale, single-theme
        // deployments so the pre-login screens stay clean.
        'controls' => [
            'theme' => env('MARTIS_AUTH_CONTROL_THEME', true),
            'locale' => env('MARTIS_AUTH_CONTROL_LOCALE', true),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Cache — Martis Extension (Task 17 ⭐ runtime control)
    |--------------------------------------------------------------------------
    |
    | Per-subsystem cache layer with three control planes:
    |   1. Config (this file).
    |   2. Env vars (override per environment).
    |   3. Runtime (Artisan + admin panel — overrides survive restart, no
    |      deploy required).
    |
    | `enabled` is the master switch. When false, every Martis cache is
    | bypassed regardless of per-type values.
    |
    | Each subsystem accepts the modern shape `['enabled' => bool, 'ttl' =>
    | int|null]` (TTL in minutes, null means "no expiration"). The legacy
    | shape — bare int = TTL with cache enabled, null = disabled — is still
    | accepted for backward compatibility.
    |
    | Bypass per-request:
    |   • Header `X-Martis-No-Cache: 1`
    |   • Query param `?nocache=1`
    |
    */

    'cache' => [
        'enabled' => env('MARTIS_CACHE_ENABLED', true),

        'metrics' => [
            'enabled' => env('MARTIS_CACHE_METRICS_ENABLED', true),
            'ttl' => env('MARTIS_CACHE_METRICS_TTL', env('MARTIS_CACHE_METRICS', 5)),
        ],
        'navigation' => [
            'enabled' => env('MARTIS_CACHE_NAVIGATION_ENABLED', true),
            'ttl' => env('MARTIS_CACHE_NAVIGATION_TTL', env('MARTIS_CACHE_NAVIGATION', 1)),
        ],
        'dashboards' => [
            'enabled' => env('MARTIS_CACHE_DASHBOARDS_ENABLED', true),
            'ttl' => env('MARTIS_CACHE_DASHBOARDS_TTL', env('MARTIS_CACHE_DASHBOARDS', null)),
        ],
        'schema' => [
            'enabled' => env('MARTIS_CACHE_SCHEMA_ENABLED', true),
            'ttl' => env('MARTIS_CACHE_SCHEMA_TTL', env('MARTIS_CACHE_SCHEMA', null)),
        ],

        // When true, Martis registers `/api/cache/*` admin endpoints and
        // surfaces the "Sistema → Cache" page. The Gate `manage-martis-cache`
        // still has to pass for any user to actually reach the page; by
        // default the gate allows any authenticated user — apps should
        // tighten it in their `AppServiceProvider`:
        //
        //   Gate::define('manage-martis-cache', fn ($u) => $u->is_admin);
        //
        'admin_ui' => env('MARTIS_CACHE_ADMIN_UI', true),
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

        /*
         | Master switch for the per-type column-width heuristics (Id → 80px,
         | Email/Url → maxWidth 280px + truncate, Date → 140px, title column →
         | minWidth 220px, etc.). When `false`, Martis ships the pre-v0.7.0
         | behaviour — every column auto-sizes and nothing truncates.
         | Explicit per-field calls like `->width()` / `->truncate()` still
         | apply regardless.
         */
        'column_defaults' => env('MARTIS_INDEX_COLUMN_DEFAULTS', true),
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
        'width' => '720px',
        'expanded_width' => '960px',
        // When `false`, the expand + fullscreen buttons are suppressed on
        // every drawer regardless of per-instance `allowExpand` /
        // `allowFullscreen` props. Lets an app lock the drawer to a single
        // width without auditing every resource that registers a
        // `DrawerOverride`.
        'expandable' => env('MARTIS_DRAWER_EXPANDABLE', true),
    ],

];
