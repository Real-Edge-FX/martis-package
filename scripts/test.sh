#!/usr/bin/env bash
#
# Run the Pest suite in a container that matches CI's PHP setup, so the
# result is 0-failure. A raw `docker run php:8.3-cli … pest` under-provisions
# the environment and reports phantom failures that are NOT real:
#
#   * missing `gd`  -> UploadedFile::fake()->image() tests fail
#   * missing `pcntl` -> the mcp:serve SIGTERM test fails
#
# This script fixes both: it builds an image with gd + pcntl (cached after
# the first run) and mounts at /martis-package. Match it to CI, get CI's
# result. (The mount path no longer matters: StubResolverTest compares the
# stub path with the checkout itself since d534e0973.) No session or cache
# driver needs pinning: the tests ignore the `.env` a killed
# `vendor/bin/testbench` leaves in the testbench skeleton under vendor/
# (tests/TestCase.php), and tests/bootstrap.php raises a memory limit below
# 1G.
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
  "$IMAGE" \
  php vendor/bin/pest --no-coverage "$@"
