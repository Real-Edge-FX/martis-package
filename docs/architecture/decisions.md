# Architectural Decision Records (ADRs)

## ADR-001: Monorepo

**Date:** 2026-04-02 | **Status:** Accepted

**Decision:** Monorepo with `packages/martis/` + `playground/`
**Rationale:** Package and playground evolve in parallel. A single `git clone` provides a fully working environment. Changes to the package are immediately reflected in the playground via Composer path repository with symlink.

---

## ADR-002: PNPM Workspaces

**Date:** 2026-04-02 | **Status:** Accepted

**Decision:** PNPM 9 with workspaces
**Rationale:** Native workspace support, strict mode that prevents phantom dependencies, 2-3x faster than npm. Workspaces allow the package and playground to share dependencies efficiently.

---

## ADR-003: Docker for Services, Host for Runtime

**Date:** 2026-04-02 | **Status:** Accepted

**Decision:** MySQL 8.0 and Redis 7 in Docker containers; PHP 8.2 and Node.js 20 on the host
**Rationale:** Isolates stateful services while keeping runtime tools directly accessible for better developer experience and lower overhead. Docker containers provide reproducible database/cache environments.

---

## ADR-004: MySQL 8.0

**Date:** 2026-04-02 | **Status:** Accepted

**Decision:** MySQL 8.0 (not PostgreSQL)
**Rationale:** Laravel Nova uses MySQL as its reference database. Martis must test against the same database engine that users will most commonly use. MySQL 8.0 also provides JSON column support, CTEs, and window functions.

---

## ADR-005: Caddy Web Server

**Date:** 2026-04-02 | **Status:** Accepted

**Decision:** Caddy as web server on port 80
**Rationale:** Zero-config simplicity — the entire Caddyfile is a few lines. SSL is handled by the external proxy (`martis.realedgefx.com -> http://192.168.50.21:80`). Caddy handles PHP-FPM proxying with minimal configuration.

---

## ADR-006: Composer Path Repository

**Date:** 2026-04-02 | **Status:** Accepted

**Decision:** Playground uses `{"type": "path", "url": "../packages/martis"}` with symlink
**Rationale:** Changes to the Martis package reflect immediately in the playground without running `composer update`. The symlink option ensures files are directly referenced, not copied.

---

## ADR-007: CI via Makefile

**Date:** 2026-04-02 | **Status:** Accepted

**Decision:** All CI through `make ci` (lint + typecheck + test); git pre-push hook blocks push without green CI
**Rationale:** Simple, reproducible, and works regardless of the CI platform. The pre-push hook ensures nothing is pushed without passing CI locally.

---

## ADR-008: Scramble for API Documentation

**Date:** 2026-04-02 | **Status:** Accepted

**Decision:** `dedoc/scramble` for automatic API documentation
**Rationale:** Zero annotations — Scramble generates OpenAPI specs from route definitions and type hints at runtime. Documentation is always in sync with the code. Available at `/docs/api`.

---

## ADR-009: Phosphor Icons

**Date:** 2026-04-04 | **Status:** Accepted

**Decision:** Phosphor Icons library (1,512 icons) for resource icons
**Rationale:** Consistent visual style, open-source license (MIT), React-native components. Resources declare icons via `icon()` method using kebab-case or PascalCase names. Phosphor provides multiple weights (regular, bold, fill, duotone, thin, light).

---

## ADR-010: react-i18next for Internationalization

**Date:** 2026-04-04 | **Status:** Accepted

**Decision:** react-i18next on the frontend; standard Laravel PHP language files on the backend
**Rationale:** Laravel convention for backend translations (publishable lang files). React standard for frontend (react-i18next). Translations are served via `GET /martis/api/translations/{locale}` (public, no auth) and loaded dynamically at app startup.

**Supported locales:** EN, PT-BR, PT-PT

---

## ADR-011: GitHub Actions Self-Hosted Runner

**Date:** 2026-04-04 | **Status:** Accepted

**Decision:** Self-hosted runner on server `192.168.50.21` connecting to GitHub via HTTPS outbound
**Rationale:** Server has no public internet access for external runners. The self-hosted runner connects outbound to GitHub, receives CI/CD triggers, and runs `make ci` locally. Automated deploy on push to `develop`.

---

## ADR-012: Post-Merge Deploy Hook

**Date:** 2026-04-04 | **Status:** Accepted

**Decision:** Git `post-merge` hook automatically runs `make build` when frontend files change
**Rationale:** Eliminates the #1 cause of "changes not visible" issues — stale frontend assets being served. The hook detects `.tsx`, `.ts`, `.css` changes and runs the build. Fallback: `make deploy` for forced manual deploy.

---

## ADR-013: 4-Tier Component Override System

**Date:** 2026-04-04 | **Status:** Accepted

**Decision:** Component resolution follows a 4-tier priority chain: explicit key > per-resource > per-type > built-in default
**Rationale:** Provides maximum flexibility without complexity. Users can override at the most appropriate level — a single field instance, all fields of a type within a resource, all fields of a type globally, or accept the default. This is Martis's key architectural advantage over Nova.

---

## ADR-014: Contract-First Architecture

**Date:** 2026-04-03 | **Status:** Accepted

**Decision:** Every public method in Resource.php and Field.php must exist in the corresponding contract (ResourceContract, FieldContract). Backend never renders UI — all frontend communication is through JSON contracts.
**Rationale:** Enforces extensibility at every layer. Third-party developers can swap implementations as long as they satisfy the contract. PHPStan level 8 validates conformance.

---

## ADR-015: React + TypeScript (Not Blade/Vue)

**Date:** 2026-04-02 | **Status:** Accepted

**Decision:** Frontend built with React 18 + TypeScript, not Blade or Vue
**Rationale:** React provides the strongest ecosystem for component-based UIs with TypeScript. TanStack Query handles server state management. React Router provides client-side routing with code splitting. This enables the override system to work at the component level.
