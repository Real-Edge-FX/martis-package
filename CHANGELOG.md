# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/)
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased] — v0.7.0-beta

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
- PARITY_MAP.md — Repeater marked DONE.
- differentials.md — dedicated Repeater section (D1–D5).
- fields.md — Repeater entry in the field-type reference.
- README.md — Repeater link in the documentation index.

#### Tests
- **1298 Pest tests** (11 new `RepeaterFieldTest`) — 3054 assertions.
- **8 Playwright specs** for the Repeater showcase
  (`repeater-field.spec.ts`).
- Playground showcases the JSON Repeater (Marcos & Fases) and the
  polymorphic Repeater (Blocos do Projeto) on `Project`.
