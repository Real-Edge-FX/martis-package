# Martis Documentation

A modern, open-source admin engine for Laravel — the developer-friendly alternative to Laravel Nova.

## What is Martis?

Martis is a **resource-driven admin panel** for Laravel applications. It provides automatic CRUD interfaces generated from your Eloquent models, with a React + TypeScript frontend and a powerful override system that lets you customize everything without forking.

### Key Features

- **Resource-driven CRUD** — Define fields once, get index/detail/create/edit views automatically
- **32 Field Types** — Text, Number, Boolean, Select, Date, BelongsTo, HasMany, File, Image, Code, Markdown, and more
- **Override System** — Replace any component at 4 granularity levels (explicit key, per-resource, per-type, global)
- **React + TypeScript Frontend** — Modern SPA with React 18, TypeScript, React Router, TanStack Query
- **Internationalization** — Built-in i18n with dynamic translation loading (EN, PT-BR, PT-PT)
- **Dark/Light Theme** — Full theme support with automatic persistence
- **Installable via Composer** — `composer require martis/martis`
- **Actions System** — Bulk, inline, standalone, queued, and destructive actions with full confirmation flow
- **Artisan Commands** — Scaffold resources and custom components from the CLI
- **Auto-Discovery** — Resources are registered automatically, no manual configuration needed
- **API-First** — Full REST API with automatic Swagger documentation via Scramble

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
| 4.5 | **[Panels & Tabs](panels-and-tabs.md)** | Panel e Tab layouts — Panel básico, collapsible, collapsedByDefault, limit; TabGroup com múltiplas abas; combinação de Panels dentro de Tabs; showcase no playground; serialização JSON |
| 5 | **[Fields Reference](fields.md)** | All 32 field types — configuration options, visibility flags, validation rules, relationship fields (BelongsTo, HasMany, BelongsToMany, MorphTo), enums, PrimeReact prop passthrough |
| 6 | **[Relationships](relationships.md)** | Relationship fields — BelongsTo, HasMany, BelongsToMany (pivot fields, attach/detach), MorphTo, choosing the right field |
| 6.5 | **[Filters](filters.md)** | Filters framework — SelectFilter, BooleanFilter, DateFilter, DateRangeFilter, custom filters, default values, dynamic filters, API reference |
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
├── fields.md ....................... Fields reference (32 types)
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

- **32 field types** implemented and tested
- **Full CRUD** — Index, Detail, Create, Edit, Delete with soft-delete support
- **Override System v1** — componentRegistry + layoutRegistry + drawer overrides
- **i18n** — EN + PT-BR + PT-PT, dynamic translation endpoint
- **HasMany relationships** — Inline tables with full CRUD on detail pages
- **File/Image uploads** — Configurable disk, thumbnails, drag-drop, validation
- **Phosphor Icons** — 1,512 icons available via `icon()`
- **Global Search** — Cross-resource search from the top bar
- **Test Coverage** — 180+ PHP tests, 43 TypeScript tests, 13 E2E tests
- **CI/CD** — Automated via GitHub Actions self-hosted runner
