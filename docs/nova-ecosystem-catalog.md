# Nova v5 Ecosystem ↔ Martis Catalog

> Companion to [migration-from-nova.md](migration-from-nova.md). This file maps the most-used third-party Nova v5 packages and add-ons to their Martis status: **built-in**, **port available**, **build-it-yourself**, or **wont't ship**.
> Scope: the catalog focuses on what teams typically install on top of a fresh Nova app. It is curated, not exhaustive — open a PR if you find a gap.
> Honesty rule: Martis is MIT and pre-1.0. Several Nova add-ons ($-priced or BSL-licensed) cannot be ported as-is. Where that is the case the entry points to a workable substitute or marks the gap explicitly.

## Status legend

| Symbol | Meaning |
|---|---|
| ✅ | Built-in to Martis. No add-on needed. |
| 🛠 | Build-it-yourself with the documented Martis primitives. Pointer included. |
| 🟡 | Partial coverage. Core capability ships; specific Nova add-on features need work. |
| 🚫 | Won't ship in core. Out of scope or licensing-incompatible. |

---

## Auth / SSO / Account

| Nova add-on | Description | Martis status | Pointer |
|---|---|---|---|
| Two-factor authentication tools | TOTP + recovery codes | ✅ Built-in | [authentication.md § 2FA](authentication.md) |
| SSO with OAuth/OIDC | Google, Azure, Okta, etc. | ✅ Built-in (pluggable provider contract) | [sso.md](sso.md) |
| Profile + avatar | User profile page with avatar upload | ✅ Built-in | [authentication.md § Profile](authentication.md) |
| Self-service registration | Public sign-up | ✅ Built-in (configurable) | [authentication.md § Registration](authentication.md) |
| Impersonation tools | Login as another user | ✅ Built-in (`Martis\Impersonation\ImpersonationManager`, opt-in via the `martis-impersonate` gate, v0.10) | [impersonation.md](impersonation.md) |
| Password reset | Forgot-password flow | ✅ Built-in | Standard Laravel flow |

## Theming / Branding

| Nova add-on | Description | Martis status | Pointer |
|---|---|---|---|
| Custom themes (light/dark) | Brand colours, typography, density | ✅ Built-in (94-token design system) | [theming.md](theming.md) |
| Per-user theme preferences | Each user picks theme/density | ✅ Built-in | [preferences.md](preferences.md) |
| Custom CSS injection | Inject CSS at runtime | ✅ Built-in (`config('martis.theme.custom_css')`) | [theming.md](theming.md) |
| Sidebar layout presets | Top-nav, minimal, etc. | ✅ Built-in (3 presets) | [overrides.md § Layout Registry](overrides.md) |

## Field types and form UX

| Nova add-on | Description | Martis status | Pointer |
|---|---|---|---|
| Tabs / Panels / Sections | Form layout primitives | ✅ Built-in | [panels-and-tabs.md](panels-and-tabs.md) |
| Repeater / Flexible content | Repeating row groups | ✅ Built-in (`Repeater` field, polymorphic mode) | [repeater.md](repeater.md) |
| Color picker | Color field with palette | ✅ Built-in (`Color`) | [fields.md § Color](fields.md) |
| Country picker | Country select | ✅ Built-in (`Country`) | [fields.md § Country](fields.md) |
| Currency input | Currency with symbol | ✅ Built-in (`Currency`) | [fields.md § Currency](fields.md) |
| Phosphor / Heroicons picker | Icon-picker field | ✅ Built-in (`Icon`, 1,512 Phosphor) ⭐ | [fields.md § Icon](fields.md) |
| Tag input | Free-form tags | ✅ Built-in (`Tag`) | [fields.md § Tag](fields.md) |
| Slug | Live slug w/ collision check | ✅ Built-in (`Slug`) ⭐ | [fields.md § Slug](fields.md) |
| Audio waveform | Audio file with canvas waveform | ✅ Built-in (`Audio`) ⭐ | [fields.md § Audio](fields.md) |
| Timezone | Grouped IANA picker w/ live clock | ✅ Built-in (`Timezone`) ⭐ | [fields.md § Timezone](fields.md) |
| Markdown / Trix | Rich text | ✅ Built-in | [fields.md](fields.md) |
| Code | Syntax-highlighted code block | ✅ Built-in (`Code`) | [fields.md § Code](fields.md) |
| Reactive / dependent fields | Field reacts to sibling values | ✅ Built-in (`->dependsOn(['attr'], Closure)`) | [fields.md § Reactive fields](fields.md) |
| File / Image multiple | Multi-upload | ✅ Built-in (`->multiple()`) | [fields.md § File](fields.md) |
| KeyValue | Editable key/value object | ✅ Built-in (`KeyValue`) | [fields.md § KeyValue](fields.md) |

## Search / Index UX

| Nova add-on | Description | Martis status | Pointer |
|---|---|---|---|
| Global search | Cross-resource palette | ✅ Built-in (per-resource config + `searchOrderBy`) | [global-search.md](global-search.md) |
| Sticky filters | Filters survive navigation | ✅ Built-in (sticky views, v0.8) | [sticky_views.md](sticky_views.md) |
| Saved filter sets | Named filter presets per user | 🛠 Use Lenses with `withDefaultFilters([...])` | [lenses.md](lenses.md) |
| Saved searches | Named search templates | 🛠 Same approach: model the search as a Lens | [lenses.md](lenses.md) |
| Excel / CSV export | Per-row / bulk export | 🛠 Implement an Action that returns `ActionResponse::redirect($downloadUrl)`; storage is yours | [actions.md](actions.md) |
| Quick filters bar | Filter chips above the table | ✅ Built-in (filter pills) | [filters.md](filters.md) |

## Reporting / Analytics

| Nova add-on | Description | Martis status | Pointer |
|---|---|---|---|
| Dashboard cards (Value/Trend/Partition/Progress) | KPI cards | ✅ Built-in | [metrics.md](metrics.md), [dashboards.md](dashboards.md) |
| Multi-dashboard layout | Several dashboards w/ filters | ✅ Built-in | [dashboards.md](dashboards.md) |
| Auto-refresh / polling | Live KPIs | ✅ Built-in (`->polling()`) | [metrics.md § Polling](metrics.md) |
| Excel pivot reports | Cross-tab reports | 🛠 Build a custom Tool page (see [tools.md](tools.md)) backed by the host app's reporting layer |
| Activity feed / audit log | Per-record event timeline | ✅ Built-in (`ActivityFeedMetric`) | [metrics.md § ActivityFeed](metrics.md) |
| Endpoint table card | Generic tabular card on dashboards | ✅ Built-in (`EndpointTableMetric`) | [metrics.md](metrics.md) |

## Notifications

| Nova add-on | Description | Martis status | Pointer |
|---|---|---|---|
| In-app notification bell | Persistent per-user notifications | ✅ Built-in (v0.8) | [notifications.md](notifications.md) |
| Toast notifications | Transient feedback | ✅ Built-in | Built-in `useToast()` |
| Email digest of notifications | Daily summary email | 🛠 Use Laravel Notifications via `mail` channel — works alongside the bell |

## Workflow / Operations

| Nova add-on | Description | Martis status | Pointer |
|---|---|---|---|
| Bulk actions | Multi-row actions w/ confirm | ✅ Built-in | [actions.md](actions.md) |
| Queued actions | Actions via queue worker | ✅ Built-in (`ShouldQueue` trait) | [actions.md § Queued](actions.md) |
| Action log / events | Persisted action audit trail | ✅ Built-in (`martis_action_events`) | [actions.md § Action Log](actions.md) |
| Scheduled tasks UI | View / trigger scheduled commands | 🚫 Won't ship in core — Telescope / Horizon already serve this; consumers integrate them directly |
| Approval workflows | Multi-step approval | 🛠 Build with Action + State enum on the model |
| Draft / publish | Two-stage content pipeline | 🛠 Use a `published_at` column + `Slug::make()->freezeAfterPublish()` |

## Custom UI / Pages

| Nova add-on | Description | Martis status | Pointer |
|---|---|---|---|
| Custom Tools (sidebar pages) | Free-form non-resource pages | ✅ Built-in (v0.10 — `Martis\Tools\Tool` primitive) | [tools.md](tools.md) |
| Custom Cards on dashboards | Free-form dashboard cards | ✅ Built-in (any React component is a card) | [dashboards.md](dashboards.md) |
| Custom Resource components | Replace ResourceIndex/Detail/Create/Update | ✅ Built-in (component override registry) | [overrides.md](overrides.md) |
| Drawer pages | Side-drawer create/update/detail | ✅ Built-in | [overrides.md § Drawer](overrides.md) |
| Custom Field types | Build a field with custom React renderer | ✅ Built-in (`martis:field`) | [fields.md § Custom fields](fields.md) |

## Data / DevOps

| Nova add-on | Description | Martis status | Pointer |
|---|---|---|---|
| Database query optimisation | N+1 detection | 🚫 Use Laravel Telescope or Pulse — Martis does not duplicate this |
| Backup management UI | Trigger / browse backups | 🚫 Use `spatie/laravel-backup` directly; no Martis wrapper |
| Mail logs UI | View sent emails | 🚫 Telescope's mail tab covers this |
| Cache control | Toggle / clear caches per layer | ✅ Built-in (`MartisCache`) | [cache.md](cache.md) |
| API tokens UI | Manage Sanctum tokens | 🛠 Build as a custom Tool over Sanctum's PAT API |

## Licensing-blocked

These Nova add-ons cannot be ported as-is because of their license. Equivalent functionality is achievable, but each one needs a clean-room implementation.

| Nova add-on | Status | Recommended substitute |
|---|---|---|
| Nova Stripe (paid add-on) | 🚫 | Build a custom Tool over `laravel/cashier`. |
| Nova Inspector (BSL) | 🚫 | Build a custom Tool that exposes `php artisan about` + composer/git status. |

---

## How this catalog stays accurate

- Every entry should point to either a Martis doc page (`docs/*.md`) or an external resource. If a Pointer says "open issue" the work is real.
- "Built-in" claims are auto-tested by the Task-18 ParitySurface tripwire (`tests/Feature/ParitySurfaceTest.php`) where the contract is part of the public API.
- When a new Martis subsystem ships, update both this file and [PARITY_MAP.md](PARITY_MAP.md). The two files are intentionally redundant: PARITY_MAP is the engineering scoreboard, this file is the consumer-facing "do I need to install anything?" map.
