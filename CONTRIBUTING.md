# Contributing to Martis

## Tagging a release

Releases follow semver and ship as a "trio atómico":

1. **Package tag** (`vN.N.N`) — annotated git tag on `main`.
2. **GitHub release** with notes mirrored from `CHANGELOG.md`.
3. **martis-docs landing pill** bumped to match (and any per-page mirrors of changed `docs/*.md`).

Optionally:

4. **Consumer apps** (`edge-flow`, others) bumped via `composer update martis/martis`.

### Pre-tag check

Before pushing a tag, run the pre-flight checker. It aborts when:

- The `martis-docs/src/data/landing.ts` `VERSION` pill does not match the tag.
- `CHANGELOG.md` does not have a `[vN.N.N]` section for the tag.
- `sync-docs.sh` reports drift between `martis-package/docs/*.md` and `martis-docs/src/content/**/*.mdx`.

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
# 1. Make sure CI is green on main, CHANGELOG has the section, and
#    martis-docs PR is merged with the new pill.
git checkout main && git pull --ff-only

# 2. Pre-flight (aborts on drift).
bash martis-package/.tooling/pre-tag.sh v1.10.0

# 3. Tag + push + GitHub release.
git tag -a v1.10.0 -m "v1.10.0 — <one-liner>"
git push origin v1.10.0
gh release create v1.10.0 --title "v1.10.0 — <one-liner>" --notes-file <(awk '/^## \[1.10.0\]/,/^## \[/{print}' CHANGELOG.md | sed '$d')
```

## Smoke test against a fresh laravel app

The `.github/workflows/smoke-fresh-laravel.yml` workflow exercises the full consumer experience: composer-creates a laravel project, requires martis via path repo, runs `martis:install`, runs every TSX-producing generator (`martis:tool`, `martis:field`, `martis:card`, `martis:component` × 9 types), runs `npm install` + `npm run build:extensions`, and asserts the bundle contains the expected register calls. CI runs it on PRs that touch the install / generator / shim path.

Run locally before opening a generator-touching PR:

```bash
# From the workspace root.
rm -rf /tmp/audit-fresh && mkdir /tmp/audit-fresh && cd /tmp/audit-fresh
composer create-project laravel/laravel test-app --no-interaction
cd test-app
composer config repositories.martis path /Users/lmoura/projects/martis/martis-package
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
