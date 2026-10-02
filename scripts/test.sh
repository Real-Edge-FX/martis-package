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
#
# MARTIS_PEST_IMAGE names the image (default martis-pest:8.3). Git worktrees
# whose .docker/pest.Dockerfile differs would otherwise re-tag one image under
# each other's feet, so a worktree that changes the Dockerfile sets its own.
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
IMAGE="${MARTIS_PEST_IMAGE:-martis-pest:8.3}"

# Build once; Docker layer caching makes subsequent runs instant.
docker build -q -t "$IMAGE" -f "$ROOT/.docker/pest.Dockerfile" "$ROOT/.docker" >/dev/null

# The container runs as the caller's uid:gid, not root: what Pest writes into
# the bind-mounted checkout stays owned by the caller, and the suite never
# holds more privilege than the person running it. The uid has no passwd entry
# in the image, so HOME points at the container's world-writable /tmp.
exec docker run --rm \
  --user "$(id -u):$(id -g)" \
  -e HOME=/tmp \
  -v "$ROOT":/martis-package -w /martis-package \
  -e MARTIS_TEST_PROCESS_TIMEOUT \
  "$IMAGE" \
  php vendor/bin/pest --no-coverage "$@"
