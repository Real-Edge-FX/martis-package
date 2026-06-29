<?php

declare(strict_types=1);

/**
 * Guards the declared framework compatibility. Laravel 11 is no longer
 * supported (dropped from the CI matrix — every v11.x release carries a
 * security advisory that blocks composer install), so the constraint must
 * not allow the 11.x major.
 *
 * Robust to the CI "Pin Laravel" step, which rewrites the constraint to a
 * single matrix version (e.g. `12.*` / `13.*`) — we only assert that no
 * segment targets major 11, never that a specific `^12`/`^13` string is
 * present.
 */
it('does not allow the Laravel 11 major in the framework constraint', function () {
    $composer = json_decode((string) file_get_contents(dirname(__DIR__, 2).'/composer.json'), true);
    // The CI matrix pins the version via `composer require --dev`, which
    // MOVES laravel/framework into require-dev — so read it from whichever
    // section holds it.
    $constraint = (string) (
        $composer['require']['laravel/framework']
        ?? $composer['require-dev']['laravel/framework']
        ?? ''
    );

    expect($constraint)->not->toBe('');

    $segments = preg_split('/[|,\s]+/', $constraint) ?: [];
    $allowsEleven = false;
    foreach ($segments as $segment) {
        // Match a segment whose major is 11 (after any range operator),
        // e.g. ^11.0, ~11.2, 11.*, >=11.0 — but NOT ^12.11 / 12.11.
        if (preg_match('/^[\^~><=!]*11(\.|$|\*)/', $segment)) {
            $allowsEleven = true;
            break;
        }
    }

    expect($allowsEleven)->toBeFalse("laravel/framework constraint [{$constraint}] must not allow Laravel 11.");
});
