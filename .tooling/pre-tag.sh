#!/usr/bin/env bash
#
# Pre-tag verification — run BEFORE creating any vN.N.N tag on
# martis-package. Aborts when:
#
#   1. martis-docs/src/data/landing.ts VERSION pill ≠ the tag.
#   2. martis-package/CHANGELOG.md does not have a [vN.N.N] section
#      for the tag (catches missing release notes).
#   3. sync-docs.sh reports drift between martis-package/docs/*.md
#      and martis-docs/src/content/**/*.mdx.
#   4. martis-docs/src/data/landing.ts TESTS_PASSING ≠ the README
#      "= **N passing**" total, or a landing component hardcodes its
#      own "N tests passing" instead of reading TESTS_PASSING.
#
# Keep this file in step with the workspace copy (`pre-tag-check.sh` at
# the workspace root). Both need the workspace layout: martis-docs and
# sync-docs.sh next to martis-package.
#
# Failure exits non-zero. Success prints a one-line confirmation. The
# user (and the loop driver) treat "trio atómico" — package tag +
# martis-docs PR + (when relevant) consumer bump — as the unit of
# release. This script catches the most common failure mode I keep
# repeating: tagging the package without remembering to bump the
# landing pill in martis-docs first.
#
# Usage:
#   bash pre-tag-check.sh v1.9.2
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
# martis-docs are not siblings.
SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
ROOT="${PRE_TAG_ROOT:-$(cd "$SCRIPT_DIR/../.." && pwd)}"
DOCS_LANDING="$ROOT/martis-docs/src/data/landing.ts"
CHANGELOG="$ROOT/martis-package/CHANGELOG.md"

# 1. Landing pill check.
if [ ! -f "$DOCS_LANDING" ]; then
    echo "✗ martis-docs/src/data/landing.ts not found at $DOCS_LANDING" >&2
    exit 1
fi
PILL=$(grep -oE "VERSION = '[^']+'" "$DOCS_LANDING" | sed -E "s/.*'([^']+)'.*/\1/")
if [ "$PILL" != "$TAG" ]; then
    echo "✗ martis-docs landing pill is '$PILL' but tag is '$TAG'." >&2
    echo "  Bump $DOCS_LANDING and commit/push as the docs PR before tagging." >&2
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

# 3. Docs drift check (delegates to the existing sync-docs.sh).
DRIFT=$(bash "$ROOT/sync-docs.sh" 2>&1 | grep -E '^Summary:' || true)
if [ -z "$DRIFT" ]; then
    echo "✗ sync-docs.sh did not produce a summary line — investigate." >&2
    exit 1
fi
if ! echo "$DRIFT" | grep -q '0 missing, 0 drifted'; then
    echo "✗ sync-docs.sh reports drift between martis-package/docs and martis-docs/src/content:" >&2
    echo "  $DRIFT" >&2
    echo "  Mirror the changes manually before tagging." >&2
    exit 1
fi

# 4. Test-count check. The hero status line and the stat strip both read
#    TESTS_PASSING; it must match the README total, and no component may
#    carry a count of its own (the hero once kept a stale "2,408").
README="$ROOT/martis-package/README.md"
LANDING_TESTS=$(grep -oE "TESTS_PASSING = '[^']+'" "$DOCS_LANDING" | sed -E "s/.*'([^']+)'.*/\1/" | tr -d ',' || true)
README_TESTS=$(grep -oE '= \*\*[0-9,]+ passing\*\*' "$README" | head -1 | grep -oE '[0-9,]+' | tr -d ',' || true)
if [ -z "$LANDING_TESTS" ] || [ -z "$README_TESTS" ]; then
    echo "✗ Could not read the test total (landing TESTS_PASSING='$LANDING_TESTS', README='$README_TESTS')." >&2
    exit 1
fi
if [ "$LANDING_TESTS" != "$README_TESTS" ]; then
    echo "✗ martis-docs landing shows $LANDING_TESTS tests passing but the README says $README_TESTS." >&2
    echo "  Set TESTS_PASSING in $DOCS_LANDING to the CI Pest + Vitest total before tagging." >&2
    exit 1
fi
HARDCODED=$(grep -rniE "[0-9][0-9,]* tests? passing" "$ROOT/martis-docs/src/components" "$ROOT/martis-docs/src/pages" 2>/dev/null || true)
if [ -n "$HARDCODED" ]; then
    echo "✗ A landing component hardcodes its own test count; render TESTS_PASSING instead:" >&2
    echo "$HARDCODED" | sed 's/^/  /' >&2
    exit 1
fi

echo "✓ pre-tag check passed for $TAG (landing pill, CHANGELOG section, docs sync, test count)."
