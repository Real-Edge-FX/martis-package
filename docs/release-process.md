# Release Process — Martis Tag Creation Playbook

## Pre-conditions

Before creating a new TAG, verify all of the following:

- Server must be on `develop` branch
- `make ci` must pass (PHPStan level 8, ESLint, TypeCheck, Pest PHP, Vitest)
- Working tree must be clean, or pending assets must be committed
- Both repos (monorepo + package) must be in sync on `develop`
- No in-progress feature branches pending merge that should be included

## Pre-Release Checklist

### A. Code State
- No partial feature branches pending merge
- No TODOs marked critical that would block release
- No temporary/debug code left in
- Recent commits are coherent with a public release

### B. Branch Integration
- Confirm `master` has zero commits not in `develop`
- Fast-forward merge is possible (no divergence)
- No conflicts between `develop` and `master`

### C. Quality Gates
- `make ci` passes: 893+ PHP tests, 132+ TS tests, PHPStan level 8
- Build artifacts committed (`make build` if needed)
- No known critical regressions in the playground

### D. Versioning (MAJOR.MINOR.PATCH-alpha)
- New field types, features, or systems → bump MINOR
- Bug fixes only → bump PATCH
- Breaking API changes → bump MAJOR

### E. Documentation
- README reflects all new fields and features
- `docs/` updated for new capabilities
- `PARITY_MAP.md` updated if Nova parity status changed

## Execution Steps

### Step 1 — Commit pending assets (if any)

```
git add playground/public/vendor/martis/
git commit -m "chore(assets): rebuild playground assets for release vX.Y.Z-alpha"
```

### Step 2 — Push develop

```
make push
```

Runs CI + refreshes GitHub token before push.

### Step 3 — Verify branch divergence

```
git log master..develop | wc -l   # commits only in develop
git log develop..master | wc -l   # must be 0 for clean fast-forward
```

### Step 4 — Merge develop into master

```
git checkout master
git merge --ff-only develop
git log --oneline -3 master
```

### Step 5 — Create annotated TAG

```
git tag -a vX.Y.Z-alpha -m "vX.Y.Z-alpha — short summary of release"
git tag --sort=-version:refname | head -3
```

### Step 6 — Push master and TAG

```
GIT_ASKPASS=/home/martis/.git-askpass.sh git push origin master
GIT_ASKPASS=/home/martis/.git-askpass.sh git push origin vX.Y.Z-alpha
```

### Step 7 — Sync package repo

```
git checkout develop
make sync-package
```

### Step 8 — Create GitHub Release

Use the GitHub API to create a prerelease on `Real-Edge-FX/martis`:
- Tag: `vX.Y.Z-alpha`
- Title: `vX.Y.Z-alpha`
- Pre-release: `true`
- Body: full release notes in English

### Step 9 — Post-release validation

```
git ls-remote origin refs/tags/vX.Y.Z-alpha     # confirm tag on remote
git log --oneline -3 origin/master               # confirm master updated
```

Confirm the GitHub Release is visible at: `github.com/Real-Edge-FX/martis/releases`

## Release Notes Template (English)

```
## vX.Y.Z-alpha — headline

**Release date:** YYYY-MM-DD
**Branch:** master (fast-forward from develop)
**Previous release:** vA.B.C-alpha (YYYY-MM-DD)
**Pre-release status:** Alpha — APIs may change

---

## New Features

- **Feature name** — description

## Improvements

- **Improvement** — description

## Fixes

- Component: description (REA-XXXX)

## Requirements

| Dependency | Version |
|---|---|
| PHP | 8.2+ |
| Laravel | 11+ or 12+ |
| Node.js | 20+ |

## Notes

- Alpha release — public APIs may change between minor versions.
- Run `php artisan martis:vendor-publish --assets` after upgrading.
```

## Alpha Exit Criteria

The project may exit alpha (→ beta) when:

- All Nova v5 parity features are implemented (see `PARITY_MAP.md`)
- Zero known critical bugs in core CRUD flows
- Public API contracts are stable (Resources, Fields, Actions)
- Authentication is production-hardened
- Security audit completed
- Documentation is complete and accurate
- Profile and 2FA migrations are published as proper Composer stubs

## Rollback Procedure

If a release needs to be invalidated:

1. Do NOT delete the tag (preserve history)
2. Create a new patch TAG (e.g., `v0.3.1-alpha`) with the fix
3. Mark the original GitHub Release with a deprecation note in the body
4. Update `KNOWLEDGE.md` with the incident and lessons learned

## Common Mistakes to Avoid

| Mistake | Consequence | Prevention |
|---|---|---|
| Pushing without `make push` | Token expired, push fails | Always use `make push` |
| Merging without verifying divergence | History contamination | Check `git log develop..master` first |
| Tagging before CI passes | Broken release | Never create TAG if `make ci` fails |
| Forgetting `make sync-package` | Package repo out of date | Always sync after push |
| Forgetting GitHub Release notes | No release documentation | Create release immediately after TAG |
| Tagging on dirty working tree | Non-reproducible release | Always clean before tagging |

## Release History

| Version | Date | Key Changes |
|---|---|---|
| v0.1.0-alpha | Initial | Core CRUD, basic field types, auth |
| v0.2.0-alpha | 2026-04-05 | BelongsToMany, Actions system, Scout search, query hooks |
| v0.3.0-alpha | 2026-04-10 | Profile & 2FA, HasOne field, Error Handling, Pivot Actions |
| v0.3.1-alpha | 2026-04-10 | i18n sync: missing keys in EN and PT-BR |
