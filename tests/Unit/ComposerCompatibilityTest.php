<?php

declare(strict_types=1);

/**
 * Guards the declared framework compatibility. Laravel 11 is no longer
 * supported (dropped from the CI matrix — every v11.x release carries a
 * security advisory that blocks composer install), so the constraint must
 * not advertise it.
 */
it('does not declare Laravel 11 in the framework constraint', function () {
    $composer = json_decode((string) file_get_contents(dirname(__DIR__, 2).'/composer.json'), true);
    $constraint = $composer['require']['laravel/framework'] ?? '';

    expect($constraint)->not->toContain('11')
        ->and($constraint)->toContain('^12')
        ->and($constraint)->toContain('^13');
});
