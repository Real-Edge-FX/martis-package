# Martis Documentation

A modern, open-source admin engine for Laravel.

## What is Martis?

Martis is a **resource-driven admin panel** for Laravel applications. It provides automatic CRUD interfaces generated from your Eloquent models, with a React + TypeScript frontend and a powerful override system that lets you customize everything without forking.

### Key Features

- **Resource-driven CRUD** — Define fields once, get index/detail/create/edit views automatically
- **50 Field Types** — Text, Number, Boolean, Select, Date, File, Image, Code, Markdown, Badge, Avatar, Icon, Slug, Timezone, Audio, and more
- **12 Relationship Fields** — `BelongsTo`, `HasOne`, `HasOneOfMany`, `HasOneThrough`, `HasMany`, `HasManyThrough`, `BelongsToMany`, `MorphTo`, `MorphOne`, `MorphOneOfMany`, `MorphMany`, `MorphToMany` — all with inline CRUD, attach/detach, pivot fields and per-field toolbar controls (see [fields.md](fields.md) and [relationships.md](relationships.md))
- **Lenses & Metrics & Dashboards** — Custom filtered views (`src/Lenses/`), Value/Trend/Partition/Progress metrics (`src/Metrics/`), and multi-dashboard layouts (`src/Dashboards/`) with polling, caching, and filters
- **Declarative Navigation** — `Menu`, `MenuSection`, `MenuItem` (`src/Menu/`) with per-resource `menuItem()` overrides
- **Override System** — Replace any component at 4 granularity levels (explicit key, per-resource, per-type, global)
- **React + TypeScript Frontend** — Modern SPA with React 18, TypeScript, React Router, TanStack Query
- **Internationalization** — Built-in i18n with dynamic translation loading (EN, PT-BR, PT-PT)
- **Dark/Light Theme** — Full theme support with automatic persistence
- **Installable via Composer** — `composer require martis/martis`
- **Actions System** — Bulk, inline, standalone, queued, and destructive actions with full confirmation flow
- **Artisan Commands** — Scaffold resources and custom components from the CLI
- **Auto-Discovery** — Resources are registered automatically, no manual configuration needed
- **API-First** — Full REST API with automatic Swagger documentation via Scramble

### Highlights since v0.6

#### v0.9.0-beta (Forms / Index UX, Locale Extensibility, Global Search, SSO)

- **Reactive fields** — `Field::dependsOn(['attr'], Closure)` with server-side resolution via `POST /api/resources/{r}/sync-field`, debounced and `AbortController`-cancelled on the client ([fields.md § Reactive fields](fields.md#reactive-fields--dependsonfield-closure))
- **Closure-aware setters** — 13 field setters (`nullable`, `required`, `readonly`, `default`, `placeholder`, `help`, `tooltip`, `withLabel`, `rules`, `Select::options`, `MultiSelect::options`, `BooleanGroup::options`, etc.) accept a `Closure` resolved at request time
- **Customisation hooks `?Request` 4th argument** — `resolveUsing` / `fillUsing` / `displayUsing` callbacks; `displayUsing(array)` accepts a chainable transformation pipeline
- **Context-aware validation** — `creationRules()` / `updateRules()` layer on top of `rules()`; `immutable()` flags a field as writable on create, readonly on update
- **Save variants** — `Create & add another`, `Create & view list`, `Save & continue editing`, `Save & view list` ([resources.md § Save variants](resources.md))
- **Reset filters** toolbar button — clears only the active filter set; coexists with `Reset view`
- **Global Search per-resource config** — `globallySearchable()` accepts `bool|array{enabled?, limit?, min_query?}`; new `searchOrderBy()` hook ([global-search.md](global-search.md))
- **"View all N matches in {resource}"** palette overflow item lands on the resource index with the search query pre-applied
- **Locale extensibility** — per-key deep merge of consumer overrides, configurable host-app namespaces (`martis.locales.app_namespaces`), configurable fallback chain (`martis.locales.fallback_chain`) ([i18n.md](i18n.md))
- **SSO subsystem** — pluggable provider contract (`AzureProvider` reference impl), identity-to-user resolver, role mapping (column / config / callable), permission adapters (Spatie / native / callable), idempotent `martis:sso <provider>` generator ([sso.md](sso.md))

#### v0.8.0-beta (Sticky Views, Notifications, Cache control)

- **Sticky views** — per-resource session storage of search / sort / filters / pagination / trashed-toggle / `filtersOpen`; survives back-navigation; drops on resource change ([sticky_views.md](sticky_views.md))
- **In-app notifications** — topbar bell dropdown over Laravel's standard `notifications` table + `MartisNotification::make(title:, message:, level:)` inline factory ([notifications.md](notifications.md))
- **Cache control surface** — `MartisCache::extend('name', enabled, ttl)` for host-app layers + `/martis/system/cache` admin page (toggle / version / clear per-type) ([cache.md](cache.md))

#### Track B foundation (v0.4–v0.6)

- **Lenses** — custom filtered views with sticky summary rows, per-lens query cache, default filters pre-applied, URL state sync ([lenses.md](lenses.md))
- **Metrics & Dashboards** — Value / Trend / Partition / Progress cards, dashboard-level filters, 12-column responsive grid, polling with LIVE indicator ([metrics.md](metrics.md), [dashboards.md](dashboards.md))
- **Declarative menus** — `Martis::mainMenu(...)` with `Menu`, `MenuSection`, `MenuItem` and per-resource overrides ([menus.md](menus.md))
- **Relationship toolbar hide flags** — 9 per-field toggles via `src/Fields/Concerns/ControlsRelationshipToolbar.php`
- **Soft-delete filter dropdown in relation panels** — `HasMany` / `MorphMany` relation toolbars expose `?trashed=with|only`
- **Modal history locks** — `useModalHistoryLock` (hard) and `useModalHistoryBackToClose` (soft) in `resources/js/lib/historyLock.ts`
- **`BelongsToMany` / `MorphToMany` shell migration** — share `RelationshipTableShell` with the rest of the relation suite

### Architecture Philosophy

> **Architectural superiority in customization is the competitive edge.**

1. Backend never renders UI — everything is contract-based
2. Nothing is hardcoded to the default frontend
3. Every component is prepared for override
4. Contracts enforce extensibility at every layer

---

## Documentation Menu

### Getting Started

| # | Document | What You Will Learn |
|---|----------|---------------------|
| 1 | **[Installation Guide](installation-guide.md)** | Add Martis to an existing Laravel app — Composer install, asset publishing, config, database setup, creating your first resource |
| 2 | **[Quick Start](setup/quickstart.md)** | Development workflow — running the dev server, hot reload, building assets, deploying to production |
| 3 | **[Troubleshooting](setup/troubleshooting.md)** | Common errors and solutions — asset 404s, migration issues, permission problems, build failures |

### Core Concepts

| # | Document | What You Will Learn |
|---|----------|---------------------|
| 4 | **[Resources](resources.md)** | Resource classes — model binding, field definitions, context-aware resolution, lifecycle hooks, authorization, search configuration, pagination, soft deletes, table customization |
| 4.5 | **[Panels & Tabs](panels-and-tabs.md)** | Panel and Tab layouts — basic Panel, collapsible, collapsedByDefault, limit; TabGroup with multiple tabs; nesting Panels inside Tabs; playground showcase; JSON serialization |
| 5 | **[Fields Reference](fields.md)** | All 50 field types — configuration options, visibility flags, validation rules, relationship fields (all 12 relation types), enums, PrimeReact prop passthrough |
| 6 | **[Relationships](relationships.md)** | Relationship fields — 12 types (`BelongsTo`, `HasOne`, `HasOneOfMany`, `HasOneThrough`, `HasMany`, `HasManyThrough`, `BelongsToMany`, `MorphTo`, `MorphOne`, `MorphOneOfMany`, `MorphMany`, `MorphToMany`), pivot fields, attach/detach, toolbar hide flags, soft-delete filter dropdown |
| 6.1 | **[Repeater](repeater.md)** | Repeatable row widget — JSON / HasMany / ⭐ Polymorphic storage, multi-type, row templates, duplicate, bulk paste, collapse, drag-and-drop reorder, min/max, dependsOn |
| 6.2 | **[Grid Layout](grid-layout.md)** | Multi-column form layouts via `Section::columns()` and `Field::span()` — responsive by default, zero config |
| 6.5 | **[Filters](filters.md)** | Filters framework — SelectFilter, BooleanFilter, DateFilter, DateRangeFilter, custom filters, default values, dynamic filters, API reference |
| 6.6 | **[Lenses](lenses.md)** | Custom filtered views — `Lens` base class, `summary()`, `cacheFor()`, `withDefaultFilters()`, URL state sync |
| 6.7 | **[Metrics](metrics.md)** | Metrics system — Value, Trend, Partition, Progress metrics, query helpers, ranges, caching, card width, auto-refresh |
| 6.8 | **[Dashboards](dashboards.md)** | Dashboard system — multiple dashboards, dashboard filters, refresh button, registration, fallback |
| 6.9 | **[Global Search](global-search.md)** | Cross-resource search — per-resource `globallySearchable(bool\|array)`, `searchOrderBy()`, "View all N matches" overflow |
| 6.10 | **[Sticky Views](sticky_views.md)** | Per-resource session state for search / sort / filters / pagination / trashed / `filtersOpen` (v0.8) |
| 7 | **[Actions](actions.md)** | Actions system — bulk, inline, standalone, queued, destructive actions, closure actions, dry-run preview, action fields, responses, authorization, action events |
| 7.5 | **[Default Row Actions](default_row_actions.md)** | Built-in inline action set per relation panel and index row |
| 8 | **[Override System](overrides.md)** | Component customization — 4-tier resolution (explicit key → per-resource → per-type → global), componentRegistry, layoutRegistry, drawer overrides, `boot.ts` registration |
| 8.5 | **[Menus](menus.md)** | Declarative navigation — `Martis::mainMenu(...)`, `Menu`, `MenuSection`, `MenuItem`, resource-level `menuItem()`, and `/api/navigation` |
| 9 | **[Built-in Components](components.md)** | Every UI component in the frontend — DataTable, ResourceForm, DetailView, modals, search bar, sidebar, breadcrumbs, navigation, theme toggle, toast notifications |
| 9.1 | **[Loader](loader.md)** | Global loader and per-surface skeletons — when each one fires, accessibility behaviour |
| 9.2 | **[In-app Notifications](notifications.md)** | Topbar bell + standard `notifications` table + `MartisNotification::make()` (v0.8) |
| 9.3 | **[Custom Tools](tools.md)** | Free-form sidebar pages — `Martis::tools([...])`, `MenuItem::tool()`, `/martis/api/tools` (v0.10) |
| 9.3.1 | **[Tool boot() patterns](tool-boot-patterns.md)** | When to put setup in `Tool::boot()` vs `AppServiceProvider::boot()` — decision rubric + 4 in-app patterns (routes, gates, schedules, listeners) |
| 10 | **[Authentication](authentication.md)** | Login / Register / 2FA challenge / error shell (`AuthFrame` + `AuthControls`), Google + password-reset config, self-service registration contract, user profile, avatar uploads, user menu configuration |
| 10.5 | **[SSO Subsystem](sso.md)** | Pluggable provider contract, identity-to-user resolver, role mapping, permission adapters, `martis:sso` generator (v0.9) |
| 10.6 | **[Impersonation](impersonation.md)** | Login as another user — opt-in master switch + `martis-impersonate` gate + REST + banner contract (v0.10) |
| 11 | **[Configuration](configuration.md)** | Complete `config/martis.php` reference — every option with type, default, and description |
| 11.1 | **[Theming](theming.md)** | 94-variable design system — token reference, light/dark modes, custom themes |
| 11.2 | **[User Preferences](preferences.md)** | ⭐ D1/D2/D3 — persisted per-user theme/accent/density/locale, URL presets, custom brand hex |
| 11.3 | **[Internationalisation](i18n.md)** | Adding locales, overriding strings, runtime language switching, per-key deep merge, `app_namespaces`, `fallback_chain` |
| 11.4 | **[Cache Control Surface](cache.md)** | `MartisCache::extend()`, runtime per-type toggle, `/martis/system/cache` admin page (v0.8) |

### Architecture & Design

| # | Document | What You Will Learn |
|---|----------|---------------------|
| 12 | **[Technology Stack](architecture/stack.md)** | Full stack breakdown — PHP 8.2+, Laravel 11/12, React 18, PrimeReact, Tailwind CSS, Vite, Pest, Vitest, PHPStan |
| 13 | **[Architectural Decisions](architecture/decisions.md)** | 15 ADRs — why PrimeReact over Headless UI, contract-first design, backend-driven field resolution, and other key decisions |
| 14 | **[REST API Overview](api/overview.md)** | All backend endpoints — resource CRUD, schema, search, file upload, relationship endpoints, authentication, request/response formats, error handling |

### Project Status

| # | Document | What You Will Learn |
|---|----------|---------------------|
| 15 | **[Martis Differentials](differentials.md)** | All distinctive features of Martis — override system, action extensions, filter extensions, authentication, frontend utilities |
| 16 | **[Release Process](release-process.md)** | How a release tag is cut — release branch flow, audit checklist, PR conventions |
| 17 | **[v1.0 Roadmap](v1-roadmap.md)** | Live checklist of remaining items before the v1.0.0 tag is cut |

---

## Quick Reference

### Folder Structure

```
docs/
├── README.md ........................ You are here — documentation hub
├── installation-guide.md ........... Installation & setup
├── resources.md .................... Resources reference
├── panels-and-tabs.md .............. Panels & Tabs layout guide
├── fields.md ....................... Fields reference (50 types)
├── relationships.md ................ Relationship fields guide
├── repeater.md ..................... Repeater widget
├── grid-layout.md .................. Multi-column form layouts
├── filters.md ...................... Filters framework
├── lenses.md ....................... Lenses (custom filtered views)
├── metrics.md ...................... Metrics (Value / Trend / Partition / Progress)
├── dashboards.md ................... Dashboards
├── menus.md ........................ Declarative navigation
├── global-search.md ................ Cross-resource search
├── sticky_views.md ................. Per-resource session state (v0.8)
├── actions.md ...................... Actions system
├── default_row_actions.md .......... Built-in inline action set
├── overrides.md .................... Override system
├── components.md ................... Built-in UI components
├── loader.md ....................... Global loader & skeletons
├── notifications.md ................ In-app notifications (v0.8)
├── tools.md ........................ Custom Tools — free-form sidebar pages (v0.10)
├── tool-boot-patterns.md ........... Decision rubric: Tool::boot() vs AppServiceProvider::boot()
├── authentication.md ............... Login, 2FA, profile
├── sso.md .......................... SSO subsystem (v0.9)
├── impersonation.md ................ Impersonation subsystem (v0.10)
├── configuration.md ................ Config reference
├── theming.md ...................... 94-token design system
├── preferences.md .................. User preferences (⭐ D1/D2/D3)
├── i18n.md ......................... Adding locales & translations
├── cache.md ........................ Cache control surface (v0.8)
├── differentials.md ................ Martis differentials (unique features)
├── release-process.md .............. How a release tag is cut
├── v1-roadmap.md ................... v1.0 readiness checklist
├── api/
│   └── overview.md ................. REST API reference
├── architecture/
│   ├── stack.md .................... Technology stack
│   └── decisions.md ................ Architectural Decision Records
└── setup/
    ├── quickstart.md ............... Development workflow
    └── troubleshooting.md .......... Common issues & fixes
```

### Quick Links

| Link | Description |
|------|-------------|
| Admin Panel | `http://martis.realedgefx.com/martis` |
| API Docs (Swagger) | `http://martis.realedgefx.com/docs/api` |
| Telescope (Debug) | `http://martis.realedgefx.com/telescope` |
| Dev Credentials | `admin@martis.local` / `password` |

### Artisan Commands

| Command | Description |
|---------|-------------|
| `martis:install` | Install the Martis admin panel |
| `martis:resource` | Create a new resource class |
| `martis:field` | Create a custom field (PHP + React TSX) |
| `martis:component` | Generate a React component with auto-registration |
| `martis:theme` | Scaffold a custom theme (dark + light mode) |
| `martis:user` | Create a new admin user |
| `martis:vendor-publish` | Publish package files (config, assets, views, lang) |

---

## Playground Resources

The playground application ships with pre-configured resources for development and testing:

| Resource | Model | Fields |
|----------|-------|--------|
| Users | `App\Models\User` | Text, Email, Password, Boolean, DateTime, HasMany |
| Posts | `App\Models\Post` | Text, Textarea, BelongsTo, Image, File, Date, DateTime |
| Categories | `App\Models\Category` | Text, Textarea, HasMany |
| Comments | `App\Models\Comment` | Text, Textarea, BelongsTo |
| Teams | `App\Models\Team` | Text, Boolean |

## Current State

- **50 field types** implemented (including 12 relation field types)
- **Full CRUD** — Index, Detail, Create, Edit, Delete with soft-delete support
- **Override System v1** — componentRegistry + layoutRegistry + drawer overrides
- **i18n** — EN + PT-BR + PT-PT with dynamic loading + per-key deep merge + configurable host-app namespaces + configurable fallback chain
- **Relationship panels** — Inline tables with full CRUD on detail pages for all 12 relation types (shared `RelationshipTableShell`, soft-delete dropdown, 9 per-field hide flags)
- **Reactive forms** — `Field::dependsOn()` server-side resolution + closure-aware setters across the field API + context-aware validation
- **In-app Notifications + Cache control + Sticky views** — see the v0.8 highlights above
- **SSO subsystem** — pluggable provider contract + role mapping + permission adapters + `martis:sso` generator (see [sso.md](sso.md))
- **File/Image uploads** — Configurable disk, thumbnails, drag-drop, validation
- **Phosphor Icons** — 1,512 icons available via `icon()`
- **Global Search** — Cross-resource search with per-resource config + `searchOrderBy()` + "View all N matches" overflow
- **Lenses, Metrics, Dashboards, Menus** — full implementations (see [lenses.md](lenses.md), [metrics.md](metrics.md), [dashboards.md](dashboards.md), [menus.md](menus.md))
- **CI/CD** — Automated via GitHub Actions self-hosted runner

---

## Releases

Full release history lives on the [GitHub Releases](https://github.com/Real-Edge-FX/martis-package/releases) page. The release process itself is documented in [release-process.md](release-process.md).
