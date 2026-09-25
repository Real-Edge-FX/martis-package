# Installation Guide

Add Martis to any existing Laravel application as a Composer package.

## Requirements

| Requirement | Minimum Version |
|-------------|-----------------|
| PHP | 8.3+ |
| Laravel | 12.x or 13.x |
| Node.js | 20+ (contributors only) |
| npm | the version bundled with Node.js (contributors only; the repository ships `package-lock.json` and CI runs `npm ci`) |
| Database | Any driver Laravel supports — MySQL, PostgreSQL, SQLite, or SQL Server (no specific version required) |
| Redis | 7+ (optional, for cache/queue) |

Martis is database-agnostic: it issues no driver-specific SQL on its query paths (metric period bucketing, for example, runs in PHP), so any database your Laravel app already uses works. The CI matrix runs Pest against PHP 8.3 and 8.4 on Laravel 12 and 13. End-user apps consume the precompiled frontend assets — no Node toolchain required.

## Quick Install

The fastest way to get started is the `martis:install` Artisan command. It handles everything in one step:

```bash
composer require martis/martis
php artisan martis:install
```

This is the complete end-user install flow. The consuming Laravel application does not need to run Vite, install Node dependencies, or build frontend assets for Martis.

This command performs the following steps automatically:

1. **Creates the directory structure** — `app/Martis/` for your resource definitions.
2. **Publishes the config file** — `config/martis.php` with all customizable settings.
3. **Publishes the host MartisServiceProvider** — `app/Providers/MartisServiceProvider.php` and wires it into `bootstrap/providers.php`. This is where consumer code that cannot live in `config/martis.php` (closures, gate definitions, menu items, dashboards, runtime cache layers) is registered. See [Host MartisServiceProvider](#host-martisserviceprovider) below.
4. **Publishes frontend assets** — precompiled React app to `public/vendor/martis/`.
5. **Publishes the core migrations** — `create_martis_action_events_table`, the two action-events morph id conversions (`alter_martis_action_events_morph_ids_to_string`, `fix_martis_action_events_morph_ids_string_v2`), `create_martis_user_preferences_table`, `drop_dashboards_layout_from_user_preferences_table`, `create_notifications_table` and `create_martis_cache_state_table`. A migration already present (matched by its `*_<name>.php` suffix) is skipped.
6. **Publishes translation files** — `en`, `pt_BR`, `pt_PT` to `lang/vendor/martis/`.
7. **Runs database migrations** — `php artisan migrate --force`, which creates the `martis_action_events`, `martis_user_preferences`, `notifications` and `martis_cache_state` tables. Note that it applies **every** pending migration of the application, not only the Martis ones, without the production confirmation prompt.

> **Upgrading from pre-0.7.0**: If you already have an `action_events` table, the new migration detects it and performs an in-place `RENAME` to `martis_action_events`. No data loss. The `martis_` prefix keeps every package-owned table in one namespace so it never collides with an app's own tables.

After installation, create an admin user:

```bash
php artisan martis:user
# Visit http://your-app.test/martis
```

The command prompts for the email, name and password it does not receive as
options (`--email`, `--name`, `--password`). By default it is **create-only**:
when a user with that email already exists it prints an error and exits with a
non-zero status, so a script cannot accidentally overwrite an account.

Two flags change what happens when the email already exists, which is what a
container entrypoint or a provisioning script needs to bootstrap the first
administrator on every boot:

| Flag | When the email already exists |
|------|-------------------------------|
| `--if-missing` | Exit `0` with an info line and change nothing. Safe to call unconditionally at boot. |
| `--update` | Re-hash the password from `--password` (or the prompt) and, only when `--name` is given, replace the name. `email_verified_at` is left untouched. When the email does not exist yet the user is created, so the flag behaves like an upsert. |

```bash
# Guarantee an administrator exists, never touch it afterwards
php artisan martis:user --email="$MARTIS_ADMIN_EMAIL" --name="Admin" --password="$MARTIS_ADMIN_PASSWORD" --if-missing

# Converge the administrator to the current environment (rotated password)
php artisan martis:user --email="$MARTIS_ADMIN_EMAIL" --password="$MARTIS_ADMIN_PASSWORD" --update
```

Without either flag the behaviour is unchanged: the second run of the same
command fails with `A user with email [...] already exists.`

### Install Options

| Flag | Effect |
|------|--------|
| `--force` | Overwrite previously published migrations, translations and the extension scaffold (Vite config, both tsconfig files, `index.ts`, the shims and their declarations); `config/martis.php` and the host provider stay unless you add `--force-config` / `--force-provider` |
| `--force-config` | Republish `config/martis.php`, overwriting your changes to it |
| `--force-provider` | Republish `app/Providers/MartisServiceProvider.php`, overwriting your changes to it |
| `--with-profile` | Enable profile support and publish the avatar column migration (`add_profile_picture_column`); when the `sessions` profile section is active it also publishes the sessions table migration |
| `--no-profile` | Disable profile support, even when running interactively; wins over `--with-profile` |
| `--with-2fa` | Enable two-factor support and publish the 2FA columns migration (`*_add_martis_two_factor_columns_to_users_table.php`); independent of `--with-profile` |
| `--no-2fa` | Disable two-factor support; wins over `--with-2fa` |
| `--with-sessions` | Publish the sessions table migration for the browser-sessions profile section (requires `SESSION_DRIVER=database`) |
| `--no-sessions` | Skip the sessions table migration; wins over `--with-sessions` |
| `--avatar-column=<column>` | Customize which `users` table column Martis should use for avatar paths |
| `--existing-avatar-column` | Use an existing avatar column on `users` instead of publishing a migration |

### Optional Profile Support

By default, `martis:install` always installs the core Martis package, the `martis_action_events` audit log, and the `martis_user_preferences` table (theme/accent/density/locale/reduced-motion persistence). Profile support is optional because some applications already have their own avatar column strategy.

To install Martis with profile and two-factor support:

```bash
php artisan martis:install --with-profile --with-2fa
```

The two flags are independent switches: `--with-profile` publishes the avatar column migration (`add_profile_picture_column`), and `--with-2fa` publishes the two-factor columns migration (`*_add_martis_two_factor_columns_to_users_table.php`). Each stub is idempotent:

- the avatar column is added only if it does not already exist
- the 2FA columns are added only if they do not already exist
- re-running `martis:install` with the same flags does not create duplicate migration files

If you want only the 2FA columns and not the avatar migration, pass `--with-2fa` without `--with-profile`.

If your application already stores avatar paths in a different `users` column, pass that column name when installing:

```bash
php artisan martis:install --with-profile --avatar-column=avatar_path
```

Then set the same column in your environment:

```env
MARTIS_AVATAR_COLUMN=avatar_path
```

If the column already exists on `users` and you do **not** want a migration:

```bash
php artisan martis:install --with-profile --existing-avatar-column --avatar-column=avatar_path
```

The installer only prompts when it runs interactively **and** STDIN is a real TTY. In CI, Docker setup scripts (`docker compose exec -T`), deployment hooks or an AI agent's shell there is no prompt: every optional feature you do not pass a flag for resolves to disabled. The resolved values are also written to `.env` (`MARTIS_PROFILE_ENABLED`, `MARTIS_AVATAR_ENABLED`, `MARTIS_2FA_ENABLED`, `MARTIS_SHOW_PROFILE_MENU`) on every run, and a disabled value in the config wins over `--with-*` on the next run. Pass the flags explicitly:

```bash
php artisan martis:install --force --no-interaction --with-profile --with-2fa
```

If a previous run already wrote `false`, set those keys back to `true` in `.env` (or remove them), run `php artisan config:clear`, then re-run the command with the flags.

## Manual Install (Step by Step)

If you prefer granular control, you can run each publish step individually.

### Step 1: Install via Composer

```bash
composer require martis/martis
```

Martis registers its service provider automatically via Laravel's package discovery.

### Step 2: Publish Configuration

```bash
php artisan vendor:publish --tag=martis-config
```

This creates `config/martis.php`. The most commonly customized keys:

- `path` — Admin panel URL prefix (default: `martis`)
- `guard` — Authentication guard (default: `null`, falls back to `config('auth.defaults.guard')`)
- `resources_path` — Directory scanned for resource classes (default: `app_path('Martis')`); `resources_namespace` pins its namespace when it cannot be derived from Composer's PSR-4 map (default: `null`, derived)
- `theme.default` — Default theme: `dark`, `light`, or `system`
- `theme.allowToggle` — Allow users to switch themes from the preferences panel
- `preferences.defaults.locale` — Default panel language for users without a saved preference (`MARTIS_DEFAULT_LOCALE`, default `en`; it must be listed in `preferences.locales`). The `martis.locale` middleware applies it (or the user's saved preference) on every authenticated Martis route, so this, not `APP_LOCALE`, decides the panel language.
- `locale` — Read by the blade shell only when `preferences.enabled` is `false` (`MARTIS_LOCALE`, falling back to `APP_LOCALE`, then `en`)
- `brand.name` / `brand.logo` / `brand.icon` / `brand.favicon` — Brand block (see [Configuration](configuration.md))
- `layout.preset` — Layout preset (`sidebar`, `topnav`, `minimal`)
- `footer` / `search` / `auth` / `preferences` / `cache` — Subsystem configuration

> **Locales**: Martis ships translations for `en`, `pt_BR`, `pt_PT`. There is no `available_locales` config key — Laravel's own locale resolution applies, and any extra locales you add to `lang/vendor/martis/<locale>/` are picked up automatically. The picker shown on the user preferences panel is built from `meta.locales` returned by the preferences resolver, which inspects what locales actually have files.

### Step 3: Publish the Host MartisServiceProvider

```bash
php artisan vendor:publish --tag=martis-provider
```

Creates `app/Providers/MartisServiceProvider.php`. The `martis:install` command runs this and wires it into `bootstrap/providers.php` automatically. See [Host MartisServiceProvider](#host-martisserviceprovider) for what to put in it.

### Step 4: Publish Frontend Assets

```bash
php artisan martis:publish-assets
```

This copies the precompiled React application to `public/vendor/martis/`. End users do not need to run Vite in the consuming Laravel app.

The command **wipes `public/vendor/martis/` first** so stale Vite-hashed chunks from previous package versions never accumulate, then does a deterministic full-tree copy. Laravel's stock `vendor:publish --tag=martis-assets --force` is a merge-style copy and would otherwise pile up tens of thousands of orphan files across upgrades — enough on macOS Docker bind mounts to slow every PHP-FPM request to several seconds. Pass `--no-wipe` to opt back into the legacy merge behaviour if you have a specific reason to.

Since **v1.29.1** the command also **verifies completeness**: after copying it checks that every file the published `manifest.json` references (the app entry bundle, its CSS, every chunk) exists in the destination, and exits non-zero with the missing count if any are absent — so a partial publish is caught here rather than surfacing as a black-screen admin at runtime.

Last, it publishes your custom themes: every `resources/css/martis/<name>.css` is copied to `public/vendor/martis/themes/`, where the panel loads it. Commit the sources; the published copies are written again on every run, so edit the source, never the copy. Before it replaces or removes a file there that it cannot write back, such as a copy edited in place, it backs it up to `storage/app/martis/theme-backups/` and says so, and it stops, changing nothing, when the run would take away the theme `martis.theme.name` names or remove the copy of a theme source it cannot read. A `public/vendor/martis/` or `public/vendor/martis/themes/` that is a symlink is replaced by a real directory, never written through. `php artisan martis:publish-assets --themes-only` publishes the themes alone, and the stock `vendor:publish --tag=martis-assets` never publishes them. See [Theming → Theme files](theming.md#theme-files).

Equivalent: `php artisan martis:vendor-publish --assets` performs the same wipe-then-publish flow, as does `martis:install` — all three share this single hardened path.

Important:

- `composer require`, `composer install`, and `composer update` do **not** automatically refresh files already published into `public/vendor/martis/`
- after installing or upgrading Martis, run `martis:install` or `martis:publish-assets`
- this is standard behavior for Laravel packages that publish static files into the host application

### Step 5: Publish and Run Migrations

The package ships its migration stubs under several publish tags so consumers can opt in to each subsystem.

| Tag | Publishes |
|---|---|
| `martis-migrations` | `create_martis_action_events_table` (audit log — required), `create_martis_user_preferences_table`, `create_martis_cache_state_table` and `drop_dashboards_layout_from_user_preferences_table` |
| `martis-preferences-migration` | `create_martis_user_preferences_table` (per-user theme / locale / density / accent / reduced-motion) |
| `martis-preferences-drop-dashboards-layout-migration` | `drop_dashboards_layout_from_user_preferences_table` |
| `martis-cache-state-migration` | `create_martis_cache_state_table` (persisted cache versions and runtime kill-switches) |
| `martis-2fa-migration` | `add_two_factor_columns` to `users` (TOTP secret + recovery codes) |
| `martis-avatar-migration` | `add_profile_picture_column` to `users` (filename of the uploaded avatar) |
| `martis-sessions-migration` | `create_sessions_table` (browser-sessions profile section, `SESSION_DRIVER=database`) |
| `martis-invitations-migration` | `create_invitations_table` |

No tag publishes `create_notifications_table` or the two action-events morph id conversions (`alter_martis_action_events_morph_ids_to_string`, `fix_martis_action_events_morph_ids_string_v2`, needed for UUID / ULID keyed models): only `martis:install` publishes them. Tag-published files are named `<date>_00000N_<name>.php`; `martis:install` recognises an existing migration by its `*_<name>.php` suffix and skips it, but a tag publish does not look for a differently dated copy, so publishing a tag after `martis:install` can add a second copy of the same migration. Prefer `martis:install` (without `--force`) to add missing migrations.

Recommended sequence for a manual install:

```bash
php artisan vendor:publish --tag=martis-migrations
php artisan vendor:publish --tag=martis-preferences-migration
php artisan migrate
```

The action-events stub is idempotent — if you already have a legacy `action_events` table, it is renamed in place to `martis_action_events`.

If you also want avatar + 2FA support:

```bash
php artisan vendor:publish --tag=martis-2fa-migration
php artisan vendor:publish --tag=martis-avatar-migration
php artisan migrate
```

`martis:install` publishes the core migrations on every run, the avatar migration with `--with-profile` (as `add_profile_picture_column`) and the two-factor migration with `--with-2fa` (as `*_add_martis_two_factor_columns_to_users_table.php`).

#### UUID / ULID / custom user PKs (v1.12.2+)

The published migrations adapt the `user_id` column (and the polymorphic `notifiable_id` on the notifications table) to whichever primary-key shape your host `users` table uses. The adaptation happens at migration time — each stub introspects the configured user model (`auth.providers.{provider}.model`) and picks the matching column helper:

| User model | `user_id` column |
|---|---|
| Default Laravel (auto-incrementing `bigint`) | `foreignId('user_id')->constrained()` |
| `use Illuminate\Database\Eloquent\Concerns\HasUuids;` | `foreignUuid('user_id')->constrained()` |
| `use Illuminate\Database\Eloquent\Concerns\HasUlids;` | `foreignUlid('user_id')->constrained()` |
| `$keyType = 'string'` without `HasUuids` / `HasUlids` | `string('user_id')` + explicit `foreign()` |

The polymorphic columns on `notifications` follow the same rule (`morphs` / `uuidMorphs` / `ulidMorphs`).

If your project uses a non-standard combination — for example, a custom string PK that does not register either canonical trait — set the `MARTIS_USER_ID_COLUMN_TYPE` env var to force the resolver. Accepted values: `bigint`, `uuid`, `ulid`, `string`.

```bash
MARTIS_USER_ID_COLUMN_TYPE=uuid php artisan martis:install
```

The env override always beats auto-detection. Mal-formed values (anything outside the four accepted options) are ignored and the auto-detection runs as if the variable were unset. Default `null` keeps the auto-detection on, which is the right behaviour for the overwhelming majority of host apps.

### Step 6: Publish Translations (Optional)

```bash
php artisan vendor:publish --tag=martis-lang
```

Copies language files to `lang/vendor/martis/` so you can override Martis-shipped strings (auth copy, dashboard greeting, validation messages, sidebar labels, …). Available locales out of the box: `en`, `pt_BR`, `pt_PT`. Laravel deep-merges the published files with the package shipped originals — only override the keys you need.

### Step 7: Publish Views (Optional)

```bash
php artisan vendor:publish --tag=martis-views
```

Copies the Martis blade templates to `resources/views/vendor/martis/`. Rare — only required if you need to fork the SPA shell template itself. The component override system (see [Override System](overrides.md)) is the preferred extension path.

### Step 8: Create Your First Resource

Create a resource file at `app/Martis/UserResource.php`:

```php
<?php

namespace App\Martis;

use App\Models\User;
use Illuminate\Http\Request;
use Martis\Fields\Boolean;
use Martis\Fields\DateTime;
use Martis\Fields\Email;
use Martis\Fields\Id;
use Martis\Fields\Password;
use Martis\Fields\Text;
use Martis\Resource;

class UserResource extends Resource
{
    public static function model(): string
    {
        return User::class;
    }

    public static function titleAttribute(): string
    {
        return 'name';
    }

    public function icon(): string
    {
        return 'users';
    }

    public function fields(Request $request): array
    {
        return [
            Id::make('id'),
            Text::make('name')->sortable()->searchable()->required(),
            Email::make('email')->sortable()->searchable()->required()
                ->unique(['users', 'email'], 'Email already exists.'),
            Password::make('password')->hideFromIndex()->hideFromDetail(),
            Boolean::make('is_admin', 'Administrator'),
            DateTime::make('created_at', 'Registered')->hideFromForms()->sortable(),
        ];
    }
}
```

Resources are **auto-discovered** — no manual registration needed. Martis scans `config('martis.resources_path')` (default `app/Martis/`) recursively, mapping files to classes under the namespace Composer's PSR-4 map gives that directory (or `config('martis.resources_namespace')` when set). Subdirectories (`Resources/`, `Lenses/`, `Filters/`, …) are a convention for large projects — they are NOT created or required by the install. Place each class wherever its namespace puts it.

### Step 9: Access the Admin Panel

Navigate to `http://your-app.test/martis` and log in with any user from your application.

## Host MartisServiceProvider

`martis:install` publishes `app/Providers/MartisServiceProvider.php` and wires it into `bootstrap/providers.php`. This file is where consumer code that **cannot live in `config/martis.php`** is registered:

- The main menu (`Martis::menu(...)`) — closures cannot survive `config:cache`
- Dashboards (`Martis::dashboards([...])`) — same reason
- Tools (`Martis::tools([...])`)
- Cache layer registrations
- Authorization gates (`Gate::define(...)`)
- Custom event listeners

Re-publish the stub manually with:

```bash
php artisan vendor:publish --tag=martis-provider
```

Without this provider, you can still ship a working Martis install relying purely on `config/martis.php` and auto-discovered resources, but every closure-driven feature will be unavailable.

> **`martis:install --force` does not touch this file (v1.10.2+).** The default `--force` flag rewrites the extension scaffold (Vite config, both tsconfig files, `index.ts`, the shims and their declarations), republishes `lang/vendor/martis` and rewrites the published Martis migrations, but never overwrites the host provider, where dashboards, menus, gates, and cache-layer registrations live. To republish the stub on top of your customisations, opt in explicitly with `--force-provider`:
>
> ```bash
> php artisan martis:install --force-provider
> ```
>
> The same split applies to `config/martis.php` (`--force-config`). Each flag is independent, so refreshing the extension scaffold never destroys host-app customisations by accident.

## Custom React extensions (zero-config, v1.9.0+)

Custom React components — Tools, override components, field renderers, custom Cards — register at runtime through the **runtime extension loader** (v1.8.19+) wired up to a **filesystem auto-discovery convention** (v1.9.0+). The dev runs a generator and a build; everything else is plumbing the package handles.

### What `martis:install` publishes

Once you run `php artisan martis:install`, the consumer app gets the entire extension scaffold for free:

```
your-app/
├── vite.extensions.config.ts                  # Vite library mode, react externalised
├── tsconfig.extensions.json                   # TS config for the extension sources: npx tsc -p tsconfig.extensions.json
├── package.json                               # gains: "build:extensions": "vite build --config vite.extensions.config.ts"
├── .env                                       # gains: MARTIS_EXTENSIONS=/vendor/martis-user/extensions.js
└── resources/js/martis-extensions/
    ├── index.ts                               # auto-discovery entry — picks up everything below
    ├── .shims/                                # the host modules the build imports (*.mjs) and their types (*.d.mts)
    ├── tsconfig.json                          # points editors at tsconfig.extensions.json
    ├── tools/                                 # martis:tool --with-component drops files here
    ├── fields/                                # martis:field drops files here
    ├── cards/                                 # martis:card drops files here
    └── overrides/                             # martis:component drops files here
```

The published `index.ts` uses `import.meta.glob` to register every `.tsx` under the four buckets against `window.Martis.componentRegistry`. The component key is derived from the filename, in kebab case with an acronym kept whole:

| File path                             | Registered key       |
|---------------------------------------|----------------------|
| `tools/Charts.tsx`                    | `tool:charts`        |
| `tools/SystemHealth.tsx`              | `tool:system-health` |
| `tools/SEOReport.tsx`                 | `tool:seo-report`    |
| `cards/RevenueGauge.tsx`              | `card:revenue-gauge` |
| `fields/PriceTag.tsx` (`Display`/`Input` named exports) | the `price-tag` field type: `field:display:price-tag` and `field:input:price-tag` |
| `overrides/Sidebar.tsx`               | `layout:sidebar`     |
| `overrides/LoginPage.tsx`             | `auth:login`         |

The PHP classes the generators write bind to the same keys: a Tool with `withComponent('tool:charts')`, a card with `componentKey('card:revenue-gauge')`, a field whose `type()` returns `price-tag`. Filename and key stay in lock-step. **No manual `componentRegistry.register(...)` calls**: drop the file in the right bucket, run `npm run build:extensions`, and the component is live. A Tool bound to another key (`martis:tool --component-key`) is the exception: the command prints the `register()` call to add to `index.ts`.

### Vite + `@vitejs/plugin-react` compatibility (v1.12.1+)

`martis:install` adds the npm devDependencies the extension build needs (`@vitejs/plugin-react`, `vite`, `typescript`, `@types/*`, `@phosphor-icons/react`). The `vite` + `@vitejs/plugin-react` pair is peer-dep-coupled: a new major of `@vitejs/plugin-react` typically requires a new major of `vite`, and a mismatch produces either `npm install` `ERESOLVE` errors or runtime build crashes.

To keep installs safe, the installer reads your host's existing `devDependencies.vite` and picks the matching `@vitejs/plugin-react` range from a known-compat table:

| Host Vite major | `@vitejs/plugin-react` range written |
|-----------------|--------------------------------------|
| `^4`            | `^4`                                 |
| `^5`            | `^4 \|\| ^5`                          |
| `^6`            | `^5`                                  |
| `^7` (Laravel 12 default) | `^5`                        |
| `^8`            | `^6`                                  |
| `^9`            | `^7`                                  |

If your host runs a Vite major that is NOT in the table (e.g. you upgraded ahead of the installed Martis release), the installer **skips** writing `@vitejs/plugin-react` and prints a loud warning with a paste-ready unblock recipe. Existing `@vitejs/plugin-react` entries are never overwritten.

#### Overriding the table

If you know which `@vitejs/plugin-react` release is compatible with your Vite major but Martis does not, set the `MARTIS_PLUGIN_REACT_RANGE` env var before running the installer. The resolver writes the value verbatim and skips the table lookup entirely:

```bash
# Vite 10 + plugin-react 7 (hypothetical example)
MARTIS_PLUGIN_REACT_RANGE='^7' php artisan martis:install
```

The override beats every other code path including the unknown-Vite warning. Mal-formed values are rejected (with a clear message) and the installer falls back to "skip + warn" — it never writes a constraint it cannot parse.

### The five-minute Tool path

```bash
php artisan martis:tool Charts --with-component
npm run build:extensions
```

That's it. The Tool is auto-registered (since v1.8.20), the React component is auto-registered, and the sidebar surfaces it under the default "Tools" header (or whatever you pass to `withMenuSection('Operations')` in the constructor; `withSystemSection()` docks it in the bundled "System" section instead, v1.35.0+, see [Tools](tools.md#place-a-tool-under-system--withsystemsection-v1350)).

### Collision detection

Each generator (`martis:tool`, `martis:field`, `martis:card`, `martis:component`) checks for both the destination PHP file AND the destination TSX file before writing. When either exists, the command lists the conflicting paths and asks `[y/N]` whether to overwrite. `--force` skips the prompt. In a non-interactive shell (e.g. CI) the command aborts with an error code unless `--force` was passed.

### How the registry is exposed

At SPA boot, before it loads the extension bundles, the SPA fills `window.Martis`:

```js
window.Martis = {
  componentRegistry,   // the registry the SPA resolves from (also `componentRegistry` on @martis/runtime)
  react,               // the React module instance bundled with Martis
  reactJsxRuntime,     // react/jsx-runtime, read by the JSX shim
  runtime,             // the @martis/runtime surface the shims re-export
  version,             // "1.9.0" etc.
  shortcuts,           // the keyboard-shortcut helpers as add, remove, list (addShortcut, disableShortcut, listShortcuts on @martis/runtime)
}
```

The shims and the scaffold's `index.ts` read this global, so a consumer extension registers components and shortcuts without bundling its own copy of `componentRegistry`, the shortcut registry or React. In your own code, import them from `@martis/runtime`, which is typed: `window.Martis` is typed in an extension only as far as the `declare global` in `index.ts` goes (`componentRegistry.register`).

### Configuring multiple bundle URLs

The auto-published `MARTIS_EXTENSIONS` line points at a single bundle (`/vendor/martis-user/extensions.js`). To load additional bundles — e.g. a Composer-distributed package's prebuilt extensions, or a separate dev/staging override — comma-separate them:

```env
MARTIS_EXTENSIONS=/vendor/martis-user/extensions.js,/vendor/another/lib.js
```

The blade view emits the resolved array as `window.MartisConfig.extensions`. The SPA loops over it and dynamic-imports each via `import(url)`. Failures are isolated — one broken extension can't take down the whole panel; the error is logged with the URL.

### Type-checking your extensions

`tsconfig.extensions.json` covers the sources under `resources/js/martis-extensions/`. Check them with:

```bash
npx tsc -p tsconfig.extensions.json
```

Your app installs none of `@martis/runtime`, `react-router-dom`, `react-i18next` or `@tanstack/react-query`: the Vite config sends each to a shim under `.shims/` that re-exports the host's copy. It sends `react-dom` to a shim too (v1.38.0), which carries `createPortal` and, since v1.38.2, `flushSync`, the parts of `react-dom` the runtime serves, so a portal or a synchronous flush runs on the host's React DOM. Each of those shims has its TypeScript declarations next to it (`runtime.d.mts`, `react-dom.d.mts`, `react-router-dom.d.mts`, `react-i18next.d.mts`, `tanstack-react-query.d.mts`, since v1.38.0), and the tsconfig `paths` sends the same specifiers to them, the legacy paths included, so `tsc` checks your code against the modules the build uses: a name a shim does not export fails `tsc` as it fails the build. The declarations carry the Martis types and those of the host's copy of each library, and export each library's own types (its interfaces and type aliases, such as `import type { UseQueryResult } from '@tanstack/react-query'`), so a type your code took from a copy of the library in `node_modules` still resolves once the `paths` send the specifier to the declarations. A class or enum the shim does not export (`QueryCache`, `NavigationType`) is not declared, since the build has no value for it; the default export, the host's module, holds it (`import type ReactRouterDom from 'react-router-dom'`, then `ReactRouterDom.NavigationType`). `react` and `@phosphor-icons/react` come from your own `node_modules`, where `martis:install` adds them.

`react` itself is typed by your own `@types/react`, but an extension runs on the host's React, which is React 18: the Vite config sends `react` to a shim of it. `martis:install` adds `@types/react` and `@types/react-dom` at `^18` (v1.38.0; before, `^18 || ^19` installed the React 19 types). With the React 19 types an app may keep for its own code, `use`, `useActionState` and `useOptimistic` type-check and build, then are `undefined` in the extension.

Editors type a file with the nearest `tsconfig.json`, so the scaffold also puts one in `resources/js/martis-extensions/` that extends `tsconfig.extensions.json` (v1.38.0): VS Code and other tsserver clients resolve `@martis/runtime` the same way `tsc` does, and `npx tsc -p resources/js/martis-extensions` is equivalent to the command above.

The tsconfig is browser-only (`"types": ["vite/client"]`, no `@types/node`) and does not cover `vite.extensions.config.ts`. If your app has its own `tsconfig.json` that includes `resources/js` (the Laravel React starter kit does), exclude `resources/js/martis-extensions` from it: that config does not know the aliases, so it reports `Cannot find module '@martis/runtime'`.

### Refreshing the extension scaffold after an upgrade

The scaffold files (`vite.extensions.config.ts`, `tsconfig.extensions.json`, `resources/js/martis-extensions/index.ts`, `resources/js/martis-extensions/tsconfig.json` and the shims under `resources/js/martis-extensions/.shims/` with their declarations) are copied into your app once. `composer update` does not touch them, and `martis:install` skips every file that already exists unless you pass `--force`, which rewrites all of them (the files in the four buckets are never touched).

Your extension build resolves `@martis/runtime` to `.shims/runtime.mjs`, which re-exports the members of `window.Martis.runtime` by name. A name the runtime gains in a later Martis version can be imported by name only once your copy of that file exports it. Until then the build stops with:

```
"Dropdown" is not exported by "resources/js/martis-extensions/.shims/runtime.mjs"
```

| Named export | In the shim since |
|---|---|
| `useAuth`, `useToast`, `useToastSafe`, `useIsMobile`, `TwoFactorRequiredError`, `EmailVerificationRequiredError`, `AuthProvider`, `api`, `ApiError`, `config`, `AuthFrame`, `Sidebar`, `Topbar`, `Footer`, and the `react-router-dom`, `react-i18next` and `@tanstack/react-query` re-exports (`Link`, `useNavigate`, `useTranslation`, `useQuery`, …) | v1.10.0 |
| `FieldInput`, `FieldDisplay`, `DrawerShell`, `Tooltip` | v1.19.0 |
| `useMartisForm`, `FieldsForm`, `useToolFields` | v1.20.0 |
| `martisEventBus` | v1.21.0 |
| `useRevalidateOnFocus` | v1.22.0 |
| `NestedParentProvider`, `Dropdown`, `MultiSelect`, `createPortal`, the registries (`componentRegistry`, `iconRegistry`, `layoutRegistry`), `usePageTitle`, `useModalHistoryLock`, `OverridePropsProvider`, `useOverrideProps`, `useOverridePropsOptional`, `useUnsavedChangesGuard`, `useError`, `cssVar`, `accentColor`, `mutedTextColor`, `chartPalette`, `resolveColor`, `avatarColorForSeed`, `Sparkline`, `ClearButton`, `MartisLoader`, `usePreferences`, `usePreferencesOptional`, `loadLocale`, `applyDocumentDirection`, `usePrefersReducedMotion`, `addShortcut`, `disableShortcut`, `listShortcuts` | v1.38.0 |
| `flushSync` (also in the `react-dom` shim) | v1.38.2 |

Three ways to get a missing name, from the narrowest:

1. **Republish the shims.** They hold no app code, so replacing them is safe. The `martis-extension-shims` tag rewrites every shim and its declarations together, and leaves the Vite config, both tsconfig files and `index.ts` alone:

   ```bash
   php artisan vendor:publish --tag=martis-extension-shims --force
   npm run build:extensions
   ```

2. **Refresh the whole scaffold** with `php artisan martis:install --force`. It rewrites the Vite config, `tsconfig.extensions.json`, `index.ts`, `resources/js/martis-extensions/tsconfig.json` and every shim with its declarations, so review the diff if you edited any of them.
3. **Read the name off the default export**, which every shim since v1.10.0 provides and which is the host's runtime object itself: `import runtime from '@martis/runtime'`, then `const { Dropdown } = runtime`. A misspelt name is then `undefined` at render time instead of a build error.

**Type declarations (v1.38.0).** Scaffolds published before v1.38.0 have no declarations, so `tsc -p tsconfig.extensions.json` reports `Cannot find module '@martis/runtime'` for every runtime import, and TypeScript 6 (what `martis:install` installs today) stops earlier on the deprecated `baseUrl` (TS5101). Republish the shims (option 1), then bring `tsconfig.extensions.json` in line with `vendor/martis/martis/stubs/extensions/tsconfig.extensions.json.stub`: copy it over (re-applying your own edits), or remove `baseUrl` and the `@ext/*` path (the Vite config never resolved it), set `"types": ["vite/client"]`, set `"include"` to `["resources/js/martis-extensions/**/*"]`, and use these `paths`:

```json
"paths": {
  "@martis/runtime": ["./resources/js/martis-extensions/.shims/runtime.d.mts"],
  "react-dom": ["./resources/js/martis-extensions/.shims/react-dom.d.mts"],
  "react-router-dom": ["./resources/js/martis-extensions/.shims/react-router-dom.d.mts"],
  "react-i18next": ["./resources/js/martis-extensions/.shims/react-i18next.d.mts"],
  "@tanstack/react-query": ["./resources/js/martis-extensions/.shims/tanstack-react-query.d.mts"],
  "@/contexts/*": ["./resources/js/martis-extensions/.shims/runtime.d.mts"],
  "@/lib/*": ["./resources/js/martis-extensions/.shims/runtime.d.mts"],
  "@/components/auth/*": ["./resources/js/martis-extensions/.shims/runtime.d.mts"],
  "@martis/martis/*": ["./resources/js/martis-extensions/.shims/runtime.d.mts"],
  "@/components/fields/types": ["./resources/js/martis-extensions/.shims/runtime.d.mts"]
}
```

For your editor, add `resources/js/martis-extensions/tsconfig.json` (or copy `vendor/martis/martis/stubs/extensions/martis-extensions-tsconfig.json.stub` there):

```json
{
  "extends": "../../../tsconfig.extensions.json",
  "include": ["./**/*"]
}
```

**`react-dom` (fixed in v1.38.0).** Scaffolds published before v1.38.0 send `react-dom` to the React shim, which exports React core only: `import { createPortal } from 'react-dom'` passes `tsc`, which reads `@types/react-dom`, and then stops the build with `"createPortal" is not exported by ".shims/react.mjs"`. Import `createPortal` from `@martis/runtime` instead (republishing the shims, option 1 above, is enough for that), or send `react-dom` to its own shim: republish the shims, then in `vite.extensions.config.ts` add `const reactDomShim = path.join(shimsDir, 'react-dom.mjs')` and point the `/^react-dom$/` alias at `reactDomShim`, and add the `react-dom` line of the `paths` above to `tsconfig.extensions.json` (or copy both stubs over, re-applying your own edits). The `react-dom` shim of v1.38.0 and v1.38.1 carried `createPortal` only, so a library that imports `flushSync` from `react-dom` (`@tanstack/react-virtual`, the usual list virtualiser, does it at the top of its entry) stopped the build with `"flushSync" is not exported by ".shims/react-dom.mjs"`; since v1.38.2 the runtime carries the host's `flushSync` and the shim re-exports it. Republish the shims (option 1) to take it.

**Generated cards and fields (fixed in v1.38.0).** A card `martis:card` wrote before v1.38.0 binds `componentKey('revenue-gauge')`, but the dashboard resolves a card by its exact key and the entry registers `cards/RevenueGauge.tsx` as `card:revenue-gauge`: change the call to `componentKey('card:revenue-gauge')`. The entry registered a `fields/` file as `field:price-tag`, a key the field renderer never reads, so a field `martis:field` generated rendered as plain text: refresh `index.ts` (`php artisan martis:install --force`, then re-add any registration of your own) or replace its fields loop with the one in `vendor/martis/martis/stubs/extensions/index.ts.stub`. The generators also split an acronym letter by letter (`SEOReport` became `s-e-o-report`) where the entry keeps it whole (`seo-report`): fix the key in such a class by hand.

**Legacy import paths (fixed in v1.38.0).** The Vite config also sends the paths that override files published by older versions import to the runtime shim, so those files keep building: `@/contexts/*`, `@/lib/*`, `@/components/auth/*`, `@martis/martis/*` and `@/components/fields/types` (the type module the v1.9.3 field override imports its props from). From v1.10.0 to v1.37.x the config matched only the start of the first four, and the alias replaces only what it matches, so every import through them failed (`Could not load .../.shims/runtime.mjsapi` for `@/lib/api`). If your extension imports through them, copy `vendor/martis/martis/stubs/extensions/vite.extensions.config.ts.stub` over `vite.extensions.config.ts` (re-applying your own edits), or make each pattern match the whole path (`/^@\/lib\/.*$/`). These paths reach only the names the runtime shim exports; new code imports from `@martis/runtime`. `tsc` resolves them too, through the tsconfig `paths` above. On the sidebar override the v1.9.3 generator wrote, it then reports what that file does wrong: it draws a nested menu group (`type: 'group'`) as a link. Regenerate it with `php artisan martis:component --type=sidebar --force`, whose output lists a nested group's items under its label.

### Upgrading from v1.8.18 or earlier

If your app shipped a `resources/js/martis/boot.ts` from the legacy build-time mechanism, the file is silently ignored from v1.8.19 onwards. `martis:install` detects it and prints a one-time warning so you know the file is dead. To migrate:

1. Move each `componentRegistry.register('tool:foo', FooTool)` call's component into `resources/js/martis-extensions/tools/Foo.tsx` (filename = key suffix in PascalCase).
2. Drop the `register(...)` call entirely — the auto-discovery entry handles it.
3. Delete `resources/js/martis/boot.ts` and any related Vite alias (`@user/martis/boot`).
4. Run `php artisan martis:install --force` to publish the new scaffold (your existing files in the buckets are not touched).

The package no longer ships the `@user/martis/boot` build-time alias or the `@user` Vite alias resolution — those mechanisms were removed in v1.8.19 because they never actually worked on the published bundle.

## Directory Structure After Installation

```
your-laravel-app/
├── app/
│   ├── Martis/                                       # Your resource definitions (flat by default)
│   │   ├── UserResource.php
│   │   ├── PostResource.php
│   │   └── ...                                       # Subdirectories optional
│   └── Providers/
│       └── MartisServiceProvider.php                 # Host provider (closures, menu, gates)
├── bootstrap/
│   └── providers.php                                 # Auto-wired by martis:install
├── config/
│   └── martis.php                                    # Published configuration
├── database/
│   └── migrations/
│       ├── *_create_martis_action_events_table.php   # Audit log (+ the two morph id conversions)
│       ├── *_create_martis_user_preferences_table.php # Per-user prefs
│       ├── *_create_notifications_table.php          # In-app notifications
│       ├── *_create_martis_cache_state_table.php     # Cache versions and kill-switches
│       ├── *_add_martis_two_factor_columns_to_users_table.php # 2FA (with --with-2fa)
│       └── *_add_profile_picture_column.php          # Avatar (with --with-profile)
├── lang/
│   └── vendor/
│       └── martis/                                   # Published translations (martis-lang)
│           ├── en/
│           ├── pt_BR/
│           └── pt_PT/
├── public/
│   └── vendor/
│       └── martis/                                   # Published frontend assets (martis-assets)
└── resources/
    └── js/
        └── martis-extensions/                            # Consumer React extensions (v1.9+)
            ├── index.ts                                  # Auto-discovery entry — ships with martis:install
            ├── tsconfig.json                             # Editor tsconfig, extends tsconfig.extensions.json (v1.38.0)
            ├── .shims/                                   # Vite alias shims (react, runtime, etc.) and their declarations
            ├── tools/                                    # `martis:tool --with-component` outputs land here
            ├── fields/                                   # `martis:field` outputs
            ├── cards/                                    # `martis:card` outputs
            └── overrides/                                # `martis:component` outputs (shell, sidebar, auth pages, etc.)
```

## Upgrading

When upgrading Martis, always re-publish the frontend assets. Recommended command:

```bash
composer update martis/martis
php artisan martis:install --force
```

Equivalent manual asset-only upgrade:

```bash
composer update martis/martis
php artisan martis:publish-assets
```

Why this second step exists:

- Composer updates package files inside `vendor/martis/martis`
- Martis serves published files from `public/vendor/martis`
- The host app keeps using the old published files until you publish again

If your app has a custom theme and you are coming from 1.x, follow [Theming → Upgrading from 1.x](theming.md#upgrading-from-1x) before you publish: the publish now writes `public/vendor/martis/themes/` from `resources/css/martis/`, so edits made to the published copy have to move to the source first.

Coming from 1.x, also check:

- **`profile.resource`** must be `null` or name a class that implements `Martis\Contracts\ProfileResourceContract`. Any other value, such as a misspelt class, now throws an `InvalidArgumentException` naming the key; 1.x fell back to the default resource without a word. When you set it, `/martis/api/auth/user` and the login response take the Topbar avatar from your resource's `toArray()`, as the profile page does. See [Authentication → Custom Profile Resource](authentication.md#custom-profile-resource).
- **Avatar colours** come from the theme's `--martis-avatar-1..16` tokens. The Topbar shows two initials on a palette colour instead of one letter on the accent colour, the profile avatar is no longer indigo, and the initials of an `Avatar` or `UiAvatar` field can change colour. To keep a fixed colour per record, use `colorFrom()`; to change the colours, redefine the tokens in your theme. A subclass of `Avatar` or `UiAvatar` that calls or overrides the trait's `resolveInitialsColor()`, `deterministicInitialsColor()` or `$initialsPalette` has to move to `Martis\Support\Initials` (`paletteSlot()`, `defaultColor()`), or override `customInitialsColor()` to give its own colour.
- **Metric results are cached per user.** No action is needed, but the metric cache holds one entry per user and metric where 1.x held one per metric. See [Cache → The four built-in layers](cache.md#the-four-built-in-layers).

Use the asset-only command if you only want to refresh static files. Use the install command with `--force` if you want the full recommended refresh:

```bash
php artisan martis:install --force
```

`--force` also rewrites the extension scaffold (Vite config, `tsconfig.extensions.json`, shims and their declarations, `index.ts`, `resources/js/martis-extensions/tsconfig.json`), which is how an existing extension picks up the runtime names added since it was scaffolded. See [Refreshing the extension scaffold after an upgrade](#refreshing-the-extension-scaffold-after-an-upgrade) for the narrower options.

Know what else the installer changes before you use it on an app with customisations. With `--force`:

- it republishes `lang/vendor/martis`, overwriting customised strings
- it rewrites the published Martis migrations in place
- it rewrites `resources/js/martis-extensions/index.ts`, manual `register()` calls included

On every run, with or without `--force`:

- it rewrites the profile and 2FA flags in `.env` from the flags you pass (see [Optional Profile Support](#optional-profile-support)) and resets `MARTIS_EXTENSIONS` to `/vendor/martis-user/extensions.js`
- the asset publish wipes and re-copies `public/vendor/martis/` (see Step 4)
- it ends with `php artisan migrate --force`, which applies all pending migrations of the app

Commit first and review `git diff` afterwards. If your application uses the optional profile and two-factor migrations, re-run the install command with the same options after upgrading:

```bash
composer update martis/martis
php artisan martis:install --force --with-profile --with-2fa --avatar-column=avatar_path
```

Since v2.0 every Martis cache key carries the installed version, so an upgrade rebuilds the caches on its own (on a path repository, whose version does not change, run `php artisan martis:cache:clear`). On the `database` or `file` cache store, run `php artisan martis:cache:prune` after an upgrade to delete the entries the previous version left behind; see [Cache → Invalidation](cache.md#invalidation).

## Vendor Publish Tags Reference

The package exposes the following `--tag` values for `vendor:publish`:

| Tag | What it publishes | Destination |
|---|---|---|
| `martis-config` | `config/martis.php` (all knobs) | `config/martis.php` |
| `martis-provider` | Host service provider stub | `app/Providers/MartisServiceProvider.php` |
| `martis-assets` | Precompiled React frontend | `public/vendor/martis/` |
| `martis-views` | Blade SPA shell template | `resources/views/vendor/martis/` |
| `martis-lang` | Translation files (en, pt_BR, pt_PT) | `lang/vendor/martis/` |
| `martis-extension-shims` | Consumer-extension shims and their TypeScript declarations (v1.38.0) | `resources/js/martis-extensions/.shims/` |
| `martis-migrations` | Action-events audit log, user preferences, cache state and drop-dashboards-layout migrations | `database/migrations/*_create_martis_action_events_table.php`, `*_create_martis_user_preferences_table.php`, `*_create_martis_cache_state_table.php`, `*_drop_dashboards_layout_from_user_preferences_table.php` |
| `martis-preferences-migration` | User preferences table | `database/migrations/*_create_martis_user_preferences_table.php` |
| `martis-preferences-drop-dashboards-layout-migration` | Drops the legacy `dashboards_layout` preferences column | `database/migrations/*_drop_dashboards_layout_from_user_preferences_table.php` |
| `martis-cache-state-migration` | Cache state table | `database/migrations/*_create_martis_cache_state_table.php` |
| `martis-2fa-migration` | 2FA columns on `users` | `database/migrations/*_add_two_factor_columns.php` |
| `martis-avatar-migration` | Profile picture column on `users` | `database/migrations/*_add_profile_picture_column.php` |
| `martis-sessions-migration` | Sessions table (browser-sessions profile section) | `database/migrations/*_create_sessions_table.php` |
| `martis-invitations-migration` | Invitations table | `database/migrations/*_create_invitations_table.php` |

`martis:install` runs the appropriate combination based on its flags, and is the only way to publish the notifications table and the action-events morph id conversions. Direct `vendor:publish` calls are for advanced manual workflows; see Step 5 for the duplicate-migration caveat.

## Available Artisan Commands

The package ships 35 commands (plus the aliases `martis:override` → `martis:component` and `martis:make-policy` → `martis:policy`). The full list:

### Setup & maintenance

| Command | Description |
|---|---|
| `martis:install` | Full installation (directories, config, provider, assets, core migrations, translations, auto-migrate) |
| `martis:user` | Create an admin user (`--if-missing` / `--update` for idempotent bootstrap scripts) |
| `martis:publish-assets` | Republish the frontend assets: wipes `public/vendor/martis/`, copies the package build, verifies it against the manifest (`--no-wipe` for the legacy merge copy), then publishes the app themes from `resources/css/martis/` |
| `martis:vendor-publish` | Publish Martis package files by flag (`--config`, `--assets`, `--views`, `--lang`, `--force`); `--assets` goes through `martis:publish-assets` |
| `martis:stubs` | Publish all generator stubs into `stubs/martis/` for customisation (`--force` overwrites existing ones) |
| `martis:list-overrides` | Print the component keys the PHP layer declares (Tools, Actions with a custom component, resources); `--frontend` checks that your extension registers them |
| `martis:list-env-vars` | List every `MARTIS_*` env var the published config reads, with its default and config key (`--json` for machine output) |
| `martis:agents` | Generate guidelines for AI coding agents (`AGENTS.md`, `CLAUDE.md`, ...) and optionally wire the Martis MCP server. With `--no-interaction` it overwrites existing files without asking |
| `martis:mcp-serve` | Serve the Martis docs as an MCP server (stdio or HTTP transport) |
| `martis:invitations` | Scaffold the consumer-owned invitations admin UI (resource, actions, policy, notification); `--no-migrate` / `--no-publish` skip the migration steps |

### Cache control

| Command | Description |
|---|---|
| `martis:cache:status` | Show enabled / disabled state for each Martis cache subsystem |
| `martis:cache:clear` | Flush every Martis cache subsystem |
| `martis:cache:prune` | Delete the cache entries earlier versions and clears left behind (database and file stores; v2.0) |
| `martis:cache:enable` | Enable a Martis cache subsystem at runtime (survives until disabled) |
| `martis:cache:disable` | Disable a Martis cache subsystem at runtime |

### Resource scaffolding

| Command | Description |
|---|---|
| `martis:resource` | Scaffold a new resource class |
| `martis:field` | Scaffold a custom field class |
| `martis:action` | Scaffold an action class |
| `martis:filter` | Scaffold a filter class |
| `martis:lens` | Scaffold a lens class |
| `martis:policy` | Scaffold an authorization policy |
| `martis:tool` | Scaffold a Tool (free-form sidebar page) |
| `martis:roles` | Scaffold the Spatie roles + permissions admin (resources, policies, seeder) |

### Metrics & dashboards

| Command | Description |
|---|---|
| `martis:dashboard` | Scaffold a dashboard class |
| `martis:value` | Scaffold a Value metric |
| `martis:trend` | Scaffold a Trend metric |
| `martis:partition` | Scaffold a Partition metric |
| `martis:progress` | Scaffold a Progress metric |
| `martis:activity-feed` | Scaffold an Activity Feed metric |
| `martis:endpoint-table` | Scaffold an Endpoint Table metric |
| `martis:card` | Scaffold a custom dashboard card |

### Frontend & branding

| Command | Description |
|---|---|
| `martis:component` | Scaffold a React override (TSX) under `resources/js/martis-extensions/overrides/`. Every `--type` auto-registers on the next `npm run build:extensions`. See [Overrides](overrides.md#6-creating-custom-components-artisan) for the filename → key table |
| `martis:theme` | Scaffold a custom theme in `resources/css/martis/<name>.css`, publish it and set `theme.name` in a published `config/martis.php` |
| `martis:theme:diff` | Compare a consumer theme against the bundled package tokens (exit `0` aligned, `2` drift) |
| `martis:sso` | Scaffold an SSO provider end to end (composer deps, config, env, listener, migrations); pass `--no-composer --no-migrate` in CI or agent shells |

## Next Steps

- [Resources](resources.md) — Learn how to define and configure resources
- [Fields Reference](fields.md) — Explore the 50+ field types Martis ships
- [Override System](overrides.md) — Customize the UI without forking
