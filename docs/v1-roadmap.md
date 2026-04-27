# Roadmap to v1.0.0

> Live document. Updated with every PR that lands on `release/v1.0.0`.
> Current snapshot: branch `release/v1.0.0` cut from `main` at v0.10.0-rc1.

## Where we are

**Feature-complete.** v0.10.0-rc1 closed the entire post-1.0 backlog from the parity audit (see [PARITY_MAP.md](PARITY_MAP.md)): Custom Tools, Impersonation, Reactive forms, Save variants, Sticky views, Cache surface, SSO, Notifications, Locale extensibility, all 12 relation fields, 50 field types, full action / lens / metric / dashboard / menu systems.

| Metric | Status |
|---|---|
| Pest tests | 1613 passing, 1 skipped, 0 failed |
| Vitest tests | 84 passing, 5 skipped, 0 failed |
| Parity score | 167/169 (98.8%) |
| Documentation | 34 docs files; broken links / orphan docs = 0; comparative references contained to the parity document |
| ParitySurface tripwire | Locks v0.8 / v0.9 / v0.10 public API |

**There is no functional code missing.** What remains is operational confidence: time, sanity checks, and a handful of polish items.

---

## Hard blockers — must land before tag

The list is short.

### 1. Greenfield install verification
- [ ] `composer create-project laravel/laravel test-app && composer require martis/martis:0.10.0-rc1 && php artisan martis:install` works end-to-end on a fresh Laravel app (not just the playground).
- [ ] `php artisan martis:install --with-profile` migrates avatar + 2FA columns idempotently.
- [ ] Login flow works without any seed data.
- [ ] Dashboard renders without registered metrics (default fallback).

**Owner:** anyone. Estimated effort: 30 minutes.

### 2. CHANGELOG.md
- [ ] Replace the current ad-hoc release-history file with a proper `CHANGELOG.md` at the repo root following the [Keep a Changelog](https://keepachangelog.com/) format. Sections: `## [Unreleased]`, `## [1.0.0] - YYYY-MM-DD`, with subsections `Added / Changed / Fixed / Removed / Security`.
- [ ] Backfill from v0.7.0-beta onward (the lifetime of the current branch model).

**Owner:** release engineering. Estimated effort: 1 hour.

### 3. Visual regression baseline
- [ ] Capture baseline screenshots (or Playwright snapshots) of the canonical pages: login, dashboard, resource index, resource detail, resource create form, profile, system cache page, impersonation banner, tool page.
- [ ] Wire one Playwright assertion per page so future PRs get a CI signal when something visually drifts.

**Owner:** anyone with the playground running. Estimated effort: 2 hours.

---

## Soft blockers — should land before tag

### 4. README screenshot / GIF showcase
- [ ] Add 3-4 screenshots or a single GIF to the root `README.md`: dashboard with metrics, sticky-views in action, the impersonation banner, a custom tool. Discoverability on the GitHub repo home suffers without visuals.

**Owner:** anyone. Estimated effort: 30 minutes.

### 5. First external consumer
- [ ] One real app (not the playground) running `martis/martis:0.10.0-rc1` in staging or production for ≥ 1 week without bug reports.
- [ ] Record any friction points encountered during install / port / customisation.

**Owner:** project owner. This is the only true "needs calendar time" item — everything else is mechanical.

### 6. CI matrix expansion
- [ ] CI runs against PHP 8.2 AND PHP 8.3 (currently only one). Composer's `require` says `^8.2` so both must pass.
- [ ] CI runs against Laravel 11 AND Laravel 12 (the package supports both).
- [ ] All combinations green before tag.

**Owner:** CI engineer. Estimated effort: 2 hours.

### 7. Static analysis baseline
- [ ] `vendor/bin/phpstan analyse` runs at level 8 with zero errors.
- [ ] Document any baseline ignores (the lower the better; ideally zero).
- [ ] Add to `make ci` so future PRs get the signal.

**Owner:** anyone. Estimated effort: 1-3 hours depending on existing baseline.

---

## Nice to have — does NOT block v1.0

These are quality-of-life improvements that can land in v1.0.x patch releases without blocking the cut.

- Custom tool React component lazy-loading (currently bundled inline).
- Re-ingest `RELEASE_HISTORY.md` into the second-brain vault.
- Anúncio público (X, Reddit r/laravel, r/PHP) when v1.0.0 ships.
- E2E parity tests that simulate the most common admin workflows (resource CRUD, action with confirmation, lens navigation) — useful for migration confidence (see [PARITY_MAP.md](PARITY_MAP.md) for the full list).

---

## Out of scope for v1.0

Two scope decisions worth stating explicitly so they don't surface as "missing" later.

### Domain-specific wrappers — won't ship

Martis intentionally does **not** ship pre-built wrappers for things where every team makes different choices:

- Excel / CSV export — disk, library, format options vary too much. Recipe documented in [actions.md](actions.md) using `ActionResponse::redirect($downloadUrl)`.
- Approval workflows — workflow shape (sequential vs parallel, rollback, escalation) is domain-specific. Recipe: `Action` + a state-machine enum on the model.
- Draft / publish — straight `published_at` column + `Slug::freezeAfterPublish()`. No framework needed.
- Scheduled-task / queue / N+1 / mail / backup admin UIs — Laravel ships best-in-class tooling for each (Horizon, Telescope, Pulse, `spatie/laravel-backup`). Martis does not duplicate them; the Tool primitive lets a consumer surface any of these inside the Martis shell when they want it integrated.

The `Action`, `Tool`, and `Lens` primitives plus the Laravel-native ecosystem cover these cases without forcing opinions.

### Internal polish — could land in v1.0.x patches

- Custom Tool React component lazy-loading (currently bundled inline). Cosmetic; the inline path works.

---

## Tagging procedure

When the items above are checked off:

1. Create PR `release/v1.0.0 → main`. Body lists every PR / commit since the last tag (`v0.10.0-rc1`).
2. Final test run: `vendor/bin/pest && npm run test`. Both green.
3. Merge.
4. Tag: `git tag -a v1.0.0 -m "v1.0.0 — first stable release" && git push origin v1.0.0`.
5. GitHub release: NOT pre-release. Title `v1.0.0 — first stable release`. Body summarises the v0.7-beta → v1.0.0 arc (Track B foundation, Experience Confidence track, parity work, post-1.0 backlog closure — see [PARITY_MAP.md](PARITY_MAP.md) for the full feature scorecard).
6. Update root README badge to point to v1.0.0.

After tag:

- Bump `composer.json` minimum-stability to `stable`.
- Open `[1.0.x]` section in `CHANGELOG.md` with the date.
- Re-ingest `PARITY_MAP.md` into the vault.

---

## Tracking

This file is the source of truth for v1.0 readiness. Tick items as PRs land. Once all hard blockers are checked, the tag can be cut. Soft blockers should be checked but a calendar-driven exception is acceptable if the project owner waives one.

Updated: 2026-04-27. Branch: `release/v1.0.0`.
