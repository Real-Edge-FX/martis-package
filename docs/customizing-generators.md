# Customising Martis Generators

Every Martis make-command (e.g. `martis:resource`, `martis:action`, `martis:lens`, `martis:trend`, `martis:tool`) renders a class from a `.stub` template shipped inside the package. The defaults aim for "drop-in" with sane Laravel-style headers and docblocks. Once a project has its own conventions (custom file headers, opinionated docblocks, organisation imports, project-specific traits) the default stubs become a friction point.

`martis:stubs` publishes every template into `stubs/martis/` so consumers can edit them in place.

## Quick start

```bash
php artisan martis:stubs
```

Output:

```
  resource.stub ............................................... PUBLISHED
  action.stub ................................................. PUBLISHED
  action.destructive.stub ..................................... PUBLISHED
  lens.stub ................................................... PUBLISHED
  ... (18 stubs total)

  18 stubs published; 0 skipped. Edit them in stubs/martis/ to customise generator output.
```

The folder is created at `base_path('stubs/martis/')`. From now on every `martis:*` make-command reads its template from there first, falling back to the package default only if the override file is missing.

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
| `component-*.tsx.stub` | `martis:component` |

The migration stubs (`create_*_table.php.stub`, `add_*_column.php.stub`) used by `martis:install` and `martis:sso` are intentionally **not** customisable — they describe schema the package depends on at runtime, and divergence there breaks upgrades.

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

The resolver:

1. Returns `base_path('stubs/martis/resource.stub')` when that file exists.
2. Falls back to `<package>/stubs/resource.stub` otherwise.

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
