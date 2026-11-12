<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Auth\User;
use Martis\Sso\IdentityResolver;
use Martis\Sso\PermissionAdapters\CallableAdapter;
use Martis\Sso\RoleMapper;
use Martis\Sso\SsoIdentity;
use Martis\Sso\SsoManager;
use Martis\Tests\TestCase;

uses(TestCase::class);

function hookIdentity(string $provider = 'azure'): SsoIdentity
{
    return new SsoIdentity($provider, '1', 'a@b', 'A');
}

beforeEach(function () {
    $this->manager = new SsoManager;
    $this->user = new User;
});

it('afterLogin appends: every callback runs, in registration order', function () {
    $order = [];
    $this->manager->afterLogin(function () use (&$order) {
        $order[] = 'first';
    });
    $this->manager->afterLogin(function () use (&$order) {
        $order[] = 'second';
    });
    $this->manager->afterLogin(function () use (&$order) {
        $order[] = 'third';
    });

    $this->manager->fireAfterLogin($this->user, hookIdentity(), 'azure');

    expect($order)->toBe(['first', 'second', 'third']);
});

it('afterLogin passes the user, the identity and the provider to every callback', function () {
    $seen = [];
    $identity = hookIdentity();
    $this->manager->afterLogin(function ($user, $id, $provider) use (&$seen) {
        $seen[] = [$user, $id, $provider];
    });

    $this->manager->fireAfterLogin($this->user, $identity, 'azure');

    expect($seen)->toHaveCount(1)
        ->and($seen[0][0])->toBe($this->user)
        ->and($seen[0][1])->toBe($identity)
        ->and($seen[0][2])->toBe('azure');
});

it('afterLogin scoped to a provider runs for that provider only, global ones for all', function () {
    $ran = [];
    $this->manager->afterLogin(function () use (&$ran) {
        $ran[] = 'global';
    });
    $this->manager->afterLogin(function () use (&$ran) {
        $ran[] = 'azure-only';
    }, 'azure');
    $this->manager->afterLogin(function () use (&$ran) {
        $ran[] = 'okta-only';
    }, 'okta');

    $this->manager->fireAfterLogin($this->user, hookIdentity(), 'azure');
    expect($ran)->toBe(['global', 'azure-only']);

    $ran = [];
    $this->manager->fireAfterLogin($this->user, hookIdentity('okta'), 'okta');
    expect($ran)->toBe(['global', 'okta-only']);

    $ran = [];
    $this->manager->fireAfterLogin($this->user, hookIdentity('google'), 'google');
    expect($ran)->toBe(['global']);
});

it('afterLogin keeps registration order across global and scoped callbacks', function () {
    $ran = [];
    $this->manager->afterLogin(function () use (&$ran) {
        $ran[] = 'a-scoped';
    }, 'azure');
    $this->manager->afterLogin(function () use (&$ran) {
        $ran[] = 'b-global';
    });
    $this->manager->afterLogin(function () use (&$ran) {
        $ran[] = 'c-scoped';
    }, 'azure');

    $this->manager->fireAfterLogin($this->user, hookIdentity(), 'azure');

    expect($ran)->toBe(['a-scoped', 'b-global', 'c-scoped']);
});

it('fireAfterLogin with nothing registered does nothing', function () {
    $this->manager->fireAfterLogin($this->user, hookIdentity(), 'azure');

    expect(true)->toBeTrue();
});

it('onNoRoleMatchUsing keeps one global slot: the last registration wins', function () {
    $this->manager->onNoRoleMatchUsing(fn () => 'first');
    $this->manager->onNoRoleMatchUsing(fn () => 'second');

    expect($this->manager->fireNoRoleMatch(hookIdentity(), 'azure'))->toBe('second');
});

it('onNoRoleMatchUsing scoped to a provider wins over the global one for that provider', function () {
    $this->manager->onNoRoleMatchUsing(fn () => 'global');
    $this->manager->onNoRoleMatchUsing(fn () => 'azure', 'azure');

    expect($this->manager->fireNoRoleMatch(hookIdentity(), 'azure'))->toBe('azure')
        ->and($this->manager->fireNoRoleMatch(hookIdentity('okta'), 'okta'))->toBe('global');
});

it('onNoRoleMatchUsing keeps one slot per provider: the last registration for it wins', function () {
    $this->manager->onNoRoleMatchUsing(fn () => 'azure-1', 'azure');
    $this->manager->onNoRoleMatchUsing(fn () => 'azure-2', 'azure');
    $this->manager->onNoRoleMatchUsing(fn () => 'okta', 'okta');

    expect($this->manager->fireNoRoleMatch(hookIdentity(), 'azure'))->toBe('azure-2')
        ->and($this->manager->fireNoRoleMatch(hookIdentity('okta'), 'okta'))->toBe('okta');
});

it('fireNoRoleMatch returns null when neither a scoped nor a global callback exists', function () {
    $this->manager->onNoRoleMatchUsing(fn () => 'okta', 'okta');

    expect($this->manager->fireNoRoleMatch(hookIdentity(), 'azure'))->toBeNull();
});

it('forgetHooks clears every hook, scoped ones included', function () {
    $fired = [];
    $this->manager->afterLogin(function () use (&$fired) {
        $fired[] = 'afterLogin';
    });
    $this->manager->afterLogin(function () use (&$fired) {
        $fired[] = 'afterLogin-azure';
    }, 'azure');
    $this->manager->onNoRoleMatchUsing(fn () => 'global');
    $this->manager->onNoRoleMatchUsing(fn () => 'azure', 'azure');
    $this->manager->resolveUserUsing(fn () => null);
    $this->manager->resolveRolesUsing(fn () => new Collection);
    $this->manager->syncRolesUsing(function () use (&$fired) {
        $fired[] = 'sync';
    });

    $this->manager->forgetHooks();

    $this->manager->fireAfterLogin($this->user, hookIdentity(), 'azure');
    (new CallableAdapter)->syncRoles($this->user, new Collection);

    expect($fired)->toBe([])
        ->and($this->manager->fireNoRoleMatch(hookIdentity(), 'azure'))->toBeNull()
        ->and((new ReflectionProperty(IdentityResolver::class, 'resolver'))->getValue())->toBeNull()
        ->and((new ReflectionProperty(RoleMapper::class, 'resolver'))->getValue())->toBeNull();
});

it('flushHooksForTesting delegates to forgetHooks', function () {
    $fired = 0;
    $this->manager->afterLogin(function () use (&$fired) {
        $fired++;
    }, 'azure');
    $this->manager->onNoRoleMatchUsing(fn () => 'azure', 'azure');

    $this->manager->flushHooksForTesting();

    $this->manager->fireAfterLogin($this->user, hookIdentity(), 'azure');
    expect($fired)->toBe(0)
        ->and($this->manager->fireNoRoleMatch(hookIdentity(), 'azure'))->toBeNull();
});
