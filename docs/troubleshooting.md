# Troubleshooting

Common problems when installing or running Martis, and how to fix them. If your issue is not here, search the [GitHub issues](https://github.com/Real-Edge-FX/martis-package/issues) or open a new one.

## Install

### `composer require martis/martis` cannot resolve the version

Martis requires PHP 8.3+ and Laravel 12.x or 13.x. Check `php -v` and the `laravel/framework` constraint in your application's `composer.json`.

```bash
php -v
grep '"laravel/framework"' composer.json
```

If you are on Laravel 10, upgrade first or pin Martis to a compatible older release.

### `php artisan martis:install` does not update `config/martis.php`

When `config/martis.php` already exists, the installer skips it with the notice `Skipping config — already published (use --force-config to overwrite — destroys customisations)` and carries on with the other steps. A key added to the package config in a later release therefore never reaches the published copy (see [New config keys have no effect](#new-config-keys-have-no-effect-in-an-existing-app)).

The `--force` flag **does not** republish `config/martis.php` or `app/Providers/MartisServiceProvider.php` — those are split behind separate flags so a `--force` run cannot destroy host-app customisations:

| Flag | Republishes |
|------|-------------|
| `--force` | Extension scaffold (Vite config, both tsconfig files, shims and declarations, `index.ts`), `lang/vendor/martis` and the published Martis migrations (rewritten in place) |
| `--force-config` | `config/martis.php` |
| `--force-provider` (v1.10.2+) | `app/Providers/MartisServiceProvider.php` |

```bash
# Rewrite the extension scaffold, translations and Martis migrations.
# Overwrites index.ts register() calls and customised lang/vendor/martis strings: commit first.
php artisan martis:install --force

# Re-publish config/martis.php (destroys consumer customisations).
php artisan martis:install --force-config

# Re-publish the host provider (destroys registered dashboards/menus/gates).
php artisan martis:install --force-provider
```

Every run, with or without `--force`, also rewrites the profile / 2FA flags and `MARTIS_EXTENSIONS` in `.env` and ends with `php artisan migrate --force`.

To re-run the full installer including the optional avatar and 2FA migrations:

```bash
php artisan martis:install --force --with-profile --with-2fa
```

`--with-profile` does **not** create a `Profile` model or an admin user. It publishes the avatar column migration (`add_profile_picture_column`); `--with-2fa` independently publishes the two-factor columns migration (`*_add_martis_two_factor_columns_to_users_table.php`). Use `php artisan martis:user` afterwards to create an admin account.

### Profile or 2FA stays disabled after `--with-profile` / `--with-2fa`

The installer prompts only when STDIN is a real TTY. A run from CI, `docker compose exec -T` or an agent shell resolves every optional feature you did not pass a flag for to disabled and writes `MARTIS_PROFILE_ENABLED=false` (and `MARTIS_2FA_ENABLED`, `MARTIS_AVATAR_ENABLED`, `MARTIS_SHOW_PROFILE_MENU`) to `.env`. On the next run the disabled config wins over `--with-*`. Set those keys back to `true` in `.env` (or remove them), run `php artisan config:clear`, then:

```bash
php artisan martis:install --force --no-interaction --with-profile --with-2fa
```

### New config keys have no effect in an existing app

The package merges its config into yours with Laravel's `mergeConfigFrom()`, which only merges top-level keys. A published `config/martis.php` keeps its whole `audit`, `search`, `profile`, ... array, so a key added to one of those arrays in a newer release is simply absent, and its env switch does nothing. Add the new key to your published config by hand (the release notes list it), or re-publish with `php artisan martis:install --force-config` (destroys your customisations), then `php artisan config:clear`.

### Assets 404 after install

The package ships precompiled assets under `public/vendor/martis`. Republish them:

```bash
php artisan martis:publish-assets
```

This wipes `public/vendor/martis/` first so stale Vite-hashed chunks from previous package versions don't pile up across upgrades, does a deterministic full-tree copy of the package's compiled assets, and then **verifies the result** — every file the published `manifest.json` references (the app entry bundle, its CSS, and every chunk) must exist in the destination. Then clear caches:

```bash
php artisan optimize:clear
```

> **Note:** the legacy `php artisan vendor:publish --tag=martis-assets --force` still works but is a merge-style copy — orphaned chunks accumulate at every `composer update`, and it never checks that the full set landed. The `martis:publish-assets` command (and `martis:vendor-publish --assets`, and `martis:install`) is the canonical entry point: it avoids the disk bloat and guarantees a complete set. Pass `--no-wipe` to opt back into the merge behaviour if you have a specific reason to.

### Black screen (admin loads but nothing renders)

A blank/black admin with a `404` on `assets/app-<hash>.js` in the browser console means the app entry bundle is missing from `public/vendor/martis/` — the published asset set is incomplete. Re-run the canonical command:

```bash
php artisan martis:publish-assets
```

Since v1.29.1 this command fails **loudly** on an incomplete copy: instead of reporting success it prints the count of missing files and exits non-zero, so a partial publish (an interrupted run, a full disk, restrictive filesystem permissions) is caught at publish time rather than surfacing as a black screen. If it does report missing files, check disk space and the writability of `public/vendor/martis/`, then re-run.

If you sit behind a reverse proxy (Nginx Proxy Manager, Cloudflare, custom Nginx), set `ASSET_URL` in `.env` to the public origin:

```env
ASSET_URL=https://your-app.example.com
```

Without it, Laravel's `asset()` helper falls back to the request `Host` header, which can be the proxy's internal IP.

### Class `Martis\Fields\ID` not found on Linux but works on macOS

The class is `Martis\Fields\Id` (lowercase `d`). macOS APFS is case-insensitive by default, Linux ext4 is not. Update the import:

```php
use Martis\Fields\Id; // not ID
```

This bites users who copy-paste examples written on macOS.

## Auth

### Login page redirects in a loop

Martis uses Laravel's default authentication guard (the one set in `config('auth.defaults.guard')`, normally `web`). It only diverges when you set `MARTIS_GUARD` in `.env` or override the top-level `guard` key in `config/martis.php`:

```php
// config/martis.php — top-level key, NOT under 'auth'
'guard' => env('MARTIS_GUARD', null), // null falls back to auth.defaults.guard
```

If your app already redefined the `web` guard or uses a custom user provider, declare a named guard in `config/auth.php` and point Martis at it:

```php
// config/auth.php
'guards' => [
    'admin' => [
        'driver'   => 'session',
        'provider' => 'admins',
    ],
],
```

```env
MARTIS_GUARD=admin
```

Restart the queue / clear config (`php artisan optimize:clear`) for the new guard to be picked up.

### SSO callback returns 404

The SSO routes are registered conditionally. Make sure the master switch is on, the per-provider switch is on, and the standard Socialite environment variables are set. Provider credentials are **not** prefixed with `MARTIS_`:

```env
# Master switch for the SSO subsystem
MARTIS_SSO_ENABLED=true

# Enable a specific provider
MARTIS_SSO_AZURE_ENABLED=true

# Provider credentials (raw Socialite names, no MARTIS_ prefix)
AZURE_CLIENT_ID=...
AZURE_CLIENT_SECRET=...
AZURE_REDIRECT_URI=https://your-app.example.com/martis/sso/azure/callback
AZURE_RESOURCE_ID=...
```

See [SSO](sso.md) for the full provider matrix and the four canonical recipes.

### Forgot-password email returns 503 instead of sending

When the application's mailer is misconfigured (or the SMTP server is unreachable), the password-reset endpoint catches the failure and returns `503 Service Unavailable` with a translated message instead of a 500 stack trace. The toast on the frontend reads "Email service unavailable, try again later". Fix the mailer (`config/mail.php` and the `MAIL_*` env vars), and the same form submits successfully.

### 2FA loop after enabling TOTP

If you enabled 2FA on a user but lost the secret, log in via Tinker and clear all four columns:

```bash
php artisan tinker
```

```php
$u = \App\Models\User::find(1);
$u->two_factor_secret = null;
$u->two_factor_recovery_codes = null;
$u->two_factor_confirmed_at = null;
$u->two_factor_last_used_at = null;
$u->save();
```

The next login will treat 2FA as un-enrolled. Re-enable it from the user's profile page.

## Catalog and detail page

### "Resource not found" on a slug that exists

Resources are auto-discovered from the directory configured in `config/martis.php` (`resources_path`, default `app_path('Martis')`) and mapped to classes under `resources_namespace` (default `null`: derived from Composer's PSR-4 map, falling back to `App\Martis`). There is no `resources` array to maintain. If a resource exists on disk but Martis cannot find it:

1. Check it lives under the configured path. Move it back into `app/Martis/`, or change `resources_path` in the config.
2. Check the namespace matches the path. With `resources_namespace` left `null`, the file at `app/Martis/Customer/Resources/OrganizationResource.php` must declare `App\Martis\Customer\Resources` (whatever Composer's PSR-4 map says for that directory). If the directory is not autoloaded, set `resources_namespace` explicitly to the namespace the files declare. Before v1.36.0 the namespace was always `App\Martis`, so a `resources_path` outside `app/Martis` found nothing.
3. Check the class autoloads:
   ```bash
   composer dump-autoload
   ```
4. Clear caches (the resource registry is rebuilt on every boot, but a stale opcache/config cache can mask it):
   ```bash
   php artisan optimize:clear
   ```

### Index shows "No records found." (or a badge is missing) while `laravel.log` shows an exception

Before v1.32.4 two failures were silent in the UI: a failed index fetch (`GET /api/resources/{resource}` returning 5xx) was rendered as the empty state after the retries, and a `menuCount()` that threw simply dropped its sidebar badge. Both typically share one root cause: a query that throws for some users (a tenant scope with no tenant resolved, a missing table, a permission gate inside a global scope).

Since v1.32.4:

1. The index, lens pages and relationship panels render an inline error state with the HTTP status, the server message and a **Retry** button instead of "No records found.", and fire an error toast. "No records found." only ever describes a successful empty result.
2. The badge failure is reported through `report()` as `Martis\Exceptions\MenuCountFailedException` (naming the resource / tool class), and with dev tools on the badges payload lists the broken counters under `_failed`. See [Menus → When a badge is missing](menus.md#when-a-badge-is-missing).

If you still see the empty state on an older version, check `storage/logs/laravel.log` for the exception thrown by the resource's `indexQuery()` / global scopes, and fix the query at its source (or stop offering the resource to that user via `authorizedToViewAny()`).

### Sortable column has no effect

`->sortable()` only emits a flag. The query layer reads it from the resource's `fields()` and applies `orderBy()`. If you have a custom `indexQuery()` that re-sorts, your sort wins and the toggle silently no-ops. Remove the `orderBy` from `indexQuery()`, or apply it conditionally only when the request did not request a column sort.

### Searchable on a relationship column returns 0 results

`->searchable()` defaults to `LIKE %term%` against the field's column on the resource's table. To search a related model's column (for example, the owner's `name`), list it as a dot path in the resource's `searchableRelations()`: the index search and the global search add a `whereHas` for it.

```php
public static function searchableRelations(): array
{
    return ['owner.name'];
}
```

See [Global Search → Searchable detail relations](global-search.md#-searchable-detail-relations--searchablerelations). `relationSearchable()` on a relationship field searches nothing on its own: it shows or hides the search box of that field's panel (`HasMany`, `MorphMany`, `BelongsToMany`, `MorphToMany`) or, since v1.38.0, of its picker (`BelongsTo`, `MorphTo`, `Tag`).

## Theme and components

### Theme tokens not applied

A custom theme is the stylesheet `resources/css/martis/<name>.css`, activated by `theme.name` in the `theme` block of `config/martis.php` (there is no separate `config/martis-theme.php`). The browser loads the published copy, `public/vendor/martis/themes/<name>.css`, which `martis:publish-assets` writes from the source. After editing the theme or the config, publish and clear the config cache:

```bash
php artisan martis:publish-assets
php artisan config:clear
```

Do not run `php artisan martis:theme` again to refresh the published file: it refuses to overwrite the source without `--force`, and with `--force` it replaces your theme with the scaffold. Up to v1.39.1 every asset publish deleted the published copy without writing it again; see [Theming → Theme not loading](theming.md#theme-not-loading).

### Custom override not picked up

Almost always a key mismatch between the PHP layer (which declares "I want a component called `<key>`") and your consumer extension bundle (`resources/js/martis-extensions/`) (which registers the actual React component under that key). The `martis:list-overrides` command shows the keys the PHP layer declares (the component key of each Tool and of each Action with a custom component, and each resource's URI key):

```bash
php artisan martis:list-overrides
```

Confirm that every Tool and Action key in the output is registered by your extension (a resource needs no component): the auto-discovery entry registers each file of the four buckets, and `resources/js/martis-extensions/index.ts` holds any `register()` call of your own. To inspect the live registry, run `window.Martis.componentRegistry.keys()` in the browser console once the SPA has booted: it is the registry the SPA resolves from (the same instance `@martis/runtime` exports as `componentRegistry`), so no rebuild is needed. `php artisan martis:list-overrides --frontend` runs the same cross-check statically: it derives the key of each file in the four buckets and reads the literal key of each `register()` call in `index.ts` (v1.38.0), and exits `2` when a Tool or Action key is missing. A computed key, or a call in another module, is not read: check it in the console.

Common culprits when an override is missing:

- **`MARTIS_EXTENSIONS` env var unset.** v1.8.19+ loads the consumer extension bundle dynamically from the comma-separated URLs listed in `MARTIS_EXTENSIONS`. The config default is an empty list; `martis:install` writes `MARTIS_EXTENSIONS=/vendor/martis-user/extensions.js` to `.env` (on every run, so re-add any extra URLs afterwards). Confirm it is set in `.env` and that the URL returns 200.
- **Bundle not built.** Re-run `npm run build:extensions` and check `public/vendor/martis-user/extensions.js` exists. The deploy script runs this automatically; local dev iterations need it manually.
- **TSX file in the wrong bucket.** Auto-discovery only walks `resources/js/martis-extensions/{tools,fields,cards,overrides}/`. A file in any other folder is invisible to the loop.
- **Key typo.** Keys are case-sensitive and colon-separated: `tool:seo-report`, `card:revenue-gauge`, `field:display:<type>` / `field:input:<type>`, `auth:login`. The entry derives them from the file name (`tools/SEOReport.tsx` → `tool:seo-report`); on the PHP side use `Martis\Stubs\ExtensionKey::kebab()`, not `Str::kebab()` (which turns `SEOReport` into `s-e-o-report`).

## Performance

### Catalog page slow with relations

Eager-load relations on the resource's `indexQuery`:

```php
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

public static function indexQuery(Request $request, Builder $query): Builder
{
    return $query->with(['owner', 'invoices']);
}
```

Without this, every row triggers N+1 queries when a field accessor traverses the relation.

### Metric card is slow

Metric results are cached by default through the Martis `metrics` cache layer (`MARTIS_CACHE_METRICS_ENABLED`, TTL `MARTIS_CACHE_METRICS_TTL`, default 5 minutes). When the base `Metric::cacheFor()` returns `null` (the default), the result goes through that layer, which honours the master switch, `php artisan martis:cache:disable metrics`, `?nocache=1` and `martis:cache:clear metrics`. Check `php artisan martis:cache:status`: if the layer is disabled, every page load re-queries. Raise the TTL for heavy metrics:

```env
MARTIS_CACHE_METRICS_TTL=15
```

Overriding `cacheFor()` to return a date caches the metric with `Cache::remember()` directly, outside the Martis layer, so the kill-switch, the `?nocache` bypass and `martis:cache:clear` no longer apply to it. Neither cache path puts the user in the key: do not cache a metric whose result depends on the authenticated user or tenant (disable the layer for it instead).

See [Metrics](metrics.md) and [Cache](cache.md) for the cache keys and ranges.

### Cache subsystem disabled at runtime

Martis exposes per-subsystem cache toggles that survive restarts (preferences, search, navigation, schema, …). Inspect and reset them:

```bash
php artisan martis:cache:status
php artisan martis:cache:enable preferences
php artisan martis:cache:clear
```

If a subsystem feels stale, `martis:cache:clear` is non-destructive and safe to run in production.

## i18n

### Translations fall back to English even when the locale is set

Martis resolves the runtime locale through `PreferencesResolver` in this order: URL preset (`?preset=…`) > the `martis_user_preferences.locale` row of the authenticated user > `config('martis.preferences.defaults.locale')` (`MARTIS_DEFAULT_LOCALE`, default `en`). `APP_LOCALE` and `MARTIS_LOCALE` are **not** part of this chain: to change the panel language for users without a saved preference, set `MARTIS_DEFAULT_LOCALE` (it must be listed in `martis.preferences.locales`). The `ApplyUserPreferencesLocale` middleware (`martis.locale`) reads the resolved value and calls `app()->setLocale($locale)` on every authenticated Martis route.

If you change the locale at runtime in code, set Laravel's locale directly:

```php
app()->setLocale('pt_PT');
```

There is no `Profile->locale` column and no `Martis::setLocale()` helper — both belong to old drafts of this page.

See [Internationalisation](i18n.md) for the resolution order and the published lang files.

## Still stuck?

- [Open a GitHub issue](https://github.com/Real-Edge-FX/martis-package/issues/new) with PHP version, Laravel version, and the smallest reproducer you can produce.
- Check the [release history](https://github.com/Real-Edge-FX/martis-package/releases) — your problem may already be fixed in a newer tag.
- Read [Configuration](configuration.md) for the full list of `MARTIS_*` env flags.
