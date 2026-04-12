<p align="center">
  <img src="resources/images/logo.png" alt="Martis" width="400">
</p>

<p align="center">
  <strong>A modern, open-source admin engine for Laravel.</strong><br>
  React-first. Context-aware. Built for developers who ship.
</p>

<p align="center">
  <a href="https://github.com/Real-Edge-FX/martis/releases"><img src="https://img.shields.io/github/v/tag/Real-Edge-FX/martis?style=flat-square&label=version" alt="Version"></a>
  <img src="https://img.shields.io/badge/PHP-8.2%2B-777BB4?style=flat-square&logo=php&logoColor=white" alt="PHP 8.2+">
  <img src="https://img.shields.io/badge/Laravel-11%2B-FF2D20?style=flat-square&logo=laravel&logoColor=white" alt="Laravel 11+">
  <img src="https://img.shields.io/badge/React-18-61DAFB?style=flat-square&logo=react&logoColor=black" alt="React 18">
  <a href="LICENSE"><img src="https://img.shields.io/badge/license-MIT-green?style=flat-square" alt="License"></a>
</p>

---

Martis is a full-featured admin panel engine for Laravel. It provides automatic CRUD, 36 built-in field types, relationship management, actions, global search, authentication, and a React SPA frontend — all driven from a single PHP resource class.

## Quick Start

```bash
composer require martis/martis
php artisan martis:install
```

Visit `/martis` to log in. See the [Installation Guide](docs/installation-guide.md) for full setup.

## Requirements

| Dependency | Version |
|------------|---------|
| PHP        | 8.2+    |
| Laravel    | 11+     |
| Node.js    | 20+     |
| pnpm       | 8+      |

## Features at a Glance

- **36 field types** — text, select, date, file, image, code, markdown, rich text, badge, status, currency, country, key-value, sparkline, and more
- **Relationship fields** — BelongsTo, HasOne, HasMany, BelongsToMany, MorphTo, MorphOne, MorphMany, MorphToMany with full inline CRUD
- **Actions** — row-level, bulk, standalone, queued, with confirmation, validation, pivot fields, and custom React components
- **Override system** — replace any React component (view, field, layout, drawer) without forking the package
- **Global search** — configurable across all registered resources
- **Authentication & Profile** — built-in login, 2FA (TOTP), avatar upload, user menu
- **i18n** — English, Portuguese (PT), Portuguese (BR) out of the box
- **Dark and light theme** — full CSS variable theming, zero hardcoded colours
- **Panels and tabs** — group fields visually with collapsible panels and tab navigation

## Documentation

Full documentation lives in the [`docs/`](docs/) directory.

### Getting Started

| Document | Description |
|----------|-------------|
| [Installation Guide](docs/installation-guide.md) | Step-by-step: Composer, assets, config, first resource |
| [Quick Start](docs/setup/quickstart.md) | Dev workflow, hot reload, first CRUD |
| [Troubleshooting](docs/setup/troubleshooting.md) | Common issues and solutions |

### Core Concepts

| Document | Description |
|----------|-------------|
| [Resources](docs/resources.md) | Resource classes, lifecycle hooks, authorization, search, pagination, soft deletes, exceptions |
| [Fields Reference](docs/fields.md) | All field types — configuration, visibility flags, validation, enums |
| [Relationships](docs/relationships.md) | BelongsTo, HasOne, HasMany, BelongsToMany (pivot fields), MorphTo, MorphOne, MorphMany, MorphToMany |
| [Actions](docs/actions.md) | Inline, bulk, standalone, queued, custom components, authorization, audit log |
| [Override System](docs/overrides.md) | 4-tier component resolution: replace any view, field, layout, or drawer |
| [Built-in Components](docs/components.md) | UI components, hooks (useEventBus, useError), tooltip standard, theming |
| [Authentication](docs/authentication.md) | Login, 2FA, user profile, avatar, user menu |
| [Configuration](docs/configuration.md) | Complete `config/martis.php` reference |
| [Loader](docs/loader.md) | Page loader configuration and customization |

### Architecture & API

| Document | Description |
|----------|-------------|
| [Technology Stack](docs/architecture/stack.md) | PHP, Laravel, React, PrimeReact, Tailwind, Vite, testing tools |
| [Architectural Decisions](docs/architecture/decisions.md) | ADRs: why Inertia, why PrimeReact, why contracts |
| [REST API Overview](docs/api/overview.md) | All endpoints, request/response formats, authentication, error handling |

### Project Status

| Document | Description |
|----------|-------------|
| [Nova v5 Parity Map](docs/PARITY_MAP.md) | Feature-by-feature tracker vs Laravel Nova v5 |
| [Documentation Index](docs/README.md) | Full docs hub with quick links |

## Tech Stack

| Layer     | Technology |
|-----------|-----------|
| Backend   | PHP 8.2+, Laravel 11/12 |
| Frontend  | React 18, TypeScript, PrimeReact, Tailwind CSS |
| Icons     | Phosphor Icons |
| Build     | Vite, pnpm |
| Testing   | Pest (PHP), Vitest (JS), PHPStan Level 8 |

## License

MIT — see [LICENSE](LICENSE).
