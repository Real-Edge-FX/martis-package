#!/usr/bin/env bash
#
# Run the Pest suite in a container that matches CI's PHP setup, so the
# result is 0-failure. A raw `docker run php:8.3-cli … pest` under-provisions
# the environment and reports ~29 phantom failures that are NOT real:
#
#   * missing `gd`  -> UploadedFile::fake()->image() tests fail
#   * missing `pcntl` -> the mcp:serve SIGTERM test fails
#   * host session driver -> ~16 CookieSessionHandler "cookies on null" errors
#   * mount at /app -> StubResolver path assertions (expect "martis-package/")
#
# This script fixes all four: it builds an image with gd + pcntl (cached
# after the first run), mounts at /martis-package, and pins the array
# session/cache drivers CI resolves. Match it to CI, get CI's result.
#
# Usage:
#   scripts/test.sh                          # full suite
#   scripts/test.sh tests/Feature/Foo.php    # subset (args pass through to pest)
#   scripts/test.sh --filter='keep_signed_in'
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
IMAGE="martis-pest:8.3"

# Build once; Docker layer caching makes subsequent runs instant.
docker build -q -t "$IMAGE" -f "$ROOT/.docker/pest.Dockerfile" "$ROOT/.docker" >/dev/null

exec docker run --rm \
  -v "$ROOT":/martis-package -w /martis-package \
  -e CACHE_STORE=array -e SESSION_DRIVER=array \
  "$IMAGE" \
  php -d memory_limit=1G vendor/bin/pest --no-coverage "$@"
