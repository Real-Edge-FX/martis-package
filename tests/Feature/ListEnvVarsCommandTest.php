<?php

declare(strict_types=1);

use Martis\Console\ListEnvVarsCommand;

it('martis:list-env-vars emits a markdown table with the package config env vars', function () {
    $exit = $this->artisan('martis:list-env-vars')
        ->expectsOutputToContain('| Variable | Default |')
        ->expectsOutputToContain('MARTIS_PATH')
        ->expectsOutputToContain('MARTIS_BRAND_NAME')
        ->expectsOutputToContain('Total:')
        ->run();

    expect($exit)->toBe(0);
});

it('martis:list-env-vars --json emits a JSON array', function () {
    $this->artisan('martis:list-env-vars', ['--json' => true])
        ->expectsOutputToContain('"name": "MARTIS_PATH"')
        ->assertExitCode(0);
});

it('the parser captures every kind of default literal', function () {
    $command = new ListEnvVarsCommand;

    $source = <<<'PHP'
<?php
return [
    'a' => env('MARTIS_A_STRING', 'hello'),
    'b' => env('MARTIS_B_BOOL', true),
    'c' => env('MARTIS_C_INT', 42),
    'd' => env('MARTIS_D_NULL'),
    'e' => env('MARTIS_E_NESTED', env('MARTIS_E_NESTED_FALLBACK', 'x')),
    'f' => env('MARTIS_F_ARRAY', ['x', 'y']),
];
PHP;

    $entries = $command->parseEnvCalls($source);

    expect($entries)->toHaveKeys([
        'MARTIS_A_STRING',
        'MARTIS_B_BOOL',
        'MARTIS_C_INT',
        'MARTIS_D_NULL',
        'MARTIS_E_NESTED',
        'MARTIS_E_NESTED_FALLBACK',
        'MARTIS_F_ARRAY',
    ]);
    expect($entries['MARTIS_A_STRING']['default'])->toBe("'hello'");
    expect($entries['MARTIS_B_BOOL']['default'])->toBe('true');
    expect($entries['MARTIS_C_INT']['default'])->toBe('42');
    expect($entries['MARTIS_D_NULL']['default'])->toBe('(no default)');
});

it('the parser deduplicates env vars referenced from multiple config keys', function () {
    $command = new ListEnvVarsCommand;
    $source = <<<'PHP'
<?php
return [
    'a' => env('MARTIS_DUP', 'first'),
    'b' => env('MARTIS_DUP', 'second'),
];
PHP;

    $entries = $command->parseEnvCalls($source);
    expect($entries)->toHaveKey('MARTIS_DUP');
    expect($entries)->toHaveCount(1);
    // First occurrence wins (deterministic).
    expect($entries['MARTIS_DUP']['default'])->toBe("'first'");
});

it('the parser ignores non-MARTIS env vars', function () {
    $command = new ListEnvVarsCommand;
    $source = <<<'PHP'
<?php
return [
    'a' => env('APP_KEY', 'whatever'),
    'b' => env('MARTIS_KEEP_ME', 'yes'),
];
PHP;

    $entries = $command->parseEnvCalls($source);
    expect($entries)->toHaveKey('MARTIS_KEEP_ME');
    expect($entries)->not->toHaveKey('APP_KEY');
});

it('martis:list-env-vars produces a non-trivial number of rows for the live config', function () {
    $command = new ListEnvVarsCommand;
    $source = (string) file_get_contents(__DIR__.'/../../config/martis.php');

    $entries = $command->parseEnvCalls($source);

    // The package ships ~120 env vars as of v1.8.8; the regression
    // floor is intentionally permissive (reject removals of more
    // than ~10% of the surface).
    expect(count($entries))->toBeGreaterThan(100);
});
