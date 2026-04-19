# Martis Documentation

A modern, open-source admin engine for Laravel — the developer-friendly alternative to Laravel Nova.

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

### Highlights since v0.3

- **Lenses** — custom filtered views with sticky summary rows, per-lens query cache, default filters pre-applied, URL state sync ([lenses.md](lenses.md))
- **Metrics & Dashboards** — Value / Trend / Partition / Progress cards, dashboard-level filters, 12-column responsive grid, polling with LIVE indicator ([metrics.md](metrics.md), [dashboards.md](dashboards.md))
- **Declarative menus** — `Martis::mainMenu(...)` with `Menu`, `MenuSection`, `MenuItem` and per-resource overrides ([menus.md](menus.md))
- **Relationship toolbar hide flags** — 9 per-field toggles (`hideSearch`, `hideCreateButton`, `hidePerPageSelector`, `hideSoftDeleteToggle`, `hideViewAction`, `hideEditAction`, `hideDeleteAction`, `hideRestoreAction`, `hideForceDeleteAction`) via `src/Fields/Concerns/ControlsRelationshipToolbar.php`
- **Soft-delete filter dropdown in relation panels** — `HasMany` / `MorphMany` relation toolbars now expose the same `?trashed=with|only` filter used on the resource index (Option A clamp shared with `resolvedPerPage()`)
- **Modal history locks** — two hooks in `resources/js/lib/historyLock.ts`: `useModalHistoryLock` (hard — absorbs browser back until UI closes the modal) and `useModalHistoryBackToClose` (soft — first back closes, second navigates). See [differentials.md](differentials.md#modal-history-locks)
- **`resolvedPerPage()` clamp** — if a resource declares a `perPage()` that is not present in `perPageOptions()`, the effective value clamps to the first option so the dropdown and the actual filter stay in sync
- **`BelongsToMany` / `MorphToMany` shell migration** — both fields now share `RelationshipTableShell` with `HasMany`, unifying search, pagination, toolbar controls and modal stack (AttachModal, DetachConfirmModal, EditPivotModal, PivotActionModal)

### Architecture Philosophy

> **Nova parity is the baseline. Architectural superiority in customization is the competitive edge.**

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
| 6.5 | **[Filters](filters.md)** | Filters framework — SelectFilter, BooleanFilter, DateFilter, DateRangeFilter, custom filters, default values, dynamic filters, API reference |
| 6.6 | **[Metrics](metrics.md)** | Metrics system — Value, Trend, Partition, Progress metrics, query helpers, ranges, caching, card width, auto-refresh |
| 6.7 | **[Dashboards](dashboards.md)** | Dashboard system — multiple dashboards, dashboard filters, refresh button, registration, fallback |
| 7 | **[Actions](actions.md)** | Actions system — bulk, inline, standalone, queued, destructive actions, closure actions, dry-run preview, action fields, responses, authorization, action events |
| 8 | **[Override System](overrides.md)** | Component customization — 4-tier resolution (explicit key → per-resource → per-type → global), componentRegistry, layoutRegistry, drawer overrides, `boot.ts` registration |
| 8.5 | **[Menus](menus.md)** | Declarative navigation — `Martis::mainMenu(...)`, `Menu`, `MenuSection`, `MenuItem`, resource-level `menuItem()`, and `/api/navigation` |
| 9 | **[Built-in Components](components.md)** | Every UI component in the frontend — DataTable, ResourceForm, DetailView, modals, search bar, sidebar, breadcrumbs, navigation, theme toggle, toast notifications |
| 10 | **[Authentication](authentication.md)** | Login, logout, two-factor authentication (2FA), user profile, avatar uploads, user menu configuration |
| 11 | **[Configuration](configuration.md)** | Complete `config/martis.php` reference — every option with type, default, and description |

### Architecture & Design

| # | Document | What You Will Learn |
|---|----------|---------------------|
| 12 | **[Technology Stack](architecture/stack.md)** | Full stack breakdown — PHP 8.2+, Laravel 11/12, React 18, PrimeReact, Tailwind CSS, Vite, Pest, Vitest, PHPStan |
| 13 | **[Architectural Decisions](architecture/decisions.md)** | 15 ADRs — why PrimeReact over Headless UI, contract-first design, backend-driven field resolution, and other key decisions |
| 14 | **[REST API Overview](api/overview.md)** | All backend endpoints — resource CRUD, schema, search, file upload, relationship endpoints, authentication, request/response formats, error handling |

### Project Status

| # | Document | What You Will Learn |
|---|----------|---------------------|
| 15 | **[Martis Differentials](differentials.md)** | All features unique to Martis — override system, action extensions, filter extensions, authentication, frontend utilities |
| 16 | **[Nova v5 Parity Map](PARITY_MAP.md)** | Feature-by-feature comparison with Laravel Nova v5 — what is done, in progress, and planned |

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
├── filters.md ...................... Filters framework
├── actions.md ...................... Actions system
├── overrides.md .................... Override system
├── components.md ................... Built-in UI components
├── authentication.md ............... Login, 2FA, profile
├── configuration.md ................ Config reference
├── differentials.md ................ Martis differentials (unique features)
├── PARITY_MAP.md ................... Nova v5 parity tracker
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

- **50 field types** implemented and tested (including 12 relation field types)
- **Full CRUD** — Index, Detail, Create, Edit, Delete with soft-delete support
- **Override System v1** — componentRegistry + layoutRegistry + drawer overrides
- **i18n** — EN + PT-BR + PT-PT, dynamic translation endpoint
- **Relationship panels** — Inline tables with full CRUD on detail pages for `HasMany`, `MorphMany`, `BelongsToMany`, `MorphToMany`, including shared toolbar, soft-delete dropdown, and per-field hide flags
- **File/Image uploads** — Configurable disk, thumbnails, drag-drop, validation
- **Phosphor Icons** — 1,512 icons available via `icon()`
- **Global Search** — Cross-resource search from the top bar
- **Lenses, Metrics, Dashboards, Menus** — full implementations (see [lenses.md](lenses.md), [metrics.md](metrics.md), [dashboards.md](dashboards.md), [menus.md](menus.md))
- **Test Coverage** — 180+ PHP tests, 43 TypeScript tests, 13 E2E tests
- **CI/CD** — Automated via GitHub Actions self-hosted runner

---

## Releases

The canonical record for the current alpha line is [release-v0.3.0-alpha.md](release-v0.3.0-alpha.md). More detailed release notes and the full delta history live on the repository tags and in the internal `knowledge/RELEASE_HISTORY.md` tracker. Individual release record files in `docs/` are produced only by the release owner — feature work and docs audits do not create them.
