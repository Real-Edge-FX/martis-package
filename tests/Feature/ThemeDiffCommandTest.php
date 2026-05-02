<?php

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Filesystem\Filesystem;
use Martis\Console\ThemeDiffCommand;

it('martis:theme:diff is registered in the service provider', function () {
    $commands = $this->app->make(Kernel::class)->all();
    expect($commands)->toHaveKey('martis:theme:diff');
    expect($commands['martis:theme:diff'])->toBeInstanceOf(ThemeDiffCommand::class);
});

it('reports tokens missing from a consumer theme', function () {
    $fs = new Filesystem;
    $themeDir = public_path('vendor/martis/themes');
    $fs->ensureDirectoryExists($themeDir);

    // Consumer theme declares ONLY --martis-accent. Every other token
    // present in the package CSS should appear in the "missing" set.
    // The consumer also declares --martis-deprecated-thing which the
    // package no longer exposes — that should land in "unknown".
    $fs->put($themeDir.'/diff-test.css', <<<'CSS'
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

    $fs->delete($themeDir.'/diff-test.css');
});

it('returns SUCCESS when consumer theme is fully aligned', function () {
    // We cheat a bit by symlinking the package CSS as the "consumer
    // theme" — every token defined in the package is also declared,
    // and there is no consumer-only addition.
    $fs = new Filesystem;
    $themeDir = public_path('vendor/martis/themes');
    $fs->ensureDirectoryExists($themeDir);

    $packageCss = file_get_contents(__DIR__.'/../../resources/css/martis.css');
    $fs->put($themeDir.'/diff-aligned.css', $packageCss);

    $this->artisan('martis:theme:diff', ['theme' => 'diff-aligned'])
        ->assertExitCode(0);

    $fs->delete($themeDir.'/diff-aligned.css');
});

it('fails when the consumer theme file is missing', function () {
    $this->artisan('martis:theme:diff', ['theme' => 'never-existed-theme'])
        ->assertExitCode(1);
});

it('fails when no theme is configured and no argument is provided', function () {
    config()->set('martis.theme.name', null);

    $this->artisan('martis:theme:diff')
        ->expectsOutputToContain('No theme specified')
        ->assertExitCode(1);
});
