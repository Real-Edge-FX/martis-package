# Test-only image: php:8.3-cli plus the extensions CI provides.
#
# The bare php:8.3-cli image ships without `gd` (image-upload field tests
# call UploadedFile::fake()->image()) or `pcntl` (the mcp:serve SIGTERM
# test), so a raw `docker run php:8.3-cli … pest` reports phantom
# failures that are purely environmental — none are real. CI (via
# shivammathur/setup-php) has both extensions, which is why it is green.
# This image matches CI so `scripts/test.sh` reports 0 failures.
#
# Built and used by scripts/test.sh; never shipped in the Composer dist
# (see .gitattributes export-ignore).
FROM php:8.3-cli

RUN apt-get update \
 && apt-get install -y --no-install-recommends \
      libpng-dev libjpeg-dev libfreetype6-dev \
 && docker-php-ext-configure gd --with-freetype --with-jpeg \
 && docker-php-ext-install -j"$(nproc)" gd pcntl \
 && apt-get clean \
 && rm -rf /var/lib/apt/lists/*

# A non-root user (Trivy DS-0002). `scripts/test.sh` runs the container as the
# host user's uid:gid instead, so the files Pest writes into the bind-mounted
# checkout stay owned by the person who runs it; this user is the fallback for
# a bare `docker run`, and the image no longer defaults to root.
RUN useradd --create-home --uid 1000 --shell /usr/sbin/nologin pest
USER pest

# No health check (Trivy DS-0026): the image is a one-shot CLI container that
# runs Pest and exits. It serves nothing, so there is nothing to probe, and
# naming the omission keeps a scanner from reading it as an oversight.
HEALTHCHECK NONE
