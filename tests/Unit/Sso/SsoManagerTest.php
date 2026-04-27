<?php

declare(strict_types=1);

use Martis\Sso\Contracts\SsoProviderContract;
use Martis\Sso\PermissionAdapters\CallableAdapter;
use Martis\Sso\PermissionAdapters\NativeAdapter;
use Martis\Sso\PermissionAdapters\SpatieAdapter;
use Martis\Sso\Providers\AzureProvider;
use Martis\Sso\SsoIdentity;
use Martis\Sso\SsoManager;

uses(\Martis\Tests\TestCase::class);

class SsoManagerTestProvider implements SsoProviderContract
{
    public function name(): string { return 'fake'; }
    public function redirect(\Illuminate\Http\Request $request): \Illuminate\Http\RedirectResponse { return redirect('/'); }
    public function resolveIdentity(\Illuminate\Http\Request $request): SsoIdentity {
        return new SsoIdentity('fake', '1', 'a@b', 'A');
    }
}

beforeEach(function () {
    /** @var SsoManager $manager */
    $manager = $this->app->make(SsoManager::class);
    $manager->flushHooksForTesting();

    config()->set('martis.auth.sso.enabled', true);
    config()->set('martis.auth.sso.providers', []);
});

afterEach(function () {
    /** @var SsoManager $manager */
    $manager = $this->app->make(SsoManager::class);
    $manager->flushHooksForTesting();
});

it('ships azure as a built-in provider', function () {
    $manager = new SsoManager;
    config()->set('martis.auth.sso.providers.azure.enabled', true);

    expect($manager->isEnabled('azure'))->toBeTrue();
    expect($manager->driver('azure'))->toBeInstanceOf(AzureProvider::class);
});

it('extend() registers a custom provider class', function () {
    $manager = new SsoManager;
    $manager->extend('fake', SsoManagerTestProvider::class);

    config()->set('martis.auth.sso.providers.fake.enabled', true);

    expect($manager->driver('fake'))->toBeInstanceOf(SsoManagerTestProvider::class);
});

it('isEnabled returns false when master switch is off', function () {
    $manager = new SsoManager;
    config()->set('martis.auth.sso.enabled', false);
    config()->set('martis.auth.sso.providers.azure.enabled', true);

    expect($manager->isEnabled('azure'))->toBeFalse();
});

it('isEnabled returns false when provider is disabled but master is on', function () {
    $manager = new SsoManager;
    config()->set('martis.auth.sso.providers.azure.enabled', false);

    expect($manager->isEnabled('azure'))->toBeFalse();
});

it('enabledProviders returns only providers with enabled=true', function () {
    $manager = new SsoManager;
    $manager->extend('fake', SsoManagerTestProvider::class);

    config()->set('martis.auth.sso.providers.azure.enabled', true);
    config()->set('martis.auth.sso.providers.fake.enabled', false);

    $enabled = $manager->enabledProviders();
    expect($enabled)->toContain('azure');
    expect($enabled)->not->toContain('fake');
});

it('adapterFor honours explicit "spatie" config', function () {
    $manager = new SsoManager;
    config()->set('martis.auth.sso.providers.azure.permission_adapter', 'spatie');

    expect($manager->adapterFor('azure'))->toBeInstanceOf(SpatieAdapter::class);
});

it('adapterFor honours explicit "native" config', function () {
    $manager = new SsoManager;
    config()->set('martis.auth.sso.providers.azure.permission_adapter', 'native');

    expect($manager->adapterFor('azure'))->toBeInstanceOf(NativeAdapter::class);
});

it('adapterFor honours explicit "callable" config', function () {
    $manager = new SsoManager;
    config()->set('martis.auth.sso.providers.azure.permission_adapter', 'callable');

    expect($manager->adapterFor('azure'))->toBeInstanceOf(CallableAdapter::class);
});

it('adapterFor "auto" picks Spatie when laravel-permission is installed, Native otherwise', function () {
    $manager = new SsoManager;
    config()->set('martis.auth.sso.providers.azure.permission_adapter', 'auto');

    $expected = SpatieAdapter::isAvailable() ? SpatieAdapter::class : NativeAdapter::class;
    expect($manager->adapterFor('azure'))->toBeInstanceOf($expected);
});

it('hooks fire when manually invoked', function () {
    $manager = new SsoManager;
    $afterFired = 0;
    $noMatchFired = 0;

    $manager->afterLogin(function () use (&$afterFired) { $afterFired++; });
    $manager->onNoRoleMatchUsing(function () use (&$noMatchFired) { $noMatchFired++; });

    $identity = new SsoIdentity('azure', '1', 'a@b', 'A');
    $userClass = config('auth.providers.users.model', \Illuminate\Foundation\Auth\User::class);
    $user = new $userClass;

    $manager->fireAfterLogin($user, $identity, 'azure');
    $manager->fireNoRoleMatch($identity, 'azure');

    expect($afterFired)->toBe(1);
    expect($noMatchFired)->toBe(1);
});

it('flushHooksForTesting wipes every registered closure', function () {
    $manager = new SsoManager;

    $manager->afterLogin(fn () => null);
    $manager->onNoRoleMatchUsing(fn () => null);
    $manager->resolveUserUsing(fn () => null);
    $manager->resolveRolesUsing(fn () => null);
    $manager->syncRolesUsing(fn () => null);

    $manager->flushHooksForTesting();

    $identity = new SsoIdentity('azure', '1', 'a@b', 'A');
    $userClass = config('auth.providers.users.model', \Illuminate\Foundation\Auth\User::class);
    $user = new $userClass;

    // Nothing should fire after flush.
    $afterCount = 0;
    $manager->afterLogin(function () use (&$afterCount) { $afterCount++; });
    $manager->flushHooksForTesting();
    $manager->fireAfterLogin($user, $identity, 'azure');
    expect($afterCount)->toBe(0);
});
