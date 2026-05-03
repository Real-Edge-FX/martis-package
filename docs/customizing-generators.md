# Customising Martis Generators

Every Martis make-command (e.g. `martis:resource`, `martis:action`, `martis:lens`, `martis:trend`, `martis:tool`) renders a class from a `.stub` template shipped inside the package. The defaults aim for "drop-in" with sane Laravel-style headers and docblocks. Once a project has its own conventions (custom file headers, opinionated docblocks, organisation imports, project-specific traits) the default stubs become a friction point.

`martis:stubs` publishes every template into `stubs/martis/` so consumers can edit them in place.

> **Stub overrides vs component overrides.** This page is about **scaffolding** — the source code emitted once when you run `php artisan martis:resource Foo`. It does not affect runtime rendering. To swap a React component the panel uses live, see the [Override System](overrides.md) (`componentRegistry`, `layoutRegistry`).

## Quick start

```bash
php artisan martis:stubs
```

Output (truncated — the package ships ~45 stubs):

```
  resource.stub ............................................... PUBLISHED
  action.stub ................................................. PUBLISHED
  action.destructive.stub ..................................... PUBLISHED
  lens.stub ................................................... PUBLISHED
  ...

  45 stubs published; 0 skipped. Edit them in stubs/martis/ to customise generator output.
```

When you re-run with `--force`, the status column reads `OVERWRITTEN` instead of `PUBLISHED` so the overwrite is visible in the log.

The folder is created at `base_path('stubs/martis/')`. From now on every `martis:*` make-command reads its template from there first via `StubResolver::path()`, falling back to the package default only if the override file is missing.

## What gets published

| Stub | Used by |
|---|---|
| `resource.stub` | `martis:resource` |
| `action.stub`, `action.destructive.stub` | `martis:action` |
| `lens.stub` | `martis:lens` |
| `dashboard.stub` | `martis:dashboard` |
| `metric.value.stub`, `metric.trend.stub`, `metric.partition.stub`, `metric.progress.stub`, `metric.activity-feed.stub`, `metric.endpoint-table.stub` | `martis:value`, `martis:trend`, `martis:partition`, `martis:progress`, `martis:activity-feed`, `martis:endpoint-table` |
| `filter.boolean.stub`, `filter.date.stub`, `filter.select.stub` | `martis:filter` |
| `card.stub`, `component-card.tsx.stub` | `martis:card` |
| `tool.stub`, `tool-component.tsx.stub` | `martis:tool` |
| `field.stub`, `field.tsx.stub` | `martis:field` |
| `policy.stub` | `martis:policy` |
| `theme.css.stub` | `martis:theme` |
| `component-{type}.tsx.stub` (12 variants) | `martis:component --type={type}` (see table below) |

### `martis:component` types

`martis:component` accepts a `--type=` flag and resolves a dedicated stub per type. The stub-resolver fallback to `component-generic.tsx.stub` keeps the command working when `--type=` is omitted or unrecognised.

| `--type=` | Stub | Override key registered by the generated component |
|---|---|---|
| `shell` | `component-shell.tsx.stub` | `layout:shell` |
| `sidebar` | `component-sidebar.tsx.stub` | `layout:sidebar` |
| `topbar` | `component-topbar.tsx.stub` | `layout:topbar` |
| `footer` | `component-footer.tsx.stub` | `layout:footer` |
| `card` | `component-card.tsx.stub` | (used by `martis:card`) |
| `field` | `component-field.tsx.stub` | (used by `martis:field`) |
| `generic` (default fallback) | `component-generic.tsx.stub` | — |
| `login-page` | `component-login-page.tsx.stub` | `auth:login` |
| `register-page` | `component-register-page.tsx.stub` | `auth:register` |
| `forgot-password-page` | `component-forgot-password-page.tsx.stub` | `auth:forgot-password` |
| `reset-password-page` | `component-reset-password-page.tsx.stub` | `auth:reset-password` |
| `email-verify-notice-page` | `component-email-verify-notice-page.tsx.stub` | `auth:email-verify-notice` |

### Install / SSO / Roles stubs

`martis:stubs` also publishes the templates that `martis:install`, `martis:sso`, and `martis:roles:scaffold` use to write migrations + helper classes:

| Stub | Used by |
|---|---|
| `create_martis_action_events_table.php.stub`, `create_user_preferences_table.php.stub`, `create_martis_notifications_table.php.stub`, `add_profile_picture_column.php.stub`, `add_two_factor_columns.php.stub` | `martis:install` |
| `add_provider_group_column_to_roles_table.php.stub` | `martis:sso --with-migration` |
| `MartisServiceProvider.php.stub` | `martis:install` (provider scaffold) |
| `roles-permission-resource.stub`, `roles-role-resource.stub`, `roles-user-resource.stub`, `roles-policy.stub`, `roles-seeder.stub` | `martis:roles:scaffold` |

Since v1.8.8 these all route through `StubResolver::path()`, so editing the published copy in `stubs/martis/` produces a custom output exactly like the regular generators. **Caveat:** the migrations describe schema Martis depends on at runtime — change column names or types only if you also update the matching app code, and re-run `martis:install` after.

## Editing a stub

```bash
php artisan martis:stubs
nano stubs/martis/resource.stub        # edit
php artisan martis:resource Order       # output reflects the edits
```

No cache to clear, no rebuild, no restart. The generator reads the file from disk every invocation.

Stubs use simple `{{ placeholder }}` substitution, the same syntax Laravel core uses. Keep the placeholders intact unless you also customise the generator class.

## Re-publishing after a package upgrade

When a Martis upgrade adds new generators or changes a stub shape, the new templates ship in the package but are **not** auto-copied into your `stubs/martis/`. Two options:

1. **Keep your customisations.** Run `php artisan martis:stubs` again; existing files are skipped (output: `SKIPPED`). New stubs from the upgrade are added. Then manually port any upstream changes you care about.
2. **Reset to the latest defaults.** Run `php artisan martis:stubs --force` to overwrite every file with the latest package default. Use a clean working tree so the diff is reviewable in git.

```bash
php artisan martis:stubs           # additive: skip existing, copy new
php artisan martis:stubs --force   # destructive: overwrite everything
```

## Internals — `Martis\Stubs\StubResolver`

Every make-command resolves its stub through:

```php
\Martis\Stubs\StubResolver::path('resource.stub');
```

The resolver exposes three static methods:

| Method | Returns |
|---|---|
| `path(string $name): string` | Override-aware path — `base_path('stubs/martis/<name>')` when present, else the package default. This is the path every make-command uses. |
| `packagePath(string $name): string` | Always the bundled package default, regardless of any override. Useful when your custom command needs the unmodified template (e.g. to diff against a customer override). |
| `packageDirectory(): string` | The package's `stubs/` root, derived from the resolver's own filename via reflection. Drives the `martis:stubs` command itself. |

If you ship a custom artisan command of your own, the same resolver gives you the override-aware path:

```php
use Martis\Stubs\StubResolver;

protected function getStub(): string
{
    return StubResolver::path('my-custom.stub');
}
```

You publish your own stubs into `stubs/martis/` from your own service provider's `boot()` if you want them to participate in the same flow.

## Removing customisations

Drop the override file from `stubs/martis/` and the resolver falls back to the package default on the next run. Removing the entire folder reverts everything.

```bash
rm -rf stubs/martis/                   # full reset
rm stubs/martis/resource.stub          # revert just one
```
