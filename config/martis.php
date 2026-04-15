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
    | API Throttle
    |--------------------------------------------------------------------------
    | Configure rate limiting for the Martis API routes.
    | Set enabled to false to disable throttling entirely.
    | max_attempts = maximum requests per decay_minutes window.
    */
    'throttle' => [
        'enabled' => env('MARTIS_THROTTLE_ENABLED', true),
        'max_attempts' => (int) env('MARTIS_THROTTLE_MAX', 120),
        'decay_minutes' => (int) env('MARTIS_THROTTLE_DECAY', 1),
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
        'showMetrics' => true,
        'showResourceCards' => true,
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
        'sections' => ['account', 'password', 'avatar', 'security'],
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

];
