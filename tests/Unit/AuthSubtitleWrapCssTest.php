<?php

declare(strict_types=1);

/*
 * The subtitle of the auth card wrapped between "sign-" and "in" in the 400px
 * card ("... your organisation sign-\nin."), because a browser breaks a line
 * after a hyphen. `text-wrap: balance` spreads the text over its lines, so the
 * break lands between words; a browser without it keeps the old wrapping. The
 * Vitest CSS plugin blanks every stylesheet import, so the guard reads the
 * source here.
 */

it('balances the lines of the auth card subtitle', function () {
    $css = (string) file_get_contents(__DIR__.'/../../resources/css/martis.css');

    preg_match('/^\.martis-auth-sub \{([^}]*)\}/m', $css, $rule);

    expect($rule)->not->toBeEmpty()
        ->and($rule[1])->toMatch('/text-wrap:\s*balance;/');
});
