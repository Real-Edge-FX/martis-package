<?php

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Filesystem\Filesystem;
use Martis\Console\ThemeDiffCommand;

beforeEach(function () {
    $fs = new Filesystem;
    $fs->deleteDirectory(resource_path('css/martis'));
    $fs->deleteDirectory(public_path('vendor/martis/themes'));
    $fs->ensureDirectoryExists(resource_path('css/martis'));
});

afterEach(function () {
    $fs = new Filesystem;
    $fs->deleteDirectory(resource_path('css/martis'));
    $fs->deleteDirectory(public_path('vendor/martis/themes'));
});

it('martis:theme:diff is registered in the service provider', function () {
    $commands = $this->app->make(Kernel::class)->all();
    expect($commands)->toHaveKey('martis:theme:diff');
    expect($commands['martis:theme:diff'])->toBeInstanceOf(ThemeDiffCommand::class);
});

it('reports tokens missing from a consumer theme', function () {
    // Consumer theme declares ONLY --martis-accent. Every other token
    // present in the package CSS should appear in the "missing" set.
    // The consumer also declares --martis-deprecated-thing which the
    // package no longer exposes — that should land in "unknown".
    (new Filesystem)->put(resource_path('css/martis/diff-test.css'), <<<'CSS'
:root {
  --martis-accent: #ff00ff;
  --martis-deprecated-thing: red;
}
CSS);

    config()->set('martis.theme.name', 'diff-test');

    $this->artisan('martis:theme:diff', ['--show-match' => true])
        ->expectsOutputToContain('Missing in consumer')
        ->expectsOutputToContain('--martis-bg')
        ->expectsOutputToContain('Unknown to package')
        ->expectsOutputToContain('--martis-deprecated-thing')
        ->assertExitCode(2); // INVALID — there are missing entries
});

it('returns SUCCESS when consumer theme is fully aligned', function () {
    // We cheat a bit by copying the package CSS as the "consumer theme":
    // every token defined in the package is also declared, and there is
    // no consumer-only addition.
    $packageCss = file_get_contents(__DIR__.'/../../resources/css/martis.css');
    (new Filesystem)->put(resource_path('css/martis/diff-aligned.css'), $packageCss);

    $this->artisan('martis:theme:diff', ['theme' => 'diff-aligned'])
        ->assertExitCode(0);
});

it('treats a referenced-only package token as known, not unknown (reaches exit 0)', function () {
    // Regression: the package uses --martis-accent-contrast via
    // `var(--martis-accent-contrast, #fff)` but never declares it. A
    // consumer that legitimately declares it used to be flagged "unknown",
    // making exit 0 unreachable for a fully-declared theme. It must now be
    // treated as known (Match), so the diff exits 0.
    $packageCss = file_get_contents(__DIR__.'/../../resources/css/martis.css');
    (new Filesystem)->put(
        resource_path('css/martis/diff-ref.css'),
        $packageCss."\n:root { --martis-accent-contrast: #fff; }\n"
    );

    $this->artisan('martis:theme:diff', ['theme' => 'diff-ref'])
        ->expectsOutputToContain('referenced-only')
        ->assertExitCode(0);
});

it('compares the theme source, not the published copy', function () {
    // The app edits and commits resources/css/martis/<name>.css;
    // martis:publish-assets regenerates the published copy from it, so a
    // copy that differs is stale and says nothing about the theme.
    $fs = new Filesystem;
    $packageCss = file_get_contents(__DIR__.'/../../resources/css/martis.css');
    $fs->put(resource_path('css/martis/diff-source.css'), $packageCss);
    $fs->ensureDirectoryExists(public_path('vendor/martis/themes'));
    $fs->put(public_path('vendor/martis/themes/diff-source.css'), ':root { --martis-accent: #ff00ff; }');

    $this->artisan('martis:theme:diff', ['theme' => 'diff-source'])
        ->assertExitCode(0);
});

it('fails with a hint when only a published copy exists', function () {
    // martis:publish-assets deletes a published copy that has no source, so
    // the diff points at where the theme has to live instead of comparing it.
    $fs = new Filesystem;
    $fs->ensureDirectoryExists(public_path('vendor/martis/themes'));
    $fs->put(public_path('vendor/martis/themes/diff-orphan.css'), ':root { --martis-accent: #ff00ff; }');

    $this->artisan('martis:theme:diff', ['theme' => 'diff-orphan'])
        ->expectsOutputToContain('Theme source not found: resources/css/martis/diff-orphan.css')
        ->expectsOutputToContain('public/vendor/martis/themes/diff-orphan.css')
        ->assertExitCode(1);
});

it('fails when the consumer theme file is missing', function () {
    $this->artisan('martis:theme:diff', ['theme' => 'never-existed-theme'])
        ->expectsOutputToContain('Theme source not found: resources/css/martis/never-existed-theme.css')
        ->expectsOutputToContain('php artisan martis:theme never-existed-theme')
        ->assertExitCode(1);
});

it('fails when no theme is configured and no argument is provided', function () {
    config()->set('martis.theme.name', null);

    $this->artisan('martis:theme:diff')
        ->expectsOutputToContain('No theme specified')
        ->assertExitCode(1);
});
