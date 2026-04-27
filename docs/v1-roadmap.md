# Roadmap to v1.0.0

> Live document. Updated with every PR that lands on `release/v1.0.0`.
> Current snapshot: branch `release/v1.0.0` cut from `main` at v0.10.0-rc1.

## Where we are

**Feature-complete.** v0.10.0-rc1 closed the entire post-1.0 backlog from the parity audit (see [PARITY_MAP.md](PARITY_MAP.md)): Custom Tools, Impersonation, Reactive forms, Save variants, Sticky views, Cache surface, SSO, Notifications, Locale extensibility, all 12 relation fields, 50 field types, full action / lens / metric / dashboard / menu systems.

| Metric | Status |
|---|---|
| Pest tests | 1613 passing, 1 skipped, 0 failed |
| Vitest tests | 84 passing, 5 skipped, 0 failed |
| PHPStan level 8 | 0 errors (220 baselined; tracked toward zero post-1.0) |
| Parity score | 167/169 (98.8%) |
| Documentation | 34 docs files; broken links / orphan docs = 0; comparative references contained to the parity document |
| ParitySurface tripwire | Locks v0.8 / v0.9 / v0.10 public API |
| CHANGELOG.md | Keep-a-Changelog format; current to v0.10.0-rc1 + `[Unreleased]` |
| GitHub Actions CI | PHP 8.2/8.3 × Laravel 11/12 + PHPStan + Pint + Vitest |
| License | MIT (composer.json + LICENSE) |
| Greenfield install | Verified on Laravel 12 + PHP 8.4 |

**There is no functional code missing.** What remains is operational confidence: time, sanity checks, and a handful of polish items.

---

## Hard blockers — must land before tag

The list is short.

### 1. Greenfield install verification ✅

- [x] `composer create-project laravel/laravel:^12.0 test-app && composer require martis/martis && php artisan martis:install` works end-to-end on a fresh Laravel 12 + PHP 8.4 app.
- [x] `martis:install` publishes 5 migrations, registers the host service provider, updates `.env`, runs `migrate`.
- [x] `martis:user` creates an admin account.
- [x] `route:list --path=martis` shows 40+ routes registered, no duplicates after the API name-collision fix (see Findings below).
- [ ] **Pending:** Laravel 13 compatibility audit. Fresh `composer create-project laravel/laravel` ships Laravel 13 today; Martis caps at `^11.0|^12.0`. Either expand the constraint to `^11.0|^12.0|^13.0` after a Laravel 13 sweep, OR document the limitation prominently in the install guide.

**Findings during verification (now landed):**

- API name-collision: routes registered under `Route::name('api.')->group(fn () => Route::...->name('api.X'))` produced double-prefixed names (`martis.api.api.X`). Fixed by dropping the inner `api.` from the seven affected route names (`navigation`, `tools.index`, `tools.show`, `impersonation.status/start/stop`, `command-palette`). Safe to drop pre-stable since these are RC-only public surface.
- `composer.json` license was `proprietary` despite the LICENSE file being MIT. Fixed.

### 2. CHANGELOG.md ✅

- [x] CHANGELOG.md follows [Keep a Changelog](https://keepachangelog.com/) format.
- [x] Backfilled with v0.8.0-beta, v0.9.0-beta, v0.10.0-rc1 entries.
- [x] `[Unreleased]` section tracks the v1.0 work in progress.

### 3. Visual regression baseline ✅ (spec landed; awaits first capture)

- [x] `tests/e2e/visual-baseline.spec.ts` (in martis-playground) covers the 7 canonical pages: dashboard, resource index, resource create, profile, system cache, custom tool, login.
- [x] Each test asserts via `toHaveScreenshot()` with a 2% pixel-diff tolerance (accommodates anti-aliasing without letting real regressions slip through).
- [ ] **First capture pending** — run once with `--update-snapshots` to write the baseline PNGs:
      ```
      cd martis-playground && npx playwright test visual-baseline --update-snapshots
      git add tests/e2e/visual-baseline.spec.ts-snapshots/
      ```
      After that, future PRs that touch UI code surface the diff via CI.
- [ ] Wire the spec into the playground's existing CI step (currently has E2E suite running on the self-hosted runner).

---

## Soft blockers — should land before tag

### 4. README screenshot / GIF showcase
- [ ] Add 3-4 screenshots or a single GIF to the root `README.md`: dashboard with metrics, sticky-views in action, the impersonation banner, a custom tool. Discoverability on the GitHub repo home suffers without visuals.

**Owner:** anyone. Estimated effort: 30 minutes.

### 5. First external consumer
- [ ] One real app (not the playground) running `martis/martis:0.10.0-rc1` in staging or production for ≥ 1 week without bug reports.
- [ ] Record any friction points encountered during install / port / customisation.

**Owner:** project owner. This is the only true "needs calendar time" item — everything else is mechanical.

### 6. CI matrix expansion ✅

- [x] `.github/workflows/ci.yml` runs `pest` against the matrix: PHP 8.2 × Laravel 11.*, PHP 8.2 × Laravel 12.*, PHP 8.3 × Laravel 11.*, PHP 8.3 × Laravel 12.* (4 combinations).
- [x] Separate jobs run `phpstan analyse` and `pint --test` on PHP 8.3.
- [x] `vitest` job runs the JS test suite on Node 20.
- [x] Triggers on push to `main` / `release/**` and on PRs to those branches.
- [ ] **Pending:** first push to `main` / `release/v1.0.0` will trigger CI and validate the matrix actually passes everywhere. If a combo fails, fix before tagging v1.0.0.

### 7. Static analysis baseline ✅

- [x] `vendor/bin/phpstan analyse --memory-limit=2G` at level 8 reports **0 errors** with the baseline applied.
- [x] `phpstan-baseline.neon` locks 220 pre-v1 errors across `src/` so CI fails on **new** errors without blocking the v1.0 cut.
- [x] `phpstan.neon` includes the baseline + sets `reportUnmatchedIgnoredErrors: false` so inline `@phpstan-ignore-*` comments stay tolerant.
- [x] CI workflow runs `phpstan analyse` on every push.
- [ ] **Post-1.0 task:** track the baseline count down to zero by re-running `--generate-baseline=phpstan-baseline.neon` periodically and committing the smaller file.

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
