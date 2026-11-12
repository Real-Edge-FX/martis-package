<?php

declare(strict_types=1);

use Martis\Auth\AuthConfigurationException;
use Martis\Auth\PublishedAuthConfig;

// ===========================================================================
// `window.MartisConfig.auth` is an allow-list (v2.21.0).
//
// The login page is public, so the blade encodes PublishedAuthConfig::toArray()
// and nothing of `config('martis.auth')` beyond it: not the SSO provider blocks
// (role maps, tenant ids, logout URLs), not column names or throttle values,
// not a key an app added.
// ===========================================================================

/** The `auth` entry of window.MartisConfig, decoded from the login page. */
function renderedAuthConfig(): array
{
    $html = test()->get('/martis/login')->assertOk()->getContent();

    expect(preg_match('/^\s*auth: (.*),$/m', $html, $match))->toBe(1);

    return json_decode($match[1], true, 512, JSON_THROW_ON_ERROR);
}

beforeEach(function () {
    config()->set('martis.auth.sso.enabled', true);
    config()->set('martis.auth.sso.providers', [
        'azure' => [
            'enabled' => true,
            'driver' => 'azure',
            'label' => 'Continue with Microsoft',
            'icon' => 'microsoft-outlook-logo',
            'tenant' => 'SENTINEL-TENANT-ID',
            'role_map' => ['SENTINEL-GROUP' => 'admin'],
            'logout_url' => 'https://idp.example.com/SENTINEL-LOGOUT',
        ],
        'okta' => [
            'enabled' => false,
            'label' => 'SENTINEL-DISABLED-LABEL',
            'tenant' => 'SENTINEL-DISABLED-TENANT',
        ],
    ]);
});

it('keeps a sentinel provider key and a sentinel top-level auth key out of the login page', function () {
    config()->set('martis.auth.sentinel_top_level', 'SENTINEL-TOP-LEVEL');
    config()->set('martis.auth.registration.default_role', 'SENTINEL-DEFAULT-ROLE');
    config()->set('martis.auth.passwordReset.broker', 'SENTINEL-BROKER');
    config()->set('martis.auth.password_change.column', 'SENTINEL_COLUMN');
    config()->set('martis.auth.magic_link.auto_register', true);
    config()->set('martis.auth.google', ['enabled' => true, 'url' => 'https://SENTINEL-GOOGLE.example.com']);

    $html = $this->get('/martis/login')->assertOk()->getContent();

    foreach ([
        'SENTINEL-TENANT-ID', 'SENTINEL-GROUP', 'SENTINEL-LOGOUT', 'SENTINEL-DISABLED', 'SENTINEL-TOP-LEVEL',
        'SENTINEL-DEFAULT-ROLE', 'SENTINEL-BROKER', 'SENTINEL_COLUMN', 'SENTINEL-GOOGLE', 'role_map', 'logout_url',
        'auto_register', 'sentinel_top_level',
    ] as $sentinel) {
        expect($html)->not->toContain($sentinel);
    }
});

it('publishes the label and icon the built-in login page reads, for an enabled provider only', function () {
    $auth = renderedAuthConfig();

    expect($auth['sso']['enabled'])->toBeTrue()
        ->and($auth['sso']['providers'])->toBe([
            'azure' => ['enabled' => true, 'label' => 'Continue with Microsoft', 'icon' => 'microsoft-outlook-logo'],
        ]);
});

it('publishes the keys a provider block lists in public, and nothing else of it', function () {
    config()->set('martis.auth.sso.providers.azure.public', ['tenant_label', 'brand_color', 'missing_key']);
    config()->set('martis.auth.sso.providers.azure.tenant_label', 'Acme Corp');
    config()->set('martis.auth.sso.providers.azure.brand_color', '#0078d4');

    $azure = renderedAuthConfig()['sso']['providers']['azure'];

    expect($azure)->toBe([
        'tenant_label' => 'Acme Corp',
        'brand_color' => '#0078d4',
        'enabled' => true,
        'label' => 'Continue with Microsoft',
        'icon' => 'microsoft-outlook-logo',
    ]);
});

it('never lets public override enabled, label or icon, nor publish itself', function () {
    config()->set('martis.auth.sso.providers.azure.public', ['public', 'label', 'tenant']);

    $azure = renderedAuthConfig()['sso']['providers']['azure'];

    expect($azure['label'])->toBe('Continue with Microsoft')
        ->and($azure)->not->toHaveKey('public')
        ->and($azure['tenant'])->toBe('SENTINEL-TENANT-ID');
});

it('throws naming the key when a provider public list is not a list of key names', function (mixed $value) {
    config()->set('martis.auth.sso.providers.azure.public', $value);

    expect(fn () => PublishedAuthConfig::toArray())
        ->toThrow(AuthConfigurationException::class, 'martis.auth.sso.providers.azure.public');
})->with(['a string' => 'tenant', 'a nested array' => [[['tenant']]], 'an empty name' => [['']]]);

it('publishes a label given as a map of locale to string, as it is', function () {
    config()->set('martis.auth.sso.providers.azure.label', ['en' => 'Continue with Microsoft', 'pt_PT' => 'Continuar com a Microsoft']);

    $azure = renderedAuthConfig()['sso']['providers']['azure'];

    expect($azure['label'])->toBe(['en' => 'Continue with Microsoft', 'pt_PT' => 'Continuar com a Microsoft']);
});

it('publishes an unset or empty label as null, so the page keeps its Continue with fallback', function (mixed $label) {
    config()->set('martis.auth.sso.providers.azure.label', $label);

    expect(renderedAuthConfig()['sso']['providers']['azure']['label'])->toBeNull();
})->with(['null' => [null], 'an empty map' => [[]]]);

it('keeps an unset label out of the way when the key is absent', function () {
    $block = config('martis.auth.sso.providers.azure');
    unset($block['label']);
    config()->set('martis.auth.sso.providers.azure', $block);

    expect(renderedAuthConfig()['sso']['providers']['azure']['label'])->toBeNull();
});

it('throws naming the key when a provider label is neither a string nor a map of strings', function (mixed $label) {
    config()->set('martis.auth.sso.providers.azure.label', $label);

    expect(fn () => PublishedAuthConfig::toArray())
        ->toThrow(AuthConfigurationException::class, 'martis.auth.sso.providers.azure.label');
})->with([
    'a number' => [5],
    'true' => [true],
    'a list' => [['Continue with Microsoft']],
    'a map with a non-string value' => [['en' => 5]],
    'a map with a nested array' => [['en' => ['Continue']]],
    'a map with a null value' => [['en' => null]],
    'a map with a numeric key' => [[0 => 'a', 'en' => 'b']],
]);

it('does not check the label of a provider that is not enabled', function () {
    config()->set('martis.auth.sso.providers.okta.label', 5);

    expect(renderedAuthConfig()['sso']['providers'])->toHaveKey('azure')->not->toHaveKey('okta');
});

it('publishes no provider while the SSO master switch is off', function () {
    config()->set('martis.auth.sso.enabled', false);

    $auth = renderedAuthConfig();

    expect($auth['sso'])->toBe(['enabled' => false, 'providers' => []]);
});

it('publishes exactly the keys the SPA reads, at every level', function () {
    config()->set('martis.auth.passwordReset', ['enabled' => true, 'url' => 'https://reset.example.com', 'broker' => 'x']);
    config()->set('martis.auth.registration', ['enabled' => true, 'url' => null, 'default_role' => 'free']);

    $auth = renderedAuthConfig();

    expect(array_keys($auth))->toEqualCanonicalizing([
        'sso', 'passwordReset', 'registration', 'controls', 'magicLink', 'emailVerification',
        'passwordRequirements', 'passwordChange', 'passwordLogin', 'challenge', 'copy',
    ])
        ->and($auth['passwordReset'])->toBe(['enabled' => true, 'url' => 'https://reset.example.com'])
        ->and($auth['registration'])->toBe(['enabled' => true, 'url' => null])
        ->and(array_keys($auth['controls']))->toBe(['theme', 'locale'])
        ->and($auth['magicLink'])->toBe(['enabled' => false])
        ->and($auth['emailVerification'])->toBe(['enabled' => false])
        ->and(array_keys($auth['passwordChange']))->toBe(['enabled', 'url'])
        ->and($auth['passwordLogin'])->toBe(['enabled' => true, 'breakGlass' => false])
        ->and(array_keys($auth['challenge']))->toBe(['enabled', 'siteKey', 'appliesTo']);
});

it('still publishes the copy block verbatim', function () {
    config()->set('martis.auth.copy.login.title', ['en' => 'Sign in to Acme', 'pt_PT' => 'Inicie sessão na Acme']);

    expect(renderedAuthConfig()['copy']['login']['title'])->toBe(['en' => 'Sign in to Acme', 'pt_PT' => 'Inicie sessão na Acme']);
});

it('never publishes the challenge verifier, only the site key', function () {
    config()->set('martis.auth.challenge', [
        'enabled' => true,
        'verifier' => 'App\\SENTINEL\\Verifier',
        'site_key' => 'site-key-123',
        'applies_to' => ['login', 'forgot'],
    ]);

    $html = $this->get('/martis/login')->assertOk()->getContent();

    expect($html)->not->toContain('SENTINEL')
        ->and(renderedAuthConfig()['challenge'])->toBe([
            'enabled' => true,
            'siteKey' => 'site-key-123',
            'appliesTo' => ['login', 'forgot'],
        ]);
});

// passwordLogin.breakGlass (v2.23.0): password sign-in is off AND the
// break-glass list holds an address. A boolean only: the list never reaches
// the browser.

it('publishes breakGlass true only when password sign-in is off and the list is not empty', function (bool $enabled, array $list, bool $expected) {
    config()->set('martis.auth.password_login.enabled', $enabled);
    config()->set('martis.auth.password_login.break_glass_emails', $list);

    expect(renderedAuthConfig()['passwordLogin'])->toBe(['enabled' => $enabled, 'breakGlass' => $expected]);
})->with([
    'on, no list' => [true, [], false],
    'on, with a list' => [true, ['ops@example.com'], false],
    'off, no list' => [false, [], false],
    'off, only blank entries' => [false, ['', '  '], false],
    'off, with a list' => [false, ['ops@example.com'], true],
]);

it('never publishes the break-glass addresses', function () {
    config()->set('martis.auth.password_login.enabled', false);
    config()->set('martis.auth.password_login.break_glass_emails', ['ops-secret@example.com']);

    $html = test()->get('/martis/login')->assertOk()->getContent();

    expect($html)->not->toContain('ops-secret')->not->toContain('break_glass');
});

it('throws, naming the key, for a break-glass list that is not usable while password sign-in is off', function () {
    config()->set('martis.auth.password_login.enabled', false);
    config()->set('martis.auth.password_login.break_glass_emails', 'ops@example.com');

    expect(fn () => PublishedAuthConfig::toArray())
        ->toThrow(AuthConfigurationException::class, 'martis.auth.password_login.break_glass_emails');
});
