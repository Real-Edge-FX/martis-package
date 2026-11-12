<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;

require_once __DIR__.'/../Support/signed-token.php';

// martis:sso:check (v2.23.0): the issuer block, each enabled signed-token
// provider block and the SSO cache store, read the way the runtime reads them.
// Read-only, and a line never holds a key value or any part of one.

beforeEach(function () {
    $this->keys = stKeys();
    config()->set('martis.auth.sso.enabled', false);
    config()->set('martis.auth.sso.providers', []);
    config()->set('martis.auth.sso.issuer', [
        'enabled' => false,
        'issuer' => 'https://a.example',
        'private_key' => $this->keys['secret_b64'],
        'audiences' => ['panel-b' => ['callback_url' => 'https://b.example/martis/sso/panel_a/callback']],
    ]);
});

/** Run the command and hand back its exit code and output. */
function ssoCheck(): array
{
    $code = Artisan::call('martis:sso:check');

    return [$code, Artisan::output()];
}

/** Whether any 8-character window of the key material appears in the output. */
function ssoCheckLeaks(string $output, string ...$secrets): bool
{
    foreach ($secrets as $secret) {
        for ($i = 0; $i + 8 <= strlen($secret); $i += 4) {
            if (str_contains($output, substr($secret, $i, 8))) {
                return true;
            }
        }
    }

    return false;
}

it('exits 0 and says so when nothing is enabled', function () {
    [$code, $output] = ssoCheck();

    expect($code)->toBe(0)
        ->and($output)->toContain('skip')->toContain('Nothing to check');
});

it('passes a good issuer block and a good consumer block', function () {
    $other = stKeys();
    config()->set('martis.auth.sso.issuer.enabled', true);
    stConsumer([$other['public_b64']]);

    [$code, $output] = ssoCheck();

    expect($code)->toBe(0)
        ->and($output)->toContain('ok')
        ->and($output)->toContain('martis.auth.sso.issuer')
        ->and($output)->toContain('martis.auth.sso.providers.panel_a')
        ->and($output)->toContain('martis.auth.sso.cache_store')
        ->and($output)->toContain('All 3 SSO checks passed')
        ->and($output)->not->toContain('FAIL');
});

it('fails an enabled issuer with no private key, naming the key', function () {
    config()->set('martis.auth.sso.issuer.enabled', true);
    config()->set('martis.auth.sso.issuer.private_key', null);

    [$code, $output] = ssoCheck();

    expect($code)->toBe(1)
        ->and($output)->toContain('FAIL')->toContain('martis.auth.sso.issuer.private_key');
});

it('fails an enabled issuer with a malformed private key without printing any part of it', function (string $value) {
    config()->set('martis.auth.sso.issuer.enabled', true);
    config()->set('martis.auth.sso.issuer.private_key', $value);

    [$code, $output] = ssoCheck();

    expect($code)->toBe(1)
        ->and($output)->toContain('martis.auth.sso.issuer.private_key')
        ->and($output)->not->toContain($value);
})->with([
    'not base64' => ['this-is-not-base64-at-all!!'],
    'wrong length' => [base64_encode(str_repeat('k', 31))],
    'base64 of the public half' => [base64_encode(str_repeat('p', 32))],
]);

it('fails an inconsistent private key (the public half does not match the seed) without printing it', function () {
    $a = stKeys();
    $b = stKeys();
    $mixed = substr($a['secret'], 0, 32).substr($b['secret'], 32);
    config()->set('martis.auth.sso.issuer.enabled', true);
    config()->set('martis.auth.sso.issuer.private_key', base64_encode($mixed));

    [$code, $output] = ssoCheck();

    expect($code)->toBe(1)
        ->and($output)->toContain('martis.auth.sso.issuer.private_key')->toContain('not a consistent')
        ->and(ssoCheckLeaks($output, base64_encode($mixed), $a['secret_b64'], $b['secret_b64']))->toBeFalse();
});

it('fails an enabled issuer whose other keys are unusable', function (string $key, mixed $value) {
    config()->set('martis.auth.sso.issuer.enabled', true);
    config()->set("martis.auth.sso.issuer.{$key}", $value);

    [$code, $output] = ssoCheck();

    expect($code)->toBe(1)->and($output)->toContain("martis.auth.sso.issuer.{$key}");
})->with([
    'ttl too long' => ['ttl_seconds', 9999],
    'audiences not an array' => ['audiences', 'panel-b'],
    'audience url not https' => ['audiences', ['panel-b' => ['callback_url' => 'http://b.example/cb']]],
    'empty issuer' => ['issuer', ''],
]);

it('does not read the issuer block while it is off, even if the block is broken', function () {
    config()->set('martis.auth.sso.issuer.enabled', false);
    config()->set('martis.auth.sso.issuer.private_key', 'garbage');

    [$code, $output] = ssoCheck();

    expect($code)->toBe(0)->and($output)->toContain('skip');
});

it('fails an issuer whose enabled flag is not a boolean', function () {
    config()->set('martis.auth.sso.issuer.enabled', 'maybe');

    [$code, $output] = ssoCheck();

    expect($code)->toBe(1)->and($output)->toContain('martis.auth.sso.issuer.enabled');
});

it('fails a consumer block with bad public keys, naming the key and printing no key', function () {
    $good = stKeys();
    stConsumer(['not-a-key', $good['public_b64']]);

    [$code, $output] = ssoCheck();

    expect($code)->toBe(1)
        ->and($output)->toContain('martis.auth.sso.providers.panel_a.public_keys.0')
        ->and($output)->not->toContain('not-a-key')
        ->and(ssoCheckLeaks($output, $good['public_b64']))->toBeFalse();
});

it('fails a consumer block with no public keys, a bad url or a removed key', function (array $overrides, string $key) {
    $good = stKeys();
    stConsumer([$good['public_b64']], 'panel_a', $overrides);

    [$code, $output] = ssoCheck();

    expect($code)->toBe(1)->and($output)->toContain($key);
})->with([
    'empty list' => [['public_keys' => []], 'martis.auth.sso.providers.panel_a.public_keys'],
    'http url' => [['authorize_url' => 'http://a.example/issue'], 'martis.auth.sso.providers.panel_a.authorize_url'],
    'no issuer' => [['issuer' => null], 'martis.auth.sso.providers.panel_a.issuer'],
    'no audience' => [['audience' => null], 'martis.auth.sso.providers.panel_a.audience'],
    'removed environment_claim' => [['environment_claim' => 'env'], 'martis.auth.sso.providers.panel_a.environment_claim'],
]);

it('reports every failing block and counts them, exit code 1', function () {
    $good = stKeys();
    config()->set('martis.auth.sso.issuer.enabled', true);
    config()->set('martis.auth.sso.issuer.private_key', 'garbage');
    stConsumer([$good['public_b64']], 'panel_a');
    stConsumer(['bad'], 'panel_c');

    [$code, $output] = ssoCheck();

    expect($code)->toBe(1)
        ->and($output)->toContain('martis.auth.sso.issuer.private_key')
        ->and($output)->toContain('martis.auth.sso.providers.panel_c.public_keys.0')
        ->and($output)->toContain('2 of 4 SSO checks failed');
});

it('checks only the enabled signed-token providers', function () {
    $good = stKeys();
    stConsumer(['bad'], 'panel_a', ['enabled' => false]);
    config()->set('martis.auth.sso.providers.azure', ['enabled' => true, 'driver' => null]);
    config()->set('martis.auth.sso.providers.custom', ['enabled' => true, 'driver' => 'unknown']);
    config()->set('martis.auth.sso.providers.panel_ok', array_merge(config('martis.auth.sso.providers.panel_a'), ['enabled' => true, 'public_keys' => [$good['public_b64']]]));

    [$code, $output] = ssoCheck();

    expect($code)->toBe(0)
        ->and($output)->toContain('martis.auth.sso.providers.panel_ok')
        ->and($output)->not->toContain('providers.panel_a]')
        ->and($output)->not->toContain('providers.azure]')
        ->and($output)->not->toContain('providers.custom]');
});

it('skips the providers while the master SSO switch is off', function () {
    stConsumer(['bad']);
    config()->set('martis.auth.sso.enabled', false);

    [$code, $output] = ssoCheck();

    expect($code)->toBe(0)->and($output)->not->toContain('FAIL');
});

it('fails a cache store that is not a configured store, naming the key', function () {
    $good = stKeys();
    stConsumer([$good['public_b64']]);
    config()->set('martis.auth.sso.cache_store', 'nope');

    [$code, $output] = ssoCheck();

    expect($code)->toBe(1)->and($output)->toContain('martis.auth.sso.cache_store');
});

it('writes nothing and prints no secret', function () {
    $other = stKeys();
    config()->set('martis.auth.sso.issuer.enabled', true);
    stConsumer([$other['public_b64']]);

    $before = [];
    foreach ([base_path('.env'), base_path('.env.example'), config_path('martis.php')] as $path) {
        $before[$path] = is_file($path) ? file_get_contents($path) : null;
    }
    $storage = glob(storage_path('*/*')) ?: [];
    $storageBefore = array_map(fn ($f) => is_file($f) ? md5_file($f) : null, $storage);

    [$code, $output] = ssoCheck();

    foreach ($before as $path => $contents) {
        expect(is_file($path) ? file_get_contents($path) : null)->toBe($contents);
    }

    expect($code)->toBe(0)
        ->and(array_map(fn ($f) => is_file($f) ? md5_file($f) : null, $storage))->toBe($storageBefore)
        ->and(ssoCheckLeaks($output, $this->keys['secret_b64'], $other['public_b64']))->toBeFalse();
});
