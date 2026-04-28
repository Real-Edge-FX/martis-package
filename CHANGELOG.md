# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/)
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added

- **`martis:stubs` artisan command** — publishes every generator stub from the package into `stubs/martis/` in the consuming app. Once published, edits to those files take effect on the next generator run with no cache to clear. New `Martis\Stubs\StubResolver` resolves each lookup against the project copy first, falling back to the bundled stub when no override exists. All 17 generator commands (resource, action, lens, field, dashboard, every metric type, filter, card, tool, component, theme, policy) now route through the resolver.

### Changed

- **`martis:make-policy` renamed to `martis:policy`** — aligns with the rest of the Martis generator naming (`martis:resource`, `martis:action`, ...). The historical `martis:make-policy` name is preserved as a hidden alias on the same command, so existing scripts and tutorials continue to work.

## [1.0.0] — 2026-04-27

First stable release. The post-v0.10.0-rc1 cycle closed the documentation
audit, the v1.0 readiness checklist, and a handful of polish items found
during release-candidate baking.

### Added

- **Visual regression baseline** — `tests/e2e/visual-baseline.spec.ts`
  in the playground covers 7 canonical pages (dashboard, resource
  index, resource create, profile, system cache, custom tool, login)
  with `toHaveScreenshot()` assertions and committed PNG baselines.
- **README screenshot showcase** — six inline screenshots in the root
  README, sourced from the visual-baseline captures.
- **GitHub Actions CI** — new `.github/workflows/ci.yml` runs:
  - `pest` matrix: PHP 8.2/8.3 × Laravel 11.*/12.* (4 cells)
  - `phpstan` at level 8
  - `pint` style check
  - `vitest` JS test suite
- **PHPStan baseline** — `phpstan-baseline.neon` locks 220 pre-v1.0
  errors so CI fails on new errors without blocking the cut. Track the
  count down to zero post-1.0.
- **`docs/v1-roadmap.md`** — live readiness checklist used to drive
  this release.

### Changed

- **Documentation overhaul** — every doc reads as a self-contained
  Martis explainer. No comparative framing.
  - Removed historical / outdated docs (`release-v0.3.0-alpha.md`,
    `PROJECT_CONTEXT.md`).
  - Filled 4 missing config-key sections (`preferences`, `sticky_views`,
    `loader`, `impersonation`) in `docs/configuration.md`.
  - Filled 7 missing endpoint families (Dashboards, Tools, Preferences,
    Notifications, Cache Admin, Profile, Impersonation, Sync Field) in
    `docs/api/overview.md`.
  - Documented `RelationshipQueryResolver`, `RedirectAfter`,
    `MetricResult`, and `useUnsavedChangesGuard`.
- **`composer.json`** — `minimum-stability` bumped from `dev` to
  `stable` for the first stable release.
- **`composer.json`** — license corrected from `proprietary` to `MIT`
  (matches the LICENSE file content all along).

### Fixed

- **Route name collision** — RC-only routes registered under
  `Route::name('api.')->group(...)` and named `api.X` produced
  double-prefixed names (`martis.api.api.tools.index` etc). Affected
  7 routes from the v0.10.0-rc1 surface; dropped the inner `api.` so
  the canonical names are now `martis.api.tools.index`,
  `martis.api.impersonation.{status,start,stop}`,
  `martis.api.navigation`, `martis.api.command-palette`.
- **PHP 8.2/8.3 syntax compatibility** — `(new class { ... })::class`
  now wrapped in parens (8 occurrences across 4 test files). The
  unparenthesised form requires PHP 8.4+ and was breaking CI on the
  8.2/8.3 matrix cells.
- **Style baseline** — auto-fixed 80 files of pre-existing Pint drift
  (single_quote, ordered_imports, fully_qualified_strict_types, etc).
  Pure formatting; no semantic changes.
- **Playwright auth setup** — login form selector moved from
  `#email`/`#password` to `#login-email`/`#login-password` at some
  point; the test setups never picked up the rename and were silently
  timing out. Fixed in playground PR #6.

## [0.10.0-rc1] — 2026-04-27

First release candidate for v1.0.0. Closes the entire post-1.0 backlog
identified in the v0.9.0-beta planning round.

### Added — Custom Tools

- `Martis\Tools\Tool` base class + `Martis\Contracts\ToolContract` interface.
- Per-tool `boot()` lifecycle hook — runs once during host application
  boot (after Martis loads its own routes / views / config). Use it to
  register tool-owned routes, event listeners, gates, schedules, view
  namespaces. Idempotent; exception-swallowing so a broken tool cannot
  bring down the admin shell.
- `Martis\Tools\ToolServiceProvider` — abstract base for distributing a
  Tool as a standalone Composer package (auto-discovery via
  `extra.laravel.providers`).
- `Tool::publishes()` + `Tool::publishesAssets()` — per-tool config /
  migrations / lang / asset publishing through the standard
  `vendor:publish --tag=...` flow.
- `Martis::tools([...])` registration on the manager;
  `Martis::resolveTools(Request)` and `Martis::findTool(Request, $key)`
  for authorisation-filtered lookups.
- `MenuItem::tool($class|$instance)` factory + new `MenuItemType::Tool`
  enum case.
- REST surface: `GET /martis/api/tools` + `GET /martis/api/tools/{uriKey}`
  (404-when-denied so unauthorised users cannot probe which tools exist).
- `martis:tool` artisan generator with flags `--with-component`,
  `--component-key`, `--use-bundled`, `--menu-section`, `--icon`,
  `--force`. Prints "next steps" CLI block on success.
- Bundled `SystemStatusDemo` React component (`martis:tool:system-status-demo`)
  for zero-TSX consumer paths.
- React shell — `resources/js/pages/ToolPage.tsx` mounted at
  `/tools/:uriKey` resolves the registered React component via
  `componentRegistry`.

### Added — Impersonation

- Two-layer guard: `martis.impersonation.enabled` master switch
  (default `false`) + `martis-impersonate` Laravel Gate (no default —
  consumers define it explicitly in their `AuthServiceProvider`).
- `Martis\Impersonation\ImpersonationManager` with `start`, `stop`,
  `isActive`, `originalUser`, `currentTarget`, `enabled`, `guard`,
  `snapshot` methods.
- `Impersonation` facade.
- 3 endpoints: `GET /martis/api/impersonation/status`,
  `POST /start/{userId}`, `POST /stop`. Full 503 / 403 / 404 / 422 / 200
  error matrix; self-impersonation and chaining rejected by design.
- Built-in `ImpersonationBanner` React component, mounted in the layout
  shell. Boot-config short-circuit so disabled installs pay zero
  ongoing cost.

### Added — Documentation deliverables

- `docs/tools.md` rewritten as end-to-end walkthrough (architecture
  diagram, 5-min path, anatomy, lifecycle, distribution patterns,
  cookbook with 3 recipes, anti-patterns).
- `docs/tool-boot-patterns.md` companion: decision rubric for
  `Tool::boot()` vs `AppServiceProvider::boot()`.
- `docs/impersonation.md` — banner integration recipe + audit-log
  subclass example.

### Changed

- `MartisManager::bootTools()` runs every registered tool's `boot()`
  exactly once per request lifecycle.
- Test count — 1613 Pest + 84 Vitest = 1697 passing, 6 skipped, 0 failed.

### Fixed

- `ImpersonationBanner` skips its mount-time fetch entirely when the
  master switch is off at boot. Previously polled `/api/impersonation/status`
  on every authenticated page mount, adding ~1s under cold cache.
- `MartisManager::tools()` re-arms the boot lifecycle so tools registered
  after the first request (in tests, deferred providers) still fire.
- Test health metadata across the docs corrected — the historical
  "282 failing" was retired in v0.7.0-beta but never propagated to
  workspace metadata.

### Tests

- `tests/Feature/ToolsControllerTest.php` (13 tests) — registration,
  authorisation, boot lifecycle, exception swallowing, publishing,
  menu integration.
- `tests/Feature/ImpersonationControllerTest.php` (10 tests) — both
  guards, start error matrix, stop idempotency, snapshot shape.
- `tests/Feature/ParitySurfaceTest.php` — Reflection-based tripwire
  that locks the public surface of every v0.8 / v0.9 / v0.10 contract.
  18 tests, expanded to cover the new Tools + Impersonation surface.

## [0.9.0-beta] — 2026-04-26

Forms / Index UX, Locale Extensibility, Global Search, Visual UX, SSO.

### Added — Reactive forms (Task 09)

- `Field::dependsOn(['attr'], Closure)` reactive fields with server-side
  resolution via `POST /api/resources/{r}/sync-field`. Frontend
  debounces 200ms and uses `AbortController` so the latest value
  always wins.
- 13 closure-aware setters across the field API (`nullable`,
  `required`, `readonly`, `default`, `placeholder`, `help`, `tooltip`,
  `withLabel`, `rules`, `Select::options`, `MultiSelect::options`,
  `BooleanGroup::options`).
- Customisation hooks gain a `?Request` 4th argument
  (`resolveUsing` / `fillUsing` / `displayUsing`).
- `displayUsing(array)` accepts a chainable transformation pipeline.
- Context-aware validation: `creationRules()` / `updateRules()` layered
  on top of `rules()`; new `immutable()` flag (writable on create,
  readonly on update).
- 4 save variants: `Create & add another`, `Create & view list`,
  `Save & continue editing`, `Save & view list`.
- Reset-filters toolbar button (separate from Reset view).

### Added — Global Search (Task 11)

- Per-resource config: `globallySearchable()` accepts
  `bool|array{enabled?, limit?, min_query?}`.
- `searchOrderBy()` hook applied AFTER the search filter.
- "View all N matches in {resource}" palette overflow item.

### Added — Locale extensibility (Task 13)

- Per-key deep merge of consumer overrides.
- Configurable host-app namespaces via `martis.locales.app_namespaces`.
- Configurable fallback chain via `martis.locales.fallback_chain`.

### Added — SSO (Task 14)

- Pluggable provider contract (`SsoProviderContract`) with `AzureProvider`
  reference implementation.
- Identity-to-user resolver, role mapping (column / config / callable),
  permission adapters (Spatie / native / callable).
- Idempotent `martis:sso <provider>` generator.

### Fixed — Layout-flatten audit

- Closed five latent bugs across `HasManyController`,
  `MorphManyController`, `LensController`,
  `ResourceController::syncField`, and
  `ResourceController::relatableSearch`. Every code path that iterates
  `Resource::fields()` raw now flattens `Section` / `Panel` / `TabGroup`
  first.

### Fixed — Visual UX (Task 16)

- Loader dark-mode bug.
- Spinner under `prefers-reduced-motion`.
- Topbar search overlap.
- `applySorting` layout TypeError.
- Audio play-button on row click.

## [0.8.0-beta] — 2026-04-26

Sticky Views, Notifications, Cache control.

### Added — Sticky views

- Per-resource session storage of search / sort / filters / pagination
  / trashed-toggle / `filtersOpen`. Survives back-navigation; drops on
  resource change.
- `useStickyView()` hook in `resources/js/lib/`.
- Config: `martis.sticky_views.{enabled, scope, persist.*}`.

### Added — In-app notifications

- Topbar bell dropdown over Laravel's standard `notifications` table.
- `MartisNotification::make(title:, message:, level:)` inline factory
  (no `MartisNotifies` trait — consumer uses Laravel's standard
  `Notifiable`).
- REST: `/api/notifications`, `/unread-count`, `/read-all`,
  `/{id}/read`, single + bulk delete.
- Config: `martis.notifications.{enabled, poll_interval, max_in_dropdown}`.

### Added — Cache control surface

- `MartisCache::extend('name', enabled, ttl)` for host-app cache
  layers on top of the four built-ins (`metrics`, `navigation`,
  `dashboards`, `schema`).
- `/martis/system/cache` admin page (toggle / version / clear per type).
- Three control planes: config, env vars, runtime overrides
  (cache-stored, survives restarts).
- Per-request bypass via `X-Martis-No-Cache: 1` header or
  `?nocache=1` query.

## [0.7.0-beta] — 2026-04-25

### Added — Design-system phases 1–5
- **Phase 1 tokens** — 94 design tokens covering background layers,
  text / borders, accent / brand, semantic colours, interactive
  states, overlays / shadows, datatable, radius, typography, chart
  palette and file icons. Light + dark roots ship together.
- **Phase 2 Preferences panel** — per-user theme, accent, brand
  colour, density, locale, reduced-motion. Persisted in
  `martis_user_preferences`. Applied before first paint to prevent
  any flash. Optional named presets via `?preset=<name>` URL param.
- **Phase 3 Shell** — `.martis-shell` grid (sidebar / topbar / main),
  responsive collapse states, density-aware row heights, sidebar
  group polish (counts badge, expandable section toggle, kicker
  section heading).
- **Phase 4 Components** — buttons (primary / secondary / danger /
  success / warning / ghost + sizes + icon-only), badges (semantic
  + neutral + dot), avatars (xs / sm / md / lg / xl + circle /
  rounded / squared + stack), data table chrome.
- **Phase 5 Surfaces** — modals, peeks (BelongsTo card flip), trix
  editor chrome, action event JSON viewer, OfMany aggregate tile,
  through-relationship breadcrumb hint.

### Added — Authentication redesign (Fase 6)
- Six pre-login surfaces share a unified `AuthFrame.tsx` shell —
  Login, Register, 2FA challenge, 404, 403, 500.
- `auth.controls` config with independent toggles for the theme
  cycle button and language picker on every guest surface.
- 6-cell OTP row with auto-advance, paste-to-fill, 30s countdown,
  and a backup-code toggle that swaps the OTP grid for a recovery
  code input.
- Configurable SSO / Google / password reset / registration flows
  via `config/martis.auth`.

### Added — Command Palette (⌘K / Ctrl+K)
- Global overlay with four ordered sections: Resources, Actions,
  Recent activity, Records (debounced cross-resource search).
- Keyboard-first: `↑` / `↓` / `↵` / `esc`, `⌘K` / `Ctrl+K` to toggle
  even while typing.
- New `GET /api/command-palette` endpoint behind the standard
  Martis auth + 2FA + locale middleware stack. Standalone resource
  actions auto-register in the palette.

### Added — DataTable column widths
- Per-type column-width heuristics (Id 80px, Email/Url 280px max +
  truncate, Date 140px, title column 220px min). Explicit
  `->width()` / `->maxWidth()` / `->minWidth()` / `->truncate()`
  always wins. Global opt-out via
  `config('martis.index.column_defaults', false)` for apps that
  prefer the pre-v0.7.0 fully auto-sizing behaviour.

### Added — Sidebar polish
- Per-resource navigation count badges, polled at the configurable
  `config('martis.navigation.poll_interval')` interval.
- Top-level section headings (`group()` value) render as kickers.
- Group rows are now toggleable; default-open state remembered in
  `localStorage`.

### Added — Page title customization
- Resources can override the document title and breadcrumb segment
  via `pageTitle()` and `pageSubtitle()`.

### Added — Dashboard extras
- **Sparkline mode on `TrendCard`** — backend opt-in (`sparkline: true`
  on the result) renders an inline SVG sparkline + delta pill instead
  of the full Chart.js panel. New reusable `<Sparkline>` helper.
- **`ActivityFeedMetric`** + `ActivityFeedResult` — chronological event
  stream (actor / verb / target / time + coloured Phosphor avatar
  tile). Generator: `php artisan martis:activity-feed`.
- **`EndpointTableMetric`** + `EndpointTableResult` — compact HTTP
  route table with method chips, mono numeric columns, and a thin
  share bar. Generator: `php artisan martis:endpoint-table`.
- **`MetricType` enum** gains `ActivityFeed` and `EndpointTable`
  cases, wired end-to-end through `MetricCard` + the React layer.
- **`.martis-dash-kpis` / `.martis-dash-grid`** layout helpers (4-col
  KPI row, 3-col body grid, with `.span-2` / `.span-3` cell helpers
  and a 1100px breakpoint that collapses both).

### Added — Audio field redesign
- Custom on-brand player replaces `<audio controls>`: accent
  play/pause button, waveform OR progress bar, mono current/total
  timestamps, download affordance. Looks consistent in light and dark
  themes.
- Drop-zone empty state with dashed border, Phosphor MusicNote icon,
  drag-and-drop support.
- All strings via `messages.php` (`audio_empty_title`,
  `audio_empty_hint`, `audio_browse`, `audio_replace`, `audio_remove`,
  `audio_play`, `audio_pause`, `audio_download`) in en / pt_PT /
  pt_BR.

### Added — KPI typography + status dot
- New `.martis-kpi-label` / `.martis-kpi-label-icon` /
  `.martis-kpi-label-text` (12px uppercase muted with leading icon).
- New `.martis-kpi-value` (28px semibold + tabular numerals; collapses
  to 24px under `[data-density="dense"]`).
- New `.martis-kpi-delta` (`is-up` / `is-down` trend variants) with
  `.martis-kpi-delta-sub` for "vs previous" copy.
- New `.martis-status-dot` + `.martis-status-pulse` (canonical Live
  indicator). Reduced-motion aware via `[data-reduced-motion="true"]`
  and the `prefers-reduced-motion` media query.
- `MetricCard` Live badge unified with the dot.

### Added — Detail surfaces, drawer, and form density
- **Detail panel rows** — `.martis-detail-panel`,
  `.martis-detail-row`, `.martis-detail-label`, `.martis-detail-value`
  with an `is-drawer` variant + density overrides.
- **Detail kicker** — `.martis-detail-kicker` for kicker/eyebrow text.
- **Drawer shell** — `.martis-drawer-shell/head/head-main/head-row/
  title/subtitle/icon/actions/foot` primitives.
- **Form density helpers** — `.martis-form-body`,
  `.martis-form-stack`, `.martis-form-grid` for create/update + drawer
  forms; density overrides bring `martis-input` to 30px and tighten
  `.martis-input-wrap` gap under dense mode.
- **Tabs / Segmented / Skeleton** raw classes — `.martis-tabs`,
  `.martis-segmented`, `.martis-skeleton` — promoted from one-off
  surface usage to public utility classes.
- **MultiSelect chips** — code-like values render mono, regular values
  inherit the design-system badge family.

### Added — Avatars, badges, shell micro-fixes, auth controls
- **Avatar palette** — 16 deterministic hues declared as
  `--martis-avatar-1..16` (identical hex across light/dark) plus a new
  `lib/avatarPalette.ts` helper exposing `avatarColorForSeed(seed)` /
  `avatarHexForSeed(seed)`. `AvatarField` and `UiAvatarField` use the
  palette as the zero-config fallback when the backend doesn't supply
  a colour.
- **Avatar fallback icon** — empty initials render a muted UserIcon
  inside a new `.martis-avatar-fallback` chip instead of `?`.
- **Badge sizing** — height 20 → 22, font-weight 600 → 500, gap 4 →
  5, plus tabular numerals so trailing counts read evenly.
- **`.martis-notif-dot`** — public, reusable notification indicator
  any icon-button can stamp on top of itself.
- **Sidebar caret semantics** — closed = caret-right, open rotates
  90deg to point down (matches the design system).
- **`auth.controls.theme` / `auth.controls.locale`** — independently
  toggle the theme cycle button and language picker on every pre-login
  surface. Env vars: `MARTIS_AUTH_CONTROL_THEME`,
  `MARTIS_AUTH_CONTROL_LOCALE`.

### Changed
- **Drawer default width** bumped 560 → 720 (expanded 800 → 960) so
  relationship toolbars (Create / Search / Per-page / Trashed) fit
  inside the drawer at default size.
- **Detail page right rail** — refactored as a stacked detail panel
  with kicker support.
- **Sidebar section heading** colour switched from
  `--martis-text-faint` to `--martis-text-muted` (spec).

### Fixed
- **Generator stub paths** — 7 `Make*` artisan generators
  (`Action`, `Filter`, `Dashboard`, `Partition`, `Progress`, `Trend`,
  `Value`) had a wrong `__DIR__` chain that resolved to a path outside
  the package, throwing `FileNotFoundException` on first invocation.
- **`filter.date.stub`** — generated subclasses crashed at runtime
  because they passed the new `ComparisonOperator` enum directly to
  `whereDate()`. Stub now calls `->value` on it.

## [0.6.0-alpha] — 2026-04-19

### Task 07 — Field coverage completion (PRs A → E)

#### Added — Fields
- **`Repeater` + `Repeatable`** — full repeatable-row support. JSON
  storage (`asJson`), HasMany storage with 3-way upsert (`asHasMany` +
  `uniqueField`), multi-type Add menu, per-row validation
  (`attribute.0.field` errors), confirm removal. See [docs/repeater.md](docs/repeater.md).
- **`HasOneOfMany` / `MorphOneOfMany`** and the promoter API
  `HasOne::ofMany()` / `MorphOne::ofMany()` with `latestByTimestamp()`.
- **`HasOneThrough`** and **`HasManyThrough`** relationship fields.
- **`Stack` + `Line`** composite display field.
- **`Slug`, `PasswordConfirmation`, `Timezone`** form fields.
- **`BooleanGroup`, `Avatar`, `UiAvatar`, `Audio`** choice/media fields.
- **`Icon` field ⭐** — Phosphor icon picker (100% Martis differential).

#### Added — ⭐ Martis differentials
- **Repeater differentials** (5):
  - `asPolymorphic($typeColumn, $payloadColumn)` — single-table
    storage discriminated by row type, useful for page-builder rows.
  - `dependsOn([attributes])` — exposes parent record attributes to
    every field inside a row.
  - Cardinality + UX — `minRows`, `maxRows`, `collapsible`,
    `collapsedByDefault`, `reorderable(?$orderColumn)` with native
    HTML5 drag-and-drop.
  - `Repeatable::icon/color/title/badgeCount` — dynamic row header
    decorations (template strings or closures).
  - `rowTemplates()` pre-filled presets in the Add menu, one-click
    duplicate row, bulk-paste modal that parses TSV/CSV/JSON (with
    `->hideDuplicate()` / `->hideBulkPaste()` opt-outs).
- **HasOne::ofMany differentials** (3) — "Latest-of-N" pill, aggregate
  tile via `aggregateVia(AggregateFunction, column)`, promoter API.
- **Through-relationship differentials** (3) — `throughBreadcrumb()`
  tooltip, `countBadge()` index badge, `HasManyThrough` inherits
  `HasMany` toolbar/filter controls.
- **Stack differentials** (3) — Line `variant` enum, `subtitleFrom`,
  compact-divider mode.
- **Unsaved-changes guard** (Camada A+B) with `UnsavedChangesConfig`
  and full-page form guard via `useUnsavedChangesGuard`.
- **Action event JSON viewer** — Original/Changes rendered with
  syntax highlighting via the `Code` field.

#### Added — Enum-first API
Finite strings are now PHP enums throughout the public API:
`SortDirection`, `FilterType`, `MetricType`, `MetricRange`,
`MetricWidthPreset`, `TrendPeriod`, `TrashedFilter`, `DefaultRowAction`,
`LineVariant`, `BadgeType`, `MenuItemType`, `RepeaterStorage`. Contracts
(`ResourceContract`, `FilterContract`, `MetricContract`) updated to
return enums; `ModalSize::Fullscreen` replaces the boolean `fullscreen`
flag on Actions.

#### Added — Developer experience
- Field-level **`tooltip()`** with HTML content support.
- Effective-per-page resolution — if a user-submitted `perPage` is not
  in `perPageOptions`, the controller clamps to the first allowed value.
- Modal history locks audit — `useModalHistoryLock` (hard) vs.
  `useModalHistoryBackToClose` (soft). TrixField ImageModal upgraded.
- Drawer Detail → Update swap in place (no route change).
- Drawer footer buttons aligned to modal button classes.

#### Fixed
- Validator `customAttributes` wired package-wide so validation messages
  render the field label, not the underlying column name.
- BelongsToMany/MorphToMany "attach" SQL ambiguity on `id` column.
- Attach modal overlay full-page coverage via portal.
- Unsaved-changes dialog re-opening after discard — cleanup now checks
  the history sentinel before popping.
- `isDirty` false-positives when fields normalise on mount (rebases
  baseline 250 ms after the record loads).
- TeamMember, Skill, Tag seeds and playground extensions.

#### Documentation
- New [docs/repeater.md](docs/repeater.md) covering all storage modes,
  the Repeatable API and every ⭐ Martis differential with examples.
- differentials.md — dedicated Repeater section (D1–D5).
- fields.md — Repeater entry in the field-type reference.
- README.md — Repeater link in the documentation index.

#### Tests
- **1298 Pest tests** (11 new `RepeaterFieldTest`) — 3054 assertions.
- **8 Playwright specs** for the Repeater showcase
  (`repeater-field.spec.ts`).
- Playground showcases the JSON Repeater (Marcos & Fases) and the
  polymorphic Repeater (Blocos do Projeto) on `Project`.
