# Troubleshooting

Common problems when installing or running Martis, and how to fix them. If your issue is not here, search the [GitHub issues](https://github.com/Real-Edge-FX/martis-package/issues) or open a new one.

## Install

### `composer require martis/martis` cannot resolve the version

Martis requires PHP 8.3+ and Laravel 11.x, 12.x, or 13.x. Check `php -v` and the `laravel/framework` constraint in your application's `composer.json`.

```bash
php -v
grep '"laravel/framework"' composer.json
```

If you are on Laravel 10, upgrade first or pin Martis to a compatible older release.

### `php artisan martis:install` fails on `martis-config` publish

Vendor publishing skips files that already exist. If a previous (incomplete) install left `config/martis.php` in place, the command exits without overwriting. Use `--force`:

```bash
php artisan martis:install --force
```

To re-run the full installer including the optional avatar + 2FA migrations:

```bash
php artisan martis:install --force --with-profile
```

`--with-profile` does **not** create a `Profile` model or an admin user. It publishes two granular migration stubs (`add_two_factor_columns` and `add_profile_picture_column`) on top of the base install. Use `php artisan martis:user` afterwards to create an admin account.

### Assets 404 after install

The package ships precompiled assets under `public/vendor/martis`. Republish them:

```bash
php artisan martis:publish-assets
```

This wipes `public/vendor/martis/` first so stale Vite-hashed chunks from previous package versions don't pile up across upgrades. Then clear caches:

```bash
php artisan optimize:clear
```

> **Note:** the legacy `php artisan vendor:publish --tag=martis-assets --force` still works but is a merge-style copy — orphaned chunks accumulate at every `composer update`. The `martis:publish-assets` command (and `martis:vendor-publish --assets`) is the canonical entry point and avoids the disk bloat. Pass `--no-wipe` to opt back into the merge behaviour if you have a specific reason to.

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

Resources are auto-discovered from the directory configured in `config/martis.php` (`resources_path`, default `app_path('Martis')`). There is no `resources` array to maintain. If a resource exists on disk but Martis cannot find it:

1. Check it lives under the configured path. Move it back into `app/Martis/`, or change `resources_path` in the config.
2. Check the class autoloads:
   ```bash
   composer dump-autoload
   ```
3. Clear caches (the resource registry is rebuilt on every boot, but a stale opcache/config cache can mask it):
   ```bash
   php artisan optimize:clear
   ```

### Sortable column has no effect

`->sortable()` only emits a flag. The query layer reads it from the resource's `fields()` and applies `orderBy()`. If you have a custom `indexQuery()` that re-sorts, your sort wins and the toggle silently no-ops. Remove the `orderBy` from `indexQuery()`, or apply it conditionally only when the request did not request a column sort.

### Searchable on a relationship column returns 0 results

`->searchable()` defaults to `LIKE %term%` against the field's column on the resource's table. To search a related model's column (for example, the owner's `name`), use a relationship field with `relationSearchable()`:

```php
use Martis\Fields\BelongsTo;

BelongsTo::make('Owner', 'owner', \App\Martis\UserResource::class)
    ->searchable()
    ->relationSearchable(),
```

`relationSearchable()` is shipped on `BelongsTo`, `MorphTo`, `HasMany`, `MorphMany`, and `Tag`. It tells the global search to issue a `whereHas` against the related table's title attribute (or the columns you explicitly opt in via the resource's `searchableRelations()`). See [Fields](fields.md#searchable) for the full search behaviour.

## Theme and components

### Theme tokens not applied

Theme tokens live under the `theme` block in `config/martis.php` (there is no separate `config/martis-theme.php`). After editing tokens, clear the config cache:

```bash
php artisan config:clear
```

If you scaffolded a custom theme via `php artisan martis:theme`, regenerate the published file by running the generator again.

### Custom override not picked up

Almost always a key mismatch between the PHP layer (which declares "I want a component called `<key>`") and your consumer extension bundle (`resources/js/martis-extensions/`) (which registers the actual React component under that key). The `martis:list-overrides` command shows every key the PHP layer expects:

```bash
php artisan martis:list-overrides
```

Confirm that every key in the output is registered in your `resources/js/martis-extensions/index.ts`. The `componentRegistry` is exported as a module from `@/lib/componentRegistry` — there is no `window.componentRegistry`, so to inspect at runtime add a temporary `import { componentRegistry } from '@/lib/componentRegistry'; (window as any).componentRegistry = componentRegistry;` to your extension entry, rebuild, and read `componentRegistry.keys()` from devtools.

Common culprits when an override is missing:

- **`MARTIS_EXTENSIONS` env var unset.** v1.8.19+ loads the consumer extension bundle dynamically from the URL listed in `MARTIS_EXTENSIONS` (defaults to `/vendor/martis-user/extensions.js`). Confirm it is set in `.env` and that the URL returns 200.
- **Bundle not built.** Re-run `npm run build:extensions` and check `public/vendor/martis-user/extensions.js` exists. The deploy script runs this automatically; local dev iterations need it manually.
- **TSX file in the wrong bucket.** Auto-discovery only walks `resources/js/martis-extensions/{tools,fields,cards,overrides}/`. A file in any other folder is invisible to the loop.
- **Slug typo.** Keys are case-sensitive. `field.text` and `Field.Text` are different.

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

Metrics do **not** cache by default. The base `Metric::cacheFor()` returns `null`, so the metric re-queries on every page load. Override it on the metric class:

```php
use DateTimeInterface;

public function cacheFor(): ?DateTimeInterface
{
    return now()->addMinutes(5);
}
```

See [Metrics](metrics.md) for the cache key and ranges.

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

Martis resolves the runtime locale through `PreferencesResolver` in this order: URL preset (`?preset=…`) > the `martis_user_preferences.locale` row of the authenticated user > `config('app.locale')`. The `ApplyUserPreferencesLocale` middleware reads the resolved value and calls `app()->setLocale($locale)` for the request.

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
