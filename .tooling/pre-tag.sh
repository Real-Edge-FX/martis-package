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

echo "✓ pre-tag check passed for $TAG (landing pill, CHANGELOG section, docs sync)."
