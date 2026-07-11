<?php

// Regression guard for the reported drift: `martis:theme <name>` immediately
// followed by `martis:theme:diff <name>` on the SAME package version must be
// drift-free (exit 0). It failed because stubs/theme.css.stub had diverged
// from resources/css/martis.css — 24 declared tokens missing, 1 deprecated
// (--martis-text-faint) still emitted. This test fails if the stub and the
// package's declared token set diverge again.

afterEach(function () {
    @unlink(resource_path('css/martis/diffscaffold.css'));
    @unlink(public_path('vendor/martis/themes/diffscaffold.css'));
});

it('a freshly scaffolded theme passes theme:diff with exit 0', function () {
    // Scaffold writes resources/css/martis/<name>.css and publishes a copy to
    // public/vendor/martis/themes/<name>.css (which theme:diff reads).
    $this->artisan('martis:theme', ['name' => 'DiffScaffold'])->assertExitCode(0);

    $this->artisan('martis:theme:diff', ['theme' => 'diffscaffold'])
        ->expectsOutputToContain('Missing in consumer (0)')
        ->assertExitCode(0);
});
