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

Martis is a full-featured admin panel engine for Laravel. It provides automatic CRUD, 32 built-in field types, relationship management, actions, search, authentication, and a React SPA frontend — all driven from a single PHP resource class.

## Quick Start

```bash
composer require martis/martis
php artisan martis:install
```

The install command publishes assets, configuration, and scaffolds the panel. Visit `/admin` to log in.

## Requirements

| Dependency | Minimum |
|------------|---------|
| PHP | 8.2+ |
| Laravel | 11+ or 12+ |
| Node.js | 20+ |
| pnpm | 8+ |

## Documentation

| Topic | Description |
|-------|-------------|
| [Installation Guide](docs/installation-guide.md) | Full installation, configuration, and first resource |
| [Resources](docs/resources.md) | Creating resources, CRUD lifecycle, authorization, hooks |
| [Fields](docs/fields.md) | All 32+ field types, common methods, visibility flags |
| [Relationships](docs/relationships.md) | BelongsTo, HasMany, BelongsToMany, polymorphic fields |
| [Actions](docs/actions.md) | Row-level and bulk actions, confirmation, validation |
| [Configuration](docs/configuration.md) | `config/martis.php` — branding, auth, profile, storage |
| [Authentication](docs/authentication.md) | Guards, middleware, 2FA, profile |
| [Overrides](docs/overrides.md) | Custom React components for create/update/detail/index |
| [Components](docs/components.md) | Frontend component library reference |
| [Loader](docs/loader.md) | Page loader configuration |
| [API Reference](docs/api/overview.md) | Backend REST API endpoints |
| [Architecture](docs/architecture/stack.md) | Technical stack and design decisions |

## Features at a Glance

- **32 built-in field types** — text, select, date, file, image, BelongsTo, HasMany, morph fields, code, markdown, rich text, and more
- **Context-aware fields** — different field sets for index, detail, create, and update
- **Relationship management** — inline CRUD panels for HasOne, HasMany, BelongsToMany, morph relationships with pivot field support
- **Actions** — row-level and bulk actions with confirmation dialogs, validation, and redirect
- **Global search** — configurable, across all registered resources
- **Panels and tabs** — group fields visually with collapsible panels and tab navigation
- **i18n** — full English, Portuguese (PT), and Portuguese (BR) translations out of the box
- **Profile and 2FA** — built-in user profile page with avatar, password change, and TOTP two-factor authentication
- **Dark and light theme** — full CSS variable theming, zero hardcoded colours
- **Extensible** — custom field components, resource overrides, relatableQuery hooks, policy-based authorization

## License

MIT — see [LICENSE](LICENSE).
