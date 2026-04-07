# Technology Stack

## Server Infrastructure

| Component | Value |
|-----------|-------|
| Server IP | 192.168.50.21 (local network) |
| OS | Ubuntu 24.04.4 LTS |
| User | martis |
| SSH | Port 22 |
| Web Server | Caddy 2.x (port 80) |

## Backend

| Component | Version | Notes |
|-----------|---------|-------|
| PHP | 8.2 | Installed on host |
| Laravel | 12.x | Base framework |
| Composer | 2.x | PHP dependency management |
| MySQL | 8.0 | Docker container (`127.0.0.1:3306`) |
| Redis | 7 | Docker container (`127.0.0.1:6379`), password auth |
| PHP-FPM | 8.2 | Unix socket, configured for `martis` user |
| Caddy | 2.x | Reverse proxy to PHP-FPM |

## Frontend

| Component | Version | Notes |
|-----------|---------|-------|
| Node.js | 20 | Installed on host |
| PNPM | 9 | Workspace-aware package manager |
| React | 18 | UI framework |
| TypeScript | 5.x | Strict mode enabled |
| Vite | 6 | Build tool with HMR support |
| React Router | 6 | Client-side routing with code splitting |
| TanStack Query | — | Server state management and caching |
| PrimeReact | — | UI component library (DataTable, Dropdown, etc.) |
| react-i18next | — | Internationalization |
| Phosphor Icons | — | Icon library (1,512 icons) |
| Tailwind CSS | — | Utility-first CSS with dark mode support |

## Development Tools

| Tool | Purpose |
|------|---------|
| Pest PHP | PHP unit/feature testing |
| Vitest | TypeScript unit testing |
| Playwright | End-to-end browser testing |
| PHPStan | Static analysis (level 8) |
| Laravel Pint | PHP code formatter |
| ESLint | TypeScript linter |
| Telescope | Laravel debug dashboard (dev only) |
| Scramble | Automatic API documentation (Swagger at `/docs/api`) |

## CI/CD

| Component | Details |
|-----------|---------|
| CI Runner | GitHub Actions self-hosted runner on `192.168.50.21` |
| CI Pipeline | `make ci` (lint + typecheck + test) |
| Deploy | Automatic via post-merge hook or GitHub Actions deploy workflow |
| Pre-push Hook | Blocks push if `make ci` fails |
| GitHub App | Token-based auth (App ID: 3164933, auto-refreshed) |

## Key Makefile Commands

| Command | Description |
|---------|-------------|
| `make ci` | Full CI: lint + typecheck + test |
| `make build` | Compile frontend assets (Vite production build) |
| `make push` | Refresh GitHub token + push to origin |
| `make deploy` | Manual deploy: pull + build + cache clear |
| `make test` | Run all tests (Pest + Vitest) |
| `make lint` | Run linters (Pint + ESLint) |
| `make format` | Auto-fix formatting |
| `make typecheck` | PHPStan + TypeScript check |
| `make fresh` | Reset database with seeds |
| `make start` / `make stop` | Start/stop Docker services |
| `make status` | Check service health |
| `make assets-watch` | Vite HMR for development |
| `make sync-package` | Sync to package repository |
| `make refresh-token` | Refresh GitHub App token |
