# Loader

The Martis Loader is a built-in loading indicator that appears automatically during data fetches, page transitions, and async operations. It is fully configurable and replaceable without forking the package.

## Where It Appears

| Context | Trigger | Default Size |
|---------|---------|--------------|
| Resource Index (initial load) | Schema + records fetch | `lg` |
| Resource Index (refetch overlay) | Search, sort, pagination | `sm` (overlay) |
| Resource Detail | Schema + record fetch | `lg` |
| Resource Lens | Schema + lens query | `md` |
| Profile | Session check | `md` |
| Action Drawer | Action schema fetch | `md` |
| Tool Page | `/api/tools/{uriKey}` fetch | `md` |
| Cache Admin | Cache stats endpoint | `md` |

`size` defaults to `md` when the call site does not pass it. The `lg` and `sm` rows above are the explicit overrides used by the resource pages.

## Configuration

Publish the Martis config file if you haven't already:

```bash
php artisan martis:vendor-publish --config
```

Then edit `config/martis.php` and add or modify the `loader` section:

```php
'loader' => [
    // Custom loading text. null = use the locale default ("Loading...")
    'message' => null,

    // Phosphor icon name to replace the spinner (e.g. 'spinner-gap', 'circle-notch')
    // When set, the named icon spins instead of the default SpinnerGap.
    'icon' => null,

    // URL to a logo/image shown instead of the spinner.
    // Takes precedence over 'icon'.
    'logo' => null,

    // CSS color for the spinner. Default: var(--martis-accent) — your theme's accent color.
    'spinnerColor' => null,

    // Overlay background opacity (0.0–1.0). Default: 0.6.
    'overlayOpacity' => null,

    // CSS color for overlay background. Default: var(--martis-bg).
    'overlayColor' => null,

    // Set to true to disable all loaders globally.
    'disabled' => false,

    // Granular opt-out per context.
    'disableOn' => [
        'table'      => false,  // disable overlay on index table refetch
        'search'     => false,  // disable loader during search
        'detail'     => false,  // disable loader on resource detail pages
        'components' => false,  // disable in other component contexts
                                // (Profile, ActionDrawer, ToolPage, CacheAdmin, ResourceLens)
    ],
],
```

All options are optional. Omitted keys use the built-in defaults.

## Configuration Examples

### Custom message and spinner color

```php
'loader' => [
    'message'      => 'Please wait...',
    'spinnerColor' => '#6366f1',
],
```

### Use your brand logo instead of a spinner

```php
'loader' => [
    'logo'    => '/images/logo.svg',
    'message' => null,  // hide message when using logo
],
```

### Use a different Phosphor icon

```php
'loader' => [
    'icon'    => 'circle-notch',
    'message' => 'Fetching...',
],
```

### Semi-transparent overlay on table refetch

```php
'loader' => [
    'overlayOpacity' => 0.4,
    'overlayColor'   => '#0f172a',
],
```

### Disable all loaders

```php
'loader' => [
    'disabled' => true,
],
```

### Disable only the table refetch overlay

```php
'loader' => [
    'disableOn' => [
        'table' => true,
    ],
],
```

## Customization via Component Registry

For full control, replace `MartisLoader` with your own component. Register the override from your consumer extension bundle (`resources/js/martis-extensions/`) (typically `resources/js/martis-extensions/index.ts`):

```typescript
import { componentRegistry } from '@/lib/componentRegistry'
import { MyLoader } from './MyLoader'

// Replace the global loader with your custom component. Every page in
// the package that imports `MartisLoader` from `@/components/Loader`
// transparently routes through this override.
componentRegistry.register('loader', MyLoader)
```

The override is read on every render, so registering after the SPA has mounted (HMR / lazy-loaded boot files) takes effect immediately on the next render — no reload required.

Your custom loader receives the same props as `MartisLoader`:

```typescript
interface MartisLoaderProps {
  /** Whether the loader is visible */
  loading?: boolean
  /** Override config message */
  message?: string | null
  /** Overlay mode: covers parent container with semi-transparent overlay */
  overlay?: boolean
  /** Disable this specific loader (per-context opt-out) */
  disabled?: boolean
  /** Size variant */
  size?: 'sm' | 'md' | 'lg'
  /** Children to render under the loader overlay (overlay mode only) */
  children?: React.ReactNode
  /**
   * Context identifier used to check `loader.disableOn` config.
   * Supported: "table" | "search" | "detail" | "components".
   * Pages pass this so a single config flag can opt one surface out
   * without having to thread `disabled={...}` everywhere.
   */
  context?: 'table' | 'search' | 'detail' | 'components'
  /**
   * Per-instance config override. Merged on top of the global
   * `config.loader` for this one render. The wrapper passes the
   * active resource's `Resource::loaderConfig()` here automatically;
   * call sites can also pass an inline override to win over both
   * global config and the resource override.
   */
  configOverride?: Partial<MartisLoaderConfig>
}
```

### Size tokens

| Size | Spinner | Text |
|------|---------|------|
| `sm` | 16px   | `text-xs` (12px) |
| `md` *(default)* | 24px | `text-sm` (14px) |
| `lg` | 32px   | `text-base` (16px) |

### Custom Loader Example

```tsx
// resources/martis-extensions/martis/MyLoader.tsx
import type { MartisLoaderProps } from '@/components/Loader'

export function MyLoader({ loading, size, overlay, children }: MartisLoaderProps) {
  if (!loading) return overlay ? <>{children}</> : null

  const spinner = (
    <div className="flex items-center gap-2 text-indigo-500">
      <svg className="animate-spin h-5 w-5" viewBox="0 0 24 24" fill="none">
        <circle className="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" strokeWidth="4" />
        <path className="opacity-75" fill="currentColor"
          d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z" />
      </svg>
      <span className="text-sm text-muted-foreground">Loading...</span>
    </div>
  )

  if (overlay) {
    return (
      <div className="relative">
        {children}
        <div className="absolute inset-0 flex items-center justify-center bg-background/60 rounded-lg z-10">
          {spinner}
        </div>
      </div>
    )
  }

  return spinner
}
```

## Per-resource override — `Resource::loaderConfig()`

A Resource can ship its own loader override that wins over the global config while a user is inside that resource's pages:

```php
namespace App\Martis\Resources;

use Martis\Resource;

class AuditLog extends Resource
{
    public static function loaderConfig(): array
    {
        return [
            'message' => 'Calibrating audit log…',
            'spinnerColor' => '#FF6347',
            'disableOn' => [
                'detail' => true,        // skip the detail-page loader
            ],
        ];
    }

    // …model(), fields(), etc.
}
```

Recognised keys mirror `config/martis.php` `loader`: `message`, `icon`, `logo`, `spinnerColor`, `overlayOpacity`, `overlayColor`, `disabled`, `disableOn` (`table | search | detail | components`).

The override is merged shallow-on-top of `config('martis.loader')`. `disableOn` is shallow-merged separately so opting one context in/out doesn't clobber the others. The override is active only while a `ResourceIndex` or `ResourceDetail` page for that resource is mounted; navigating away restores the global config.

Returns `[]` by default. Defining the method is opt-in.

## Bridge — config → frontend

The PHP-side `loader` config reaches the SPA via `window.MartisConfig.loader`, set by the package blade template (`resources/views/app.blade.php`, line 104):

```blade
loader: {!! json_encode(config('martis.loader', ['disabled' => false])) !!},
```

The TypeScript shape is exposed as `MartisLoaderConfig` in `@/lib/config`. If you need to gate the loader from the same closure that already drives a feature flag (e.g. dim the spinner during a maintenance window), point `MARTIS_LOADER_DISABLED` at an env value the closure reads — the env wrapper avoids editing the published config file.

## Publishing via `martis:vendor-publish`

To customize the configuration:

```bash
# Publish only the config file
php artisan martis:vendor-publish --config

# Force overwrite if already published
php artisan martis:vendor-publish --config --force
```

After publishing, edit `config/martis.php` in your application root. The config is automatically passed to the frontend via `window.MartisConfig.loader`.

To customize translations (loading messages):

```bash
# Publish language files
php artisan martis:vendor-publish --lang
```

Then edit `lang/vendor/martis/{locale}/messages.php` and change the `loading` key:

```php
// lang/vendor/martis/en/messages.php
return [
    'loading' => 'Fetching data...',
    // ...
];
```

## Testing the Loader

### Trigger the initial page loader

Navigate to any resource index or detail page. The loader appears immediately while data is being fetched and disappears once the response arrives.

```
http://your-app.test/martis/resources/posts
```

### Trigger the refetch overlay

On a resource index page, type in the search bar. After 300ms debounce, a small overlay spinner appears over the table while results load. If the backend is fast, add artificial latency with `sleep(1)` in your Resource's `indexQuery()` method.

### Trigger the overlay with artificial latency

In your Resource class (for testing only):

```php
public function indexQuery(Request $request): Builder
{
    sleep(2); // simulate slow response — remove after testing
    return parent::indexQuery($request);
}
```

### Verify each configuration option

| Option | How to test |
|--------|-------------|
| `message` | Set a custom string; check text next to spinner |
| `icon` | Set a valid Phosphor icon name; spinner should change |
| `logo` | Set a URL to an image; image should appear instead of spinner |
| `spinnerColor` | Set a CSS hex color; spinner color should change |
| `overlayOpacity` | Set to `0.2` vs `0.9`; overlay darkness should differ |
| `overlayColor` | Set to a bright color; overlay background color should change |
| `disabled` | Set `true`; no loader should appear anywhere |
| `disableOn.table` | Set `true`; search/sort refetch should show no overlay |

### Verify component override

After registering a custom component via `componentRegistry.register('loader', MyLoader)`, reload any resource page and confirm your component renders instead of the default spinner.
