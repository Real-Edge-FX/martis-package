<?php

declare(strict_types=1);

use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Artisan;
use Martis\Sso\SignedToken\PasetoV4Public;

require_once __DIR__.'/../Support/signed-token.php';

// martis:sso:keys and the signed-token side of martis:sso.

beforeEach(function () {
    $this->skeletonFiles = [];

    foreach ([config_path('martis.php'), base_path('.env'), base_path('.env.example')] as $path) {
        $this->skeletonFiles[$path] = is_file($path) ? file_get_contents($path) : null;
    }
});

afterEach(function () {
    if (! isset($this->skeletonFiles)) {
        return;
    }

    foreach ($this->skeletonFiles as $path => $contents) {
        if ($contents === null) {
            is_file($path) && unlink($path);
        } elseif (! is_file($path) || file_get_contents($path) !== $contents) {
            file_put_contents($path, $contents);
        }
    }
});

/** A published config/martis.php and a .env of the host app, fresh. */
function stHostApp(object $test): void
{
    (new Filesystem)->delete(config_path('martis.php'));
    $test->artisan('martis:install', ['--no-interaction' => true])->assertSuccessful();
    file_put_contents(base_path('.env'), "APP_NAME=Test\n");
    file_put_contents(base_path('.env.example'), "APP_NAME=Test\n");
}

/** How many times config/martis.php declares `'name' => [` outside a comment. */
function stDeclarations(string $name): int
{
    return preg_match_all('/^[ \t]*\''.preg_quote($name, '/').'\'[ \t]*=>[ \t]*\[/m', (string) file_get_contents(config_path('martis.php')));
}

/** What config/martis.php of the host app returns now. */
function stHostConfig(): array
{
    return (static fn () => include config_path('martis.php'))();
}

// -----------------------------------------------------------------------------
// martis:sso:keys
// -----------------------------------------------------------------------------

it('prints a new Ed25519 key pair as base64 with where each half goes', function () {
    $this->artisan('martis:sso:keys')
        ->expectsOutputToContain('MARTIS_SSO_ISSUER_PRIVATE_KEY')
        ->expectsOutputToContain('public_keys')
        ->assertSuccessful();

    Artisan::call('martis:sso:keys');
    $output = Artisan::output();

    preg_match('/MARTIS_SSO_ISSUER_PRIVATE_KEY=([A-Za-z0-9+\/=]+)/', $output, $secret);
    preg_match('/public key[^\n]*\n\s*([A-Za-z0-9+\/=]{44})(?=\s|$)/i', $output, $public);
    preg_match('/kid[^\n]*?([0-9a-f]{16})\b/i', $output, $kid);

    expect($secret)->toHaveCount(2)->and($public)->toHaveCount(2)->and($kid)->toHaveCount(2);

    $secretRaw = base64_decode($secret[1], true);
    $publicRaw = base64_decode($public[1], true);

    expect(strlen($secretRaw))->toBe(64)
        ->and(strlen($publicRaw))->toBe(32)
        ->and(sodium_crypto_sign_publickey_from_secretkey($secretRaw))->toBe($publicRaw)
        ->and($kid[1])->toBe(PasetoV4Public::keyId($publicRaw));

    // The pair works: a token the secret key signs, the public key verifies.
    $token = PasetoV4Public::sign('{"ok":true}', $secretRaw);
    expect(PasetoV4Public::verify(PasetoV4Public::parse($token), $publicRaw))->toBeTrue();
});

it('prints a different pair every time', function () {
    Artisan::call('martis:sso:keys');
    $a = Artisan::output();
    Artisan::call('martis:sso:keys');
    $b = Artisan::output();

    preg_match('/MARTIS_SSO_ISSUER_PRIVATE_KEY=(\S+)/', $a, $first);
    preg_match('/MARTIS_SSO_ISSUER_PRIVATE_KEY=(\S+)/', $b, $second);

    expect($first[1])->not->toBe($second[1]);
});

it('never writes the secret key anywhere', function () {
    $before = [];
    foreach ([base_path('.env'), base_path('.env.example'), config_path('martis.php')] as $path) {
        $before[$path] = is_file($path) ? file_get_contents($path) : null;
    }
    $storage = glob(storage_path('*/*')) ?: [];
    $storageBefore = array_map(fn ($f) => is_file($f) ? md5_file($f) : null, $storage);

    Artisan::call('martis:sso:keys');
    preg_match('/MARTIS_SSO_ISSUER_PRIVATE_KEY=(\S+)/', Artisan::output(), $secret);

    foreach ($before as $path => $contents) {
        $now = is_file($path) ? file_get_contents($path) : null;
        expect($now)->toBe($contents);
        expect((string) $now)->not->toContain($secret[1]);
    }
    expect(array_map(fn ($f) => is_file($f) ? md5_file($f) : null, $storage))->toBe($storageBefore);
});

// -----------------------------------------------------------------------------
// martis:sso <name> --driver=signed-token
// -----------------------------------------------------------------------------

it('scaffolds the consumer block of the signed-token driver', function () {
    stHostApp($this);

    $this->artisan('martis:sso', [
        'provider' => 'panel-a',
        '--driver' => 'signed-token',
        '--no-composer' => true,
        '--no-migrate' => true,
        '--no-map' => true,
    ])->expectsOutputToContain('martis:sso:keys')->assertSuccessful();

    $block = stHostConfig()['auth']['sso']['providers']['panel_a'];

    expect($block)->toMatchArray([
        'enabled' => false,
        'driver' => 'signed-token',
        'identity_match_attribute' => 'external_id',
        'max_age_seconds' => 60,
        'clock_skew_seconds' => 30,
        'role_claim' => 'roles',
        'sync_roles' => true,
    ])->and($block)->toHaveKeys(['authorize_url', 'public_keys', 'issuer', 'audience', 'label', 'role_strategy', 'on_no_role_match'])
        ->and($block['public_keys'])->toBe([]);

    $env = (string) file_get_contents(base_path('.env'));
    foreach (['MARTIS_SSO_ENABLED', 'MARTIS_SSO_PANEL_A_ENABLED', 'MARTIS_SSO_PANEL_A_AUTHORIZE_URL', 'MARTIS_SSO_PANEL_A_PUBLIC_KEYS', 'MARTIS_SSO_PANEL_A_ISSUER', 'MARTIS_SSO_PANEL_A_AUDIENCE'] as $key) {
        expect($env)->toContain("{$key}=");
    }
    expect($env)->not->toContain('AZURE_');
});

it('does not duplicate the block when run twice', function () {
    stHostApp($this);
    $arguments = ['provider' => 'panel-a', '--driver' => 'signed-token', '--no-composer' => true, '--no-migrate' => true, '--no-map' => true];

    $this->artisan('martis:sso', $arguments)->assertSuccessful();
    $this->artisan('martis:sso', $arguments)->expectsOutputToContain('already declared')->assertSuccessful();

    expect(stDeclarations('panel_a'))->toBe(1);
});

it('refuses a driver it does not know', function () {
    $this->artisan('martis:sso', ['provider' => 'panel-a', '--driver' => 'nonsense', '--no-composer' => true])
        ->expectsOutputToContain('nonsense')
        ->assertFailed();
});

// -----------------------------------------------------------------------------
// martis:sso <audience> --issuer
// -----------------------------------------------------------------------------

it('scaffolds an audience of the issuer block and never writes a key', function () {
    stHostApp($this);

    $this->artisan('martis:sso', ['provider' => 'panel-b', '--issuer' => true])
        ->expectsOutputToContain('martis:sso:keys')
        ->assertSuccessful();

    $issuer = stHostConfig()['auth']['sso']['issuer'];

    expect($issuer['audiences'])->toHaveKey('panel-b')
        ->and($issuer['audiences']['panel-b'])->toHaveKey('callback_url');

    $env = (string) file_get_contents(base_path('.env'));
    expect($env)->toContain('MARTIS_SSO_ISSUER_ENABLED=')
        ->toContain('MARTIS_SSO_ISSUER=')
        ->toContain('MARTIS_SSO_ISSUER_PRIVATE_KEY=')
        ->toContain('MARTIS_SSO_ISSUER_AUDIENCE_PANEL_B_CALLBACK_URL=');
    preg_match('/MARTIS_SSO_ISSUER_PRIVATE_KEY=(.*)/', $env, $key);
    expect(trim($key[1]))->toBe('');
});

it('does not duplicate the audience when run twice', function () {
    stHostApp($this);

    $this->artisan('martis:sso', ['provider' => 'panel-b', '--issuer' => true])->assertSuccessful();
    $this->artisan('martis:sso', ['provider' => 'panel-b', '--issuer' => true])
        ->expectsOutputToContain('already declared')
        ->assertSuccessful();

    expect(stDeclarations('panel-b'))->toBe(1);
});

it('does not take --issuer together with --driver', function () {
    $this->artisan('martis:sso', ['provider' => 'panel-b', '--issuer' => true, '--driver' => 'signed-token'])
        ->assertFailed();
});

it('ships the issuer block and a provider example in the default config', function () {
    $config = require __DIR__.'/../../config/martis.php';

    expect($config['auth']['sso']['issuer'])->toMatchArray([
        'enabled' => false,
        'ttl_seconds' => 60,
        'claims' => null,
        'audiences' => [],
    ])->and($config['auth']['sso']['issuer'])->toHaveKeys(['issuer', 'private_key', 'environment']);

    expect(file_get_contents(__DIR__.'/../../config/martis.php'))
        ->toContain("'driver' => 'signed-token'")
        ->toContain('MARTIS_SSO_ISSUER_PRIVATE_KEY');
});

it('suggests ext-sodium in composer.json', function () {
    $composer = json_decode((string) file_get_contents(__DIR__.'/../../composer.json'), true, 16, JSON_THROW_ON_ERROR);

    expect($composer['suggest'])->toHaveKey('ext-sodium');
});
