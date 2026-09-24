<?php

declare(strict_types=1);

/*
 * Martis bundled the precompiled PrimeReact lara-dark-indigo theme, which
 * paints every component with literal colours: the Martis tokens reached a
 * component only when a selector override in martis.css restyled it, so a
 * component or state without an override kept the stock indigo and dark
 * surfaces (in light mode too) and ignored consumer themes. The theme is now
 * compiled from its SASS source (resources/sass/primereact) with every lara
 * colour variable pointed at a --martis-* token, and the remaining overrides
 * read the tokens as well. These guards keep both sides on the tokens.
 */

beforeEach(function () {
    $root = __DIR__.'/../../resources';

    $this->css = file_get_contents($root.'/css/martis.css');
    $this->sassDir = $root.'/sass/primereact';
    $this->tokens = file_get_contents($this->sassDir.'/_tokens.scss');
    $this->theme = file_get_contents($this->sassDir.'/theme.scss');

    preg_match_all('/(--martis-[a-z0-9-]+)\s*:/', $this->css, $declared);
    $this->declaredTokens = array_unique($declared[1]);
});

/** Literal colours in a CSS value, ignoring var() fallbacks. */
function primeReactLiteralColours(string $value): array
{
    $withoutVars = preg_replace('/var\([^()]*\)/', '', $value);
    preg_match_all('/#[0-9a-fA-F]{3,8}\b|rgba?\(\s*\d[^)]*\)/', $withoutVars, $matches);

    return $matches[0];
}

it('compiles the PrimeReact theme from its source instead of bundling a precompiled one', function () {
    expect($this->css)->not->toContain('primereact/resources/themes/')
        ->and(strpos($this->theme, '@import "tokens"'))->toBeLessThan(strpos($this->theme, '@import "upstream/lara/variables"'))
        ->and(strpos($this->theme, '@import "shims"'))->toBeLessThan(strpos($this->theme, '@import "tokens"'));
});

it('points the lara variables only at Martis tokens that exist', function () {
    preg_match_all('/var\((--martis-[a-z0-9-]+)/', $this->tokens, $used);

    expect($used[1])->not->toBeEmpty();

    foreach (array_unique($used[1]) as $token) {
        expect($this->declaredTokens)->toContain($token);
    }
});

it('maps the lara variables without literal colours, except the white text on severity fills', function () {
    $literal = [];

    foreach (preg_split('/\R/', $this->tokens) as $line) {
        $code = preg_replace('~//.*$~', '', $line);

        if (primeReactLiteralColours($code) !== []) {
            $literal[] = trim($code);
        }
    }

    expect($literal)->toBe(['$severityButtonTextColor: #ffffff;']);
});

it('keeps every colour in the PrimeReact overrides on the tokens', function () {
    $css = preg_replace('~/\*.*?\*/~s', '', $this->css);
    preg_match_all('/([^{}]+)\{([^{}]*)\}/', $css, $rules, PREG_SET_ORDER);

    // The exceptions the overrides keep on purpose: white text or glyphs on a
    // severity fill (as on .martis-btn-danger) and the soft neutral shadow of
    // the dropdown panel.
    $allowed = [
        '/\.p-button-danger\b.* \| color \| #fff(fff)?$/',
        '/^\.p-toast \.p-toast-message-icon \| color \| #fff(fff)?$/',
        '/^\.p-dropdown-panel \| box-shadow \| rgba\(0, 0, 0, [0-9.]+\)$/',
    ];

    $unexpected = [];

    foreach ($rules as [, $selector, $body]) {
        $selector = trim(preg_replace('/\s+/', ' ', $selector));

        if (! str_contains($selector, '.p-')) {
            continue;
        }

        foreach (explode(';', $body) as $declaration) {
            if (! str_contains($declaration, ':')) {
                continue;
            }

            [$property, $value] = array_map('trim', explode(':', $declaration, 2));

            foreach (primeReactLiteralColours($value) as $colour) {
                $entry = "{$selector} | {$property} | {$colour}";
                $isAllowed = array_filter($allowed, fn (string $pattern) => preg_match($pattern, $entry) === 1);

                if ($isAllowed === []) {
                    $unexpected[] = $entry;
                }
            }
        }
    }

    expect($unexpected)->toBe([]);
});

it('ships the MIT licence and the provenance of the upstream theme source', function () {
    $license = file_get_contents($this->sassDir.'/upstream/LICENSE');
    $readme = file_get_contents($this->sassDir.'/upstream/README.md');

    expect($license)->toContain('MIT License')
        ->and($license)->toContain('PrimeTek')
        ->and($readme)->toContain('primefaces/primereact-sass-theme')
        ->and($readme)->toContain('0b17cdc');
});

it('lists every local change to the upstream theme source in its README', function () {
    $readme = file_get_contents($this->sassDir.'/upstream/README.md');
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($this->sassDir.'/upstream', FilesystemIterator::SKIP_DOTS));
    $patched = [];

    foreach ($files as $file) {
        if ($file->getExtension() === 'scss' && str_contains(file_get_contents($file->getPathname()), '// Martis:')) {
            $patched[] = substr($file->getPathname(), strlen($this->sassDir.'/upstream/'));
        }
    }

    expect($patched)->not->toBeEmpty();

    foreach ($patched as $path) {
        expect($readme)->toContain($path);
    }
});
