# Contributing to Martis

## Tagging a release

Releases follow semver and ship as a "trio atómico":

1. **Package tag** (`vN.N.N`) on the merge commit of the release PR on `main`.
2. **GitHub release** with notes mirrored from `CHANGELOG.md`.
3. **martis-docs landing pill** bumped to match (and any per-page mirrors of changed `docs/*.md`).

Optionally:

4. **Consumer apps** bumped via `composer update martis/martis`.

The release PR carries everything the tag needs: the `## [N.N.N]` section of `CHANGELOG.md`, the docs, and the rebuilt `public/` (the compiled SPA consumers publish with `martis:publish-assets`; a `resources/js` change that is not rebuilt and committed never reaches them). CI rebuilds `public/` and `stubs/extensions/` on every PR and fails when the committed output differs from the sources, so run `npm run build` and commit the result before pushing a frontend change. Build from a `node_modules` installed in the checkout you build (`npm ci`): in a git worktree whose `node_modules` is a symlink to another checkout, Vite resolves the real path and writes `../../../node_modules/...` keys into `public/manifest.json`, which the CI build then reports as a difference.

Merge the release PR through the GitHub web UI: GitHub signs the merge commit, so the tag created on it shows as Verified. Do not merge release work locally, and do not push a tag from a local `git tag`: a local tag creates no GitHub release and sits on an unsigned commit.

### Pre-tag check

Before pushing a tag, run the pre-flight checker. It aborts when:

- The `martis-docs/src/data/landing.ts` `VERSION` pill does not match the tag.
- `CHANGELOG.md` does not have a `[vN.N.N]` section for the tag.
- `sync-docs.sh` reports drift between `martis-package/docs/*.md` and `martis-docs/src/content/**/*.mdx`.
- The `martis-docs/src/data/landing.ts` `TESTS_PASSING` total differs from the README "Test coverage" total (`= **N passing**`), or a landing component hardcodes its own "N tests passing".

The script mirrors the workspace copy (`pre-tag-check.sh` at the workspace root); keep the two in step. Both rely on the workspace layout: `martis-docs` and `sync-docs.sh` next to `martis-package`.

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
# 1. After the release PR is merged on GitHub: CI is green on main,
#    CHANGELOG has the section, and the martis-docs pill is bumped.
git checkout main && git pull --ff-only

# 2. Pre-flight (aborts on drift).
bash martis-package/.tooling/pre-tag.sh v1.10.0

# 3. GitHub release, which creates the tag on the signed merge commit.
gh release create v1.10.0 --target "$(git rev-parse HEAD)" --title "v1.10.0" \
  --notes-file <(awk '/^## \[1.10.0\]/{found=1; print; next} found && /^## \[/{exit} found{print}' CHANGELOG.md)
```

`.github/workflows/release.yml` does the same from the Actions tab (Release, Run workflow, `version` without the `v`).

## Smoke test against a fresh laravel app

The `.github/workflows/smoke-fresh-laravel.yml` workflow exercises the full consumer experience: composer-creates a laravel project, requires martis via path repo, runs `martis:install`, runs every TSX-producing generator (`martis:tool`, `martis:field`, `martis:card`, `martis:component` × 9 page/shell types plus a `field` and a `generic` override), runs `npm install` + `npm run build:extensions`, type-checks the generated sources with `npx tsc -p tsconfig.extensions.json`, and asserts the bundle contains every generated path and override key. CI runs it on pushes to `main` / `release/**` and on PRs that touch the install / generator / shim path.

Run locally before opening a generator-touching PR (a subset of the CI job; copy the assertions from the workflow for full parity):

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
php artisan martis:component StatusBadge --type=field
php artisan martis:component InfoPanel --type=generic
npm run build:extensions
npx tsc -p tsconfig.extensions.json
grep -c 'register(' public/vendor/martis-user/extensions.js  # should be ≥ 12
```

## CI and tooling hygiene

- **Pinned actions.** Every `uses:` in `.github/workflows/` is a full commit SHA with its version in a trailing comment (`uses: actions/checkout@<sha> # v4.4.0`), so a moved tag cannot change what CI runs. Dependabot (`.github/dependabot.yml`, weekly: github-actions, npm and composer) bumps the SHA and the comment together. To pin a new action by hand, resolve its tag to the commit with `gh api repos/<owner>/<repo>/git/ref/tags/<tag>`; when `object.type` is `tag`, resolve that object once more through `git/tags/<sha>`.
- **Read-only token.** `ci.yml` and `smoke-fresh-laravel.yml` declare `permissions: contents: read`. Only `release.yml` holds `contents: write`, and it takes its dispatch inputs through `env:` after validating the version (`^[0-9]+\.[0-9]+\.[0-9]+$`) and the sha (`^[0-9a-f]{7,40}$`).
- **Dependencies.** `npm audit` reports 0 vulnerabilities on `package-lock.json`; the toolchain is dev-only, as the SPA ships prebuilt in `public/`. Vitest is on 4.x with the jsdom environment, which runs on any Node 20. The Vite dev server (`npm run dev`) answers localhost origins only (Vite's default CORS), so a Playground served from another host names it in `server.cors.origin` of `vite.config.ts`.
- **Pest in Docker.** `scripts/test.sh` runs the suite in the CI-parity image as your own uid:gid, never as root. The test harness copies the testbench skeleton into a `0700` per-user directory under the temp directory and refuses one it does not own, so a shared temp directory cannot plant files in it.
