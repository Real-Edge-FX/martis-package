# Martis Documentation

A modern, open-source admin engine for Laravel — the developer-friendly alternative to Laravel Nova.

## What is Martis?

Martis is a **resource-driven admin panel** for Laravel applications. It provides automatic CRUD interfaces generated from your Eloquent models, with a React + TypeScript frontend and a powerful override system that lets you customize everything without forking.

### Key Features

- **Resource-driven CRUD** — Define fields once, get index/detail/create/edit views automatically
- **31 Field Types** — Text, Number, Boolean, Select, Date, BelongsTo, HasMany, File, Image, Code, Markdown, and more
- **Override System** — Replace any component at 4 granularity levels (explicit key, per-resource, per-type, global)
- **React + TypeScript Frontend** — Modern SPA with React 18, React Router, TanStack Query
- **Internationalization** — Built-in i18n with dynamic translation loading (EN, PT-BR, PT-PT)
- **Dark/Light Theme** — Full theme support with automatic persistence
- **Installable via Composer** — `composer require martis/martis`
- **Artisan Commands** — Scaffold resources and custom components from the CLI
- **Auto-Discovery** — Resources are registered automatically, no manual configuration needed
- **API-First** — Full REST API with automatic Swagger documentation via Scramble

### Architecture Philosophy

> **Nova parity is the baseline. Architectural superiority in customization is the competitive edge.**

1. Backend never renders UI — everything is contract-based
2. Nothing is hardcoded to the default frontend
3. Every component is prepared for override
4. Contracts enforce extensibility at every layer

## Documentation Index

### Getting Started

- [Installation Guide](installation-guide.md) — Add Martis to your Laravel application
- [Quick Start](setup/quickstart.md) — Development workflow and first steps
- [Troubleshooting](setup/troubleshooting.md) — Common issues and solutions

### Core Concepts

- [Resources](resources.md) — Define admin panels for your Eloquent models
- [Fields Reference](fields.md) — All 31 field types with configuration options
- [Override System](overrides.md) — Customize components, layouts, and behaviors
- [Built-in Components](components.md) — UI components available in the frontend

### Architecture

- [Technology Stack](architecture/stack.md) — Languages, frameworks, and infrastructure
- [Architectural Decisions](architecture/decisions.md) — ADRs explaining design choices
- [API Overview](api/overview.md) — REST API endpoints and response formats

### Status

- [Parity Map](PARITY_MAP.md) — Feature parity tracker vs Laravel Nova v5

## Quick Links

| Link | Description |
|------|-------------|
| Admin Panel | `http://martis.realedgefx.com/martis` |
| API Docs (Swagger) | `http://martis.realedgefx.com/docs/api` |
| Telescope (Debug) | `http://martis.realedgefx.com/telescope` |
| Dev Credentials | `admin@martis.local` / `password` |

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

- **31 field types** implemented and tested
- **Full CRUD** — Index, Detail, Create, Edit, Delete with soft-delete support
- **Override System v1** — componentRegistry + layoutRegistry + drawer overrides
- **i18n** — EN + PT-BR + PT-PT, dynamic translation endpoint
- **HasMany relationships** — Inline tables with full CRUD on detail pages
- **File/Image uploads** — Configurable disk, thumbnails, drag-drop, validation
- **Phosphor Icons** — 1,512 icons available via `icon()`
- **Global Search** — Cross-resource search from the top bar
- **Test Coverage** — 180+ PHP tests, 43 TypeScript tests, 13 E2E tests
- **CI/CD** — Automated via GitHub Actions self-hosted runner
