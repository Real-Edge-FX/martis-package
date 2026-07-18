# Test-only image: php:8.3-cli plus the extensions CI provides.
#
# The bare php:8.3-cli image ships without `gd` (image-upload field tests
# call UploadedFile::fake()->image()) or `pcntl` (the mcp:serve SIGTERM
# test), so a raw `docker run php:8.3-cli … pest` reports ~29 phantom
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
