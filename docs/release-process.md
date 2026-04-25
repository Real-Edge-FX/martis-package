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
- Build artifacts committed (`make build` if frontend changed)
- No known critical regressions in the playground

### D. Versioning (MAJOR.MINOR.PATCH-alpha)
- New field types, features, or systems → bump MINOR
- Bug fixes only → bump PATCH
- Breaking API changes → bump MAJOR

### E. Documentation
- README reflects all new fields and features
- `docs/` updated for new capabilities
- `PARITY_MAP.md` updated if parity status changed

## Execution Steps

### Step 1 — Commit pending assets (if any)

```
git add public/ package-lock.json
git commit -m "chore(assets): rebuild package assets for release vX.Y.Z-alpha"
```

Use this whenever frontend files changed in the package. End users do not build Martis locally, so the package release must already contain the compiled assets.

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

### Step 7 — Release to package repo (martis-package)

> ⚠️ **CRITICAL:** The TAG and GitHub Release must be created in `Real-Edge-FX/martis-package`, NOT just in the playground. `martis-package` is the repo users install via Composer. Tags in the playground are for internal tracking only.

```bash
# 7a. Push packages/martis to develop of martis-package
make release-package VERSION=vX.Y.Z-alpha

# 7b. Push same commit to main of martis-package
SUBTREE_HASH=$(git subtree split --prefix=packages/martis 2>/dev/null | tail -1)
GIT_ASKPASS=/home/martis/.git-askpass.sh git push origin-package ${SUBTREE_HASH}:refs/heads/main --no-verify
```

### Step 8 — Create TAG in martis-package (via GitHub API)

> TAG must be created in `martis-package`, not in `martis`. Use the GitHub API since `gh` CLI is not installed on the server.

```bash
TOKEN=$(git remote get-url origin-package | grep -oP "(?<=x-access-token:)[^@]+")
SUBTREE_HASH=$(git subtree split --prefix=packages/martis 2>/dev/null | tail -1)

# Create annotated tag object
TAG_RESPONSE=$(curl -s -X POST \
  -H "Authorization: token $TOKEN" \
  -H "Content-Type: application/json" \
  https://api.github.com/repos/Real-Edge-FX/martis-package/git/tags \
  --data-raw "{"tag":"vX.Y.Z-alpha","message":"Release vX.Y.Z-alpha","object":"$SUBTREE_HASH","type":"commit","tagger":{"name":"RealEdgeFX","email":"noreply@realedgefx.com","date":"$(date -u +%Y-%m-%dT%H:%M:%SZ)"}}")
TAG_SHA=$(echo "$TAG_RESPONSE" | python3 -c "import sys,json; print(json.load(sys.stdin)[sha])")

# Create ref pointing to tag object
curl -s -X POST \
  -H "Authorization: token $TOKEN" \
  -H "Content-Type: application/json" \
  https://api.github.com/repos/Real-Edge-FX/martis-package/git/refs \
  --data-raw "{"ref":"refs/tags/vX.Y.Z-alpha","sha":"$TAG_SHA"}"
```

### Step 9 — Create GitHub Release in martis-package

Use the GitHub API to create a prerelease on `Real-Edge-FX/martis-package` (not `martis`):
- Tag: `vX.Y.Z-alpha`
- Title: `vX.Y.Z-alpha`
- Pre-release: `true`
- Body: full release notes in English

```bash
curl -s -X POST \
  -H "Authorization: token $TOKEN" \
  -H "Content-Type: application/json" \
  https://api.github.com/repos/Real-Edge-FX/martis-package/releases \
  --data-raw "{"tag_name":"vX.Y.Z-alpha","name":"vX.Y.Z-alpha","body":"...","draft":false,"prerelease":true}"
```

### Step 10 — Create GitHub Release in playground (optional reference)

Optionally create a mirrored release on `Real-Edge-FX/martis` for internal reference.

### Step 11 — Rebuild and deploy martis-docs

After bumping the version in `martis-docs/src/data/version.ts` (done by `make sync-package`), rebuild the static docs site:

```bash
ssh -i secrets/martis_server_ed25519 martis@192.168.50.21 \
  "source ~/.nvm/nvm.sh && nvm use 22 && cd /home/martis/martis-docs && pnpm build"
```

> ⚠️ **Important:** `dist/` is gitignored and served directly by Caddy from the filesystem. The rebuild must be done manually on the server after each release. The version displayed on the landing page (`https://martis-docs.realedgefx.com`) will only update after this step.

Verify the live site shows the correct version before proceeding.

### Step 12 — Post-release validation

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

- All parity features are implemented (see `PARITY_MAP.md`)
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
| Creating TAG only in playground (`martis`) | Package users cannot install the version | Always run Steps 7-9 to tag `martis-package` |
| Creating GitHub Release in playground only | Misleading release notes, package users miss the release | Release notes go to `martis-package`, not `martis` |
| Forgetting GitHub Release notes | No release documentation | Create release immediately after TAG |
| Tagging on dirty working tree | Non-reproducible release | Always clean before tagging |
| Forgetting to rebuild martis-docs | Landing page shows old version | Always run Step 11 (pnpm build on server) after sync-package |

## Release History

| Version | Date | Key Changes |
|---|---|---|
| v0.1.0-alpha | Initial | Core CRUD, basic field types, auth |
| v0.2.0-alpha | 2026-04-05 | BelongsToMany, Actions system, Scout search, query hooks |
| v0.3.0-alpha | 2026-04-10 | Profile & 2FA, HasOne field, Error Handling, Pivot Actions |
| v0.3.1-alpha | 2026-04-10 | i18n sync: missing keys in EN and PT-BR |
