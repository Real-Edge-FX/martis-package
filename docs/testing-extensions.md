# Testing extensions

Render your Martis extensions in Vitest or a Playwright page with the real runtime, its providers and its shims, and no server.

Martis v2.3.0 ships a test runtime in `vendor/martis/martis/dist/testing/` (about 7 MB): a development build of `@martis/runtime`. Importing it fills `window.Martis` the way the SPA does. The shims `martis:install` published (`@martis/runtime`, `react-router-dom`, `react-i18next`, `@tanstack/react-query`) then work in a test, against your app's own development copy of React, so `act()`, state updates and effects behave as in any React test.

## Setup

1. Put your app's React on the panel's major, then install the test tools. Your app already has React, ReactDOM, Phosphor and `@vitejs/plugin-react` from `martis:install`, but its `^18 || ^19` range gives a fresh app React 19, while the panel runs React 18 (see [Library versions](#library-versions)):

   ```bash
   npm install react@^18 react-dom@^18
   npm install --save-dev vitest@^3 jsdom @testing-library/react
   ```

2. Create `vitest.config.mjs` at the root of the app:

   ```js
   import { defineConfig, mergeConfig } from 'vitest/config'
   import react from '@vitejs/plugin-react'
   import { martisExtensionTestConfig } from './vendor/martis/martis/dist/testing/vitest.mjs'

   export default mergeConfig(
     defineConfig({
       plugins: [react()],
       test: { include: ['resources/js/martis-extensions/**/*.test.tsx'] },
     }),
     martisExtensionTestConfig(),
   )
   ```

   `martisExtensionTestConfig()` does four things:
   - it sends `@martis/runtime`, `react-router-dom`, `react-i18next`, `@tanstack/react-query` and the legacy paths to the shims in `resources/js/martis-extensions/.shims/`, as `vite.extensions.config.ts` does;
   - it leaves React, its JSX runtime, ReactDOM and Phosphor to `node_modules`, so your extension, the runtime and Testing Library share one React and one icon library (`resolve.dedupe` pins `react`, `react-dom` and `@phosphor-icons/react` to your app's copy even when the Martis package, symlinked or not, has its own `node_modules`, and the icon library is inlined so it follows);
   - it maps `@martis/testing` to the test runtime;
   - it runs the test runtime as a setup file, under `jsdom`.

   Options: `root` (the app's root, the current directory by default) and `packageDir` (`vendor/martis/martis`). `packageDir` may sit outside the app root: the config lets Vite serve it (`server.fs.allow`). It throws, naming the path, when the runtime or the shims are missing.

3. Register Testing Library's cleanup. Without `globals: true` in the Vitest config, Testing Library does not unmount rendered trees after each test, and a later test finds the markup of an earlier one. Either set `test: { globals: true }` in `vitest.config.mjs`, or call it in every test file (or one setup file listed in `test.setupFiles`):

   ```tsx
   import { afterEach } from 'vitest'
   import { cleanup } from '@testing-library/react'

   afterEach(cleanup)
   ```

4. Put tests in `resources/js/martis-extensions/__tests__/`. The extension entry loads every `.tsx` in the `tools/`, `fields/`, `cards/` and `overrides/` buckets, so a test file there would end up in your bundle.

5. Type-check them with the rest of the extension: `tsconfig.extensions.json` (v2.3.0) maps `@martis/testing` to `vendor/martis/martis/dist/testing/testing.d.mts`. An app scaffolded earlier adds the two entries of [Upgrading to v2.3.0](upgrading.md#upgrading-to-v230-from-v22x) to its `paths`.

## Writing a test

`MartisTestProvider` renders its children inside the providers the Martis shell gives a page:
- the real auth, preferences, theme, toast, breadcrumb and gate providers;
- React Query;
- a router;
- the i18next instance the `react-i18next` shim serves;
- the host of the components an action shows with `ActionResponse::modal()`.

```tsx
// resources/js/martis-extensions/__tests__/Reports.test.tsx
import { afterEach, describe, expect, it } from 'vitest'
import { cleanup, render, screen } from '@testing-library/react'
import { MartisTestProvider } from '@martis/testing'
import Reports from '../tools/Reports'

afterEach(cleanup)

describe('Reports', () => {
  it('greets the signed-in user', () => {
    render(
      <MartisTestProvider user={{ id: 7, name: 'Ada', email: 'ada@example.com' }}>
        <Reports tool={{ name: 'Reports', uriKey: 'reports', icon: null, component: 'tool:reports', menuSection: null, meta: {} }} />
      </MartisTestProvider>,
    )

    expect(screen.getByText('Signed in as Ada')).toBeTruthy()
  })
})
```

| Prop | Default | What it sets |
|---|---|---|
| `user` | `defaultTestUser` (`{ id: 1, name: 'Test User', email: 'test@example.com', panel_access: true }`) | The user `useAuth()` returns. `null` renders as a guest. No `/api/auth/user` request is made. |
| `preferences` | none | Preferences on top of the defaults (`theme`, `accent`, `density`, `locale`, `reducedMotion`). `update()` and `reset()` work in memory: no `/api/preferences` request, no `localStorage` write. |
| `config` | none | Keys merged into `window.MartisConfig` while the provider is mounted (`{ profile: { ... }, auth: { ... } }`), restored when it unmounts. With two providers mounted, the later one wins a key both set, and an unmount gives each key back to the provider still mounted. Under `StrictMode` the keys stay in place. `basePath` is read once when the runtime loads: set it on `window.MartisConfig` in a setup file that runs before the runtime, if you need another one. |
| `locale` | `'en'` | The i18next language. |
| `translations` | none | Translations for `locale`, by namespace: `{ reports: { title: 'Reports' } }`, removed when the provider unmounts: a bundle your setup registered gets its own content back, and a later test without them sees the keys. Without them a key renders as the key, and the Martis components render their own keys. |
| `queryClient` | a new client, retries off | The React Query client, to read or seed the cache. |
| `path` | `'*'` | The route pattern the children render at: `'reports/:reportId'` gives them `useParams()`. |
| `initialPath` | `'/'` | The URL the router starts at. |

## The API

The runtime's `api` client calls `window.location.origin` plus `/martis`. Stub `fetch` for the requests your component makes:

```tsx
import { vi } from 'vitest'

vi.stubGlobal('fetch', vi.fn(async () => new Response(JSON.stringify({ data: [{ id: 1, title: 'First report' }] }), {
  status: 200,
  headers: { 'Content-Type': 'application/json' },
})))
```

## i18next

`tsconfig.extensions.json` maps `i18next` to declarations only (`.shims/i18next.d.mts`), with no module behind them:

- **Types work anywhere.** `import type { i18n, TFunction } from 'i18next'` type-checks in an extension source or a test.
- **An extension source never imports i18next as a value.** It reaches the panel's instance through `useTranslation()` (from `react-i18next` or `@martis/runtime`). A value import such as `import i18next from 'i18next'` type-checks, then fails `npm run build:extensions` with a misleading `[MISSING_EXPORT] "default" is not exported by ".shims/i18next.d.mts"`.
- **A test that creates its own instance installs i18next.** Without it, Vitest stops at `Failed to resolve import "i18next"`. Install the version the panel bundles, the `i18next` entry of `versions.json` (26.0.4 in Martis 2.3.0), as a dev dependency:

  ```bash
  npm install --save-dev i18next@26.0.4
  ```

```tsx
// resources/js/martis-extensions/__tests__/Greeting.test.tsx
import { afterEach, expect, it } from 'vitest'
import { cleanup, render, screen } from '@testing-library/react'
import i18next from 'i18next'
import { I18nextProvider, useTranslation } from 'react-i18next'
import { MartisTestProvider } from '@martis/testing'

afterEach(cleanup)

function Greeting() {
  const { t } = useTranslation('reports')
  return <p>{t('hello')}</p>
}

it('renders with its own i18next instance', () => {
  const i18n = i18next.createInstance()
  void i18n.init({ lng: 'en', initAsync: false, resources: { en: { reports: { hello: 'Hello' } } } })

  render(<MartisTestProvider><I18nextProvider i18n={i18n}><Greeting /></I18nextProvider></MartisTestProvider>)

  expect(screen.getByText('Hello')).toBeTruthy()
})
```

## Routes and links

The children render at `path` inside a data router, so `Link`, `useNavigate()`, `useParams()` and `useUnsavedChangesGuard()` work. Under jsdom the test runtime lets the router navigate: when the global `Request` refuses jsdom's `AbortSignal`, it is replaced by one that drops the signal, which the router only uses to abort loaders.

## Playwright

A harness page served by your app's Vite dev server can render an extension with the same runtime: import `vendor/martis/martis/dist/testing/testing.mjs` first, then render with `MartisTestProvider` through `react-dom/client`. The page needs the same aliases as the Vitest config: pass `martisExtensionTestConfig().resolve` to the harness's Vite config. For the Martis styles, link `vendor/martis/martis/dist/testing/runtime/martis.css`.

## Symlinked installs

When `vendor/martis/martis` is a symlink (a Composer `path` repository), `martisExtensionTestConfig()` sets `resolve.preserveSymlinks`, so the runtime resolves React from your app's `node_modules`. `resolve.dedupe` backs it up: a symlinked package whose real path holds its own `node_modules/react` or `@phosphor-icons/react` still cannot give the run a second copy, so an `IconContext.Provider` in your test reaches the runtime's icons. In an app whose own `node_modules` is symlinked (pnpm), check that your other tests still resolve; the option only switches on for a symlinked Martis.

TypeScript follows the symlink: in such an install, `tsc -p tsconfig.extensions.json` reads the kit's declarations with the package checkout's own `@types/react` next to your app's. Both are on React 18 (`martis:install` pins `@types/react` to `^18`), so the two type-check together. An install of a release (a tag) has no second copy.

## In CI

The kit lives in the Martis package, so a CI job that runs only the frontend tests installs it first: `composer install --no-interaction --prefer-dist` (no database or `.env` needed), then `npm ci` and `npx vitest run --config vitest.config.mjs`.

## Library versions

`vendor/martis/martis/dist/testing/versions.json` lists the Martis version and the versions of the libraries the SPA bundles: React, ReactDOM, React Router, react-i18next, i18next, React Query, Phosphor and PrimeReact. The test runtime bundles the same router, i18next and React Query builds, so a test sees what the panel runs.

React is the exception: the test runtime uses your app's React, while the panel serves its own (`versions.json`'s `react`, 18) to every extension. An extension that calls an API of another major (React 19's `use`) would pass its tests and then throw in the panel, so a test run on another major fails before any test runs, naming both versions and the fix:

```text
Error: [martis] This test run loads react 19.3.0 and react-dom 19.3.0, but the Martis panel runs React 18.3.1: an extension tested on another React major can pass here and fail in the panel. Install the panel's major in your app: npm install react@^18 react-dom@^18 (docs/testing-extensions.md).
```

An app whose own pages need React 19 cannot run the kit on the same `node_modules`.

## What the kit does not do

- **It runs no server.** Pages that need `/api/...` answers get them from your `fetch` stub.
- **It ships no translations.** Pass the keys your component reads in `translations`.
- **It renders no shell.** The Layout and its sidebar, topbar and footer are not mounted. `Sidebar`, `Topbar` and `Footer` are on `@martis/runtime`: render them yourself when a test needs them. The Layout that composes them is not exported (only its `LayoutProps` type is).

Nova has no equivalent: its tools' Vue components have no supported test runtime.
