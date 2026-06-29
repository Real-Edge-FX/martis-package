<?php

declare(strict_types=1);

/**
 * Regression guard for the duplicate top-level `search` key in
 * config/martis.php. PHP keeps only the last entry for a repeated key, so
 * the second (global-search) block silently dropped `default_limit` and
 * `min_query`. The blocks are now merged into one.
 */
it('martis.search exposes both relationship-search and global-search keys', function () {
    $search = config('martis.search');

    expect($search)->toBeArray()
        ->toHaveKeys(['default_limit', 'min_query', 'enabled', 'placeholder', 'mode', 'mobileMode']);

    // These two were silently dropped by the duplicate-key collision.
    expect($search['default_limit'])->toBe(5)
        ->and($search['min_query'])->toBe(2);
});
