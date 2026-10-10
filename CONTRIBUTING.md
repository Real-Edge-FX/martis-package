# Contributing to Martis

## Tagging a release

Releases follow semver and ship as a "trio atómico":

1. **Package tag** (`vN.N.N`) on the merge commit of the release PR on `main`.
2. **GitHub release** with notes mirrored from `CHANGELOG.md`.
3. **martis-docs deploy** (`bash scripts/deploy.sh` in a clean checkout of martis-docs `origin/main`), run after the tag: it syncs the package `docs/` of that tag into the site and writes the release version and test count (`src/data/site.ts`, `RELEASE`) from the GitHub release and the README "Test coverage" total. getmartis.com must not lag a release: deploy right after tagging, and ship any site change outside the synced docs (`src/` pages, data, navigation) by PR into martis-docs `main` before the tag.

4. **martis-playground** on the release: the release is validated on a standalone Playground instance before the release PR, the consumer changes (a published config block, `composer.json` / `composer.lock`) go by PR into the Playground's `main`, and the shared Playground checkout is brought up to the release (`composer update martis/martis`, `martis:publish-assets`, `optimize:clear`, check that it boots) without touching uncommitted work.

On the 1.x line the release PR targets `release/1.x` instead of `main`, the tag goes on its merge commit, and the GitHub release is created with `--latest=false` so the 2.x release stays the latest. The martis-docs site follows 2.x: a 1.x release does not run the deploy.

Optionally:

5. **Other consumer apps** bumped via `composer update martis/martis`.

### Release ownership

The maintainer merges the release PR on GitHub web (below). Everything else is done by whoever cuts the release, the coding agent included, end to end and without handing pull requests back: the pre-tag check, the GitHub release, the martis-docs PRs, their merge and the deploy (afterwards `node scripts/release-stats.mjs --check` in martis-docs must pass), the Playground PR and its merge, the shared Playground update, and the workspace notes (`knowledge/`, `MARTIS_CONTROL_CENTER.md`).

The release PR carries everything the tag needs: the `## [N.N.N]` section of `CHANGELOG.md`, the docs, and the rebuilt `public/` (the compiled SPA consumers publish with `martis:publish-assets`; a `resources/js` change that is not rebuilt and committed never reaches them). CI rebuilds `public/` and `stubs/extensions/` on every PR and fails when the committed output differs from the sources, so run `npm run build` and commit the result before pushing a frontend change. Build from a `node_modules` installed in the checkout you build (`npm ci`): in a git worktree whose `node_modules` is a symlink to another checkout, Vite resolves the real path and writes `../../../node_modules/...` keys into `public/manifest.json`, which the CI build then reports as a difference.

Merge the release PR through the GitHub web UI: GitHub signs the merge commit, so the tag created on it shows as Verified. Do not merge release work locally, and do not push a tag from a local `git tag`: a local tag creates no GitHub release and sits on an unsigned commit.

### Pre-tag check

Before pushing a tag, run the pre-flight checker. It aborts when:

- The `martis-docs` checkout is not a clean checkout of `origin/main` (the deploy publishes whatever tree the checkout holds).
- `CHANGELOG.md` does not have a `[N.N.N]` section for the tag.
- `martis-docs/scripts/sync-docs.mjs` fails against this checkout's `docs/` (for example a page missing from its `MAP`).
- The README does not carry exactly one "Test coverage" line with a readable `= **N passing**` total, or a martis-docs component or page hardcodes its own "N tests passing".
- `package.json` (and so the bundle's `window.Martis.version`, which Vite compiles from it) does not carry the tag's version. Bump `package.json` and `package-lock.json` and rebuild the assets in the release PR (v2.8.0 shipped reporting `2.7.0`).

The release version and test count on the site are written by the martis-docs deploy from the release, after the tag exists, so the checker reports the committed values without requiring them to match. The script mirrors the workspace copy (`pre-tag-check.sh` at the workspace root); keep the two in step. Both rely on the workspace layout: `martis-docs` next to `martis-package`.

```bash
# Run from anywhere — the script resolves the workspace root from its
# own location (martis-package/.tooling/pre-tag.sh).
bash martis-package/.tooling/pre-tag.sh v1.10.0
```

The check assumes the standard layout where `martis-package` and `martis-docs` are siblings under a single parent directory (e.g. `~/projects/martis/{martis-package,martis-docs}`). When they are not, set `PRE_TAG_ROOT` to the parent.

```bash
PRE_TAG_ROOT=/path/to/workspace bash martis-package/.tooling/pre-tag.sh v1.10.0
```

The full release sequence is:

```bash
# 1. After the release PR is merged on GitHub: CI is green on main and
#    CHANGELOG has the section.
git checkout main && git pull --ff-only

# 2. Pre-flight (aborts on drift).
bash martis-package/.tooling/pre-tag.sh v1.10.0

# 3. GitHub release, which creates the tag on the signed merge commit.
gh release create v1.10.0 --target "$(git rev-parse HEAD)" --title "v1.10.0" \
  --notes-file <(awk '/^## \[1.10.0\]/{found=1; print; next} found && /^## \[/{exit} found{print}' CHANGELOG.md)

# 4. Publish the site from a clean martis-docs origin/main.
git -C martis-docs checkout main && git -C martis-docs pull --ff-only
(cd martis-docs && bash scripts/deploy.sh)
```

`.github/workflows/release.yml` does the same from the Actions tab (Release, Run workflow, `version` without the `v`).

## Smoke test against a fresh laravel app

The `.github/workflows/smoke-fresh-laravel.yml` workflow exercises the full consumer experience: composer-creates a laravel project, requires martis via path repo, runs `martis:install`, runs every TSX-producing generator (`martis:tool`, `martis:field`, `martis:card`, `martis:component` × 9 types), runs `npm install` + `npm run build:extensions`, and asserts the bundle contains the expected register calls. CI runs it on PRs that touch the install / generator / shim path.

Run locally before opening a generator-touching PR:

```bash
# From the workspace root.
rm -rf /tmp/audit-fresh && mkdir /tmp/audit-fresh && cd /tmp/audit-fresh
composer create-project laravel/laravel test-app --no-interaction
cd test-app
composer config repositories.martis path /path/to/martis-package
composer require martis/martis:@dev
touch database/database.sqlite
php artisan martis:install --force --no-interaction
npm install
php artisan martis:tool Charts --with-component
php artisan martis:field Rating
php artisan martis:card RevenueGauge
for type in shell sidebar topbar footer login-page register-page \
            forgot-password-page reset-password-page email-verify-notice-page; do
  php artisan martis:component --type=$type
done
npm run build:extensions
grep -c 'register(' public/vendor/martis-user/extensions.js  # should be ≥ 12
```
