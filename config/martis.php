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
    'locale' => env('MARTIS_LOCALE', 'en-US'),

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
    ],

    /*
    |--------------------------------------------------------------------------
    | User Menu
    |--------------------------------------------------------------------------
    | Configure what appears in the user profile context menu.
    | Set any option to false to hide it.
    |
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
        'showProfile' => true,
        'showNotifications' => true,
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
    | Configure the default dashboard layout.
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
];
