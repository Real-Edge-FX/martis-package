# Loader

The Martis Loader is a built-in loading indicator that appears automatically during data fetches, page transitions, and async operations. It is fully configurable and replaceable without forking the package.

## Where It Appears

| Context | Trigger | Default Size |
|---------|---------|--------------|
| Resource Index (initial load) | Schema + records fetch | `lg` |
| Resource Index (refetch overlay) | Search, sort, pagination | `sm` (overlay) |
| Resource Detail | Schema + record fetch | `lg` |
| Profile | Session check | `md` |
| Action Drawer | Action schema fetch | `md` |

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
        'components' => false,  // disable in other component contexts
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

For full control, replace `MartisLoader` with your own component. This is done in your application's JavaScript entry point (typically `resources/js/app.tsx`):

```typescript
import { componentRegistry } from '@martis/martis/lib/componentRegistry'
import { MyLoader } from './components/MyLoader'

// Replace the global loader with your custom component
componentRegistry.register('loader', MyLoader)
```

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
}
```

### Custom Loader Example

```tsx
// resources/js/components/MyLoader.tsx
import type { MartisLoaderProps } from '@martis/martis/components/Loader'

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
