<?php

declare(strict_types=1);

use Martis\Discovery\Psr4NamespaceResolver;

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

/**
 * Create a throwaway tree that mimics a Laravel app root:
 *   <root>/app/Martis/Customer/Resources
 *   <root>/modules/Billing/Martis
 *
 * Built under the real path of the temp dir (macOS symlinks /var to
 * /private/var) so a path that realpath() cannot resolve, such as the
 * backslash form below, still compares equal to a resolved root.
 */
function psr4TempRoot(): string
{
    $root = (realpath(sys_get_temp_dir()) ?: sys_get_temp_dir()).'/martis_psr4_'.uniqid();
    mkdir($root.'/app/Martis/Customer/Resources', 0755, true);
    mkdir($root.'/modules/Billing/Martis', 0755, true);

    return $root;
}

// ---------------------------------------------------------------------------
// Explicit map
// ---------------------------------------------------------------------------

it('returns the prefix itself when the directory is the PSR-4 root', function () {
    $root = psr4TempRoot();

    try {
        $resolver = new Psr4NamespaceResolver(['App\\' => [$root.'/app']]);

        expect($resolver->resolve($root.'/app'))->toBe('App');
    } finally {
        rmtree($root);
    }
});

it('appends the relative path as namespace segments', function () {
    $root = psr4TempRoot();

    try {
        $resolver = new Psr4NamespaceResolver(['App\\' => [$root.'/app']]);

        expect($resolver->resolve($root.'/app/Martis'))->toBe('App\\Martis')
            ->and($resolver->resolve($root.'/app/Martis/Customer/Resources'))->toBe('App\\Martis\\Customer\\Resources');
    } finally {
        rmtree($root);
    }
});

it('resolves a directory under a second PSR-4 root', function () {
    $root = psr4TempRoot();

    try {
        $resolver = new Psr4NamespaceResolver([
            'App\\' => [$root.'/app'],
            'Modules\\' => [$root.'/modules'],
        ]);

        expect($resolver->resolve($root.'/modules/Billing/Martis'))->toBe('Modules\\Billing\\Martis');
    } finally {
        rmtree($root);
    }
});

it('prefers the longest root when roots are nested', function () {
    $root = psr4TempRoot();

    try {
        $resolver = new Psr4NamespaceResolver([
            'App\\' => [$root.'/app'],
            'Panels\\' => [$root.'/app/Martis'],
        ]);

        expect($resolver->resolve($root.'/app/Martis/Customer'))->toBe('Panels\\Customer');
    } finally {
        rmtree($root);
    }
});

it('normalises trailing slashes and backslash separators', function () {
    $root = psr4TempRoot();

    try {
        $resolver = new Psr4NamespaceResolver(['App\\' => [$root.'/app/']]);

        expect($resolver->resolve($root.'/app/Martis/'))->toBe('App\\Martis')
            ->and($resolver->resolve(str_replace('/', '\\', $root.'/app/Martis')))->toBe('App\\Martis');
    } finally {
        rmtree($root);
    }
});

it('resolves symlinks before comparing paths', function () {
    $root = psr4TempRoot();
    symlink($root.'/app', $root.'/link');

    try {
        $resolver = new Psr4NamespaceResolver(['App\\' => [$root.'/app']]);

        expect($resolver->resolve($root.'/link/Martis'))->toBe('App\\Martis');
    } finally {
        unlink($root.'/link');
        rmtree($root);
    }
});

it('returns null for a directory outside every root', function () {
    $root = psr4TempRoot();

    try {
        $resolver = new Psr4NamespaceResolver(['App\\' => [$root.'/app']]);

        expect($resolver->resolve($root.'/modules/Billing'))->toBeNull()
            ->and($resolver->resolve('/nonexistent/path/that/does/not/exist'))->toBeNull();
    } finally {
        rmtree($root);
    }
});

it('returns null with an empty map', function () {
    expect((new Psr4NamespaceResolver([]))->resolve(__DIR__))->toBeNull();
});

// ---------------------------------------------------------------------------
// Default source: the Composer class loaders registered in this process
// ---------------------------------------------------------------------------

it('reads the registered Composer class loaders when no map is given', function () {
    $resolver = new Psr4NamespaceResolver;

    // composer.json: "Martis\\": "src/", "Martis\\Tests\\": "tests/"
    expect($resolver->resolve(dirname(__DIR__, 3).'/src/Discovery'))->toBe('Martis\\Discovery')
        ->and($resolver->resolve(dirname(__DIR__, 2)))->toBe('Martis\\Tests')
        ->and($resolver->resolve(__DIR__))->toBe('Martis\\Tests\\Unit\\Discovery');
});
