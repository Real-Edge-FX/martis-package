#!/usr/bin/env bash
#
# Pre-tag verification — run BEFORE creating any vN.N.N tag on
# martis-package. Aborts when:
#
#   1. martis-docs/src/data/landing.ts VERSION pill ≠ the tag.
#   2. martis-package/CHANGELOG.md does not have a [vN.N.N] section
#      for the tag (catches missing release notes).
#   3. martis-docs/scripts/sync-docs.mjs errors against this
#      martis-package checkout's docs/ (e.g. a page missing from its
#      porter MAP). The deploy itself syncs the docs of the release it
#      publishes, so this only needs to catch a sync-time error before
#      the tag exists, not compare content against a checked-out site.
#   4. martis-docs/src/data/landing.ts TESTS_PASSING ≠ the README
#      "= **N passing**" total, or a landing component hardcodes its
#      own "N tests passing" instead of reading TESTS_PASSING.
#
# Failure exits non-zero. Success prints a one-line confirmation. The
# user (and the loop driver) treat "trio atómico" — package tag +
# martis-docs PR + (when relevant) consumer bump — as the unit of
# release. This script catches the most common failure mode I keep
# repeating: tagging the package without remembering to bump the
# landing pill in martis-docs first.
#
# Keep this file in step with the workspace copy (`pre-tag-check.sh` at
# the workspace root). Both need the workspace layout: martis-docs next
# to martis-package.
#
# Usage:
#   bash martis-package/.tooling/pre-tag.sh v1.9.2
#
set -euo pipefail

if [ $# -ne 1 ]; then
    echo "usage: $0 vN.N.N" >&2
    exit 2
fi

TAG="$1"
# Resolve to the workspace root (`martis/`), assuming the script lives
# at `martis-package/.tooling/pre-tag.sh`. Override with PRE_TAG_ROOT
# when running from a checkout layout where martis-package and
# martis-docs are not siblings (e.g. a git worktree of martis-package).
SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
ROOT="${PRE_TAG_ROOT:-$(cd "$SCRIPT_DIR/../.." && pwd)}"
DOCS_LANDING="$ROOT/martis-docs/src/data/landing.ts"
CHANGELOG="$ROOT/martis-package/CHANGELOG.md"

# 0. The martis-docs checkout must be what is deployed: main, clean. A
#    check against another branch passes on stale content (2026-09-25: the
#    checkout sat on an old docs branch, this check passed, and the deploy
#    from it replaced the redesigned site).
DOCS_DIR="$ROOT/martis-docs"
git -C "$DOCS_DIR" fetch -q origin main
if [ -n "$(git -C "$DOCS_DIR" status --porcelain --untracked-files=no)" ] \
    || [ "$(git -C "$DOCS_DIR" rev-parse HEAD)" != "$(git -C "$DOCS_DIR" rev-parse origin/main)" ]; then
    echo "✗ martis-docs is not a clean checkout of origin/main (branch $(git -C "$DOCS_DIR" branch --show-current), HEAD $(git -C "$DOCS_DIR" rev-parse --short HEAD))." >&2
    echo "  Merge the docs PR into main, then check out main and pull before tagging." >&2
    exit 1
fi

# 1. Landing pill check. Since the redesign the version and the test count
#    live in src/data/site.ts (RELEASE.version, RELEASE.tests); VERSION in
#    landing.ts is `v${RELEASE.version}`.
DOCS_SITE="$ROOT/martis-docs/src/data/site.ts"
if [ -f "$DOCS_SITE" ] && grep -qE "^export const RELEASE = " "$DOCS_SITE"; then
    PILL="v$(grep -oE "^  version: '[^']+'" "$DOCS_SITE" | sed -E "s/.*'([^']+)'.*/\1/")"
    PILL_FILE="$DOCS_SITE"
else
    if [ ! -f "$DOCS_LANDING" ]; then
        echo "✗ martis-docs/src/data/landing.ts not found at $DOCS_LANDING" >&2
        exit 1
    fi
    PILL=$(grep -oE "VERSION = '[^']+'" "$DOCS_LANDING" | sed -E "s/.*'([^']+)'.*/\1/")
    PILL_FILE="$DOCS_LANDING"
fi
# Since martis-docs PR #112 the deploy writes the version and the test count
# from the release itself (scripts/release-stats.mjs), after the tag exists:
# the committed pill trails the tag by design, so it is not required here.
STATS_FROM_RELEASE=0
[ -f "$ROOT/martis-docs/scripts/release-stats.mjs" ] && STATS_FROM_RELEASE=1
if [ "$STATS_FROM_RELEASE" = "1" ]; then
    echo "• release pill and test count: written from the $TAG release by martis-docs scripts/deploy.sh (committed pill: $PILL)"
elif [ "$PILL" != "$TAG" ]; then
    echo "✗ martis-docs release pill is '$PILL' but tag is '$TAG'." >&2
    echo "  Bump $PILL_FILE and merge the docs PR before tagging." >&2
    exit 1
fi

# 2. Changelog section check.
if [ ! -f "$CHANGELOG" ]; then
    echo "✗ martis-package/CHANGELOG.md not found at $CHANGELOG" >&2
    exit 1
fi
SECTION=$(grep -E "^## \[${TAG#v}\]" "$CHANGELOG" || true)
if [ -z "$SECTION" ]; then
    echo "✗ CHANGELOG.md is missing a section for [${TAG#v}]." >&2
    echo "  Write release notes there before tagging." >&2
    exit 1
fi

# 3. Docs sync check. The martis-docs deploy now syncs martis-package's
#    docs/ at deploy time (scripts/sync-docs.mjs run against the tag it's
#    publishing), so there is no checked-out site content to compare
#    against any more. This just runs the porter against the current
#    package docs and fails loudly on a sync-time error (e.g. an
#    unmapped page), before the tag exists.
SYNC_DOCS_MJS="$DOCS_DIR/scripts/sync-docs.mjs"
if [ ! -f "$SYNC_DOCS_MJS" ]; then
    echo "✗ martis-docs/scripts/sync-docs.mjs not found at $SYNC_DOCS_MJS" >&2
    exit 1
fi
SYNC_TMPDIR="$(mktemp -d)"
trap 'rm -rf "$SYNC_TMPDIR"' EXIT
SYNC_RC=0
SYNC_OUTPUT="$(node "$SYNC_DOCS_MJS" --package-dir "$ROOT/martis-package" --content-dir "$SYNC_TMPDIR" 2>&1)" || SYNC_RC=$?
if [ "$SYNC_RC" -ne 0 ]; then
    echo "✗ martis-docs/scripts/sync-docs.mjs failed against martis-package/docs:" >&2
    echo "$SYNC_OUTPUT" | sed 's/^/  /' >&2
    echo "  Fix the sync (e.g. add the page to MAP) before tagging." >&2
    exit 1
fi

# 4. Test-count check. The hero status line and the stat strip both read
#    TESTS_PASSING; it must match the README's "Test coverage" total, and
#    no component may carry a count of its own (the hero once kept a stale
#    "2,408").
README="$ROOT/martis-package/README.md"
# A count is plain digits or digits grouped by thousands ("4057", "4,057").
# Stripping the commas of a misgrouped "40,57" would pass it as 4057.
COUNT_FORMAT='^([0-9]+|[0-9]{1,3}(,[0-9]{3})+)$'
if [ "$PILL_FILE" = "$DOCS_SITE" ]; then
    LANDING_RAW=$(grep -oE "^  tests: [0-9_]+" "$DOCS_SITE" | grep -oE "[0-9_]+$" | tr -d '_' || true)
else
    LANDING_RAW=$(grep -oE "TESTS_PASSING = '[^']+'" "$DOCS_LANDING" | sed -E "s/.*'([^']+)'.*/\1/" || true)
fi
COVERAGE_LINES=$(grep -E '^- \*\*Test coverage\*\*' "$README" || true)
if [ "$(printf '%s' "$COVERAGE_LINES" | grep -c 'Test coverage' || true)" != "1" ]; then
    echo "✗ The README needs exactly one '- **Test coverage**' line carrying the '= **N passing**' total." >&2
    exit 1
fi
README_RAW=$(printf '%s\n' "$COVERAGE_LINES" | grep -oE '= \*\*[0-9,]+ passing\*\*' | grep -oE '[0-9,]+' || true)
if ! printf '%s' "$LANDING_RAW" | grep -qE "$COUNT_FORMAT"; then
    echo "✗ Could not read the landing TESTS_PASSING ('$LANDING_RAW'): expected digits, optionally grouped by thousands." >&2
    exit 1
fi
if ! printf '%s' "$README_RAW" | grep -qE "$COUNT_FORMAT"; then
    echo "✗ Could not read the README Test coverage total ('$README_RAW'): expected '= **N passing**'." >&2
    exit 1
fi
LANDING_TESTS=$(printf '%s' "$LANDING_RAW" | tr -d ',')
README_TESTS=$(printf '%s' "$README_RAW" | tr -d ',')
if [ "$STATS_FROM_RELEASE" != "1" ] && [ "$LANDING_TESTS" != "$README_TESTS" ]; then
    echo "✗ martis-docs landing shows $LANDING_TESTS tests passing but the README says $README_TESTS." >&2
    echo "  Set the test count in $PILL_FILE to the CI Pest + Vitest total before tagging." >&2
    exit 1
fi
for DIR in "$ROOT/martis-docs/src/components" "$ROOT/martis-docs/src/pages"; do
    if [ ! -d "$DIR" ]; then
        echo "✗ $DIR not found: cannot check the landing components for a hardcoded test count." >&2
        exit 1
    fi
done
HARDCODED=$(grep -rniE "[0-9][0-9,]*\+?[[:space:]]*tests?[[:space:]]+passing" "$ROOT/martis-docs/src/components" "$ROOT/martis-docs/src/pages" || true)
if [ -n "$HARDCODED" ]; then
    echo "✗ A landing component hardcodes its own test count; render TESTS_PASSING instead:" >&2
    echo "$HARDCODED" | sed 's/^/  /' >&2
    exit 1
fi

echo "✓ pre-tag check passed for $TAG (landing pill, CHANGELOG section, docs sync, test count)."
