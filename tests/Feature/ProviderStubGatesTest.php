<?php

declare(strict_types=1);

use Illuminate\Auth\GenericUser;
use Illuminate\Support\Facades\Gate;
use Martis\Stubs\StubResolver;
use MartisStubGateTest\StubProvider;

/*
 * The provider martis:install publishes tells the host which abilities
 * Martis closes. Its comment said every user passed `manage-martis-cache`
 * long after v1.16.0 made that ability deny by default: tie the comment to
 * the defaults the package registers.
 */

/**
 * @return array<string, array{string}>
 */
function martisDefaultDenyAbilities(): array
{
    return [
        'manage-martis-cache' => ['manage-martis-cache'],
        'bypass-martis-cache' => ['bypass-martis-cache'],
        'view-martis-action-events' => ['view-martis-action-events'],
        'martis-invite' => ['martis-invite'],
    ];
}

function martisProviderStub(): string
{
    return (string) file_get_contents(StubResolver::packagePath('MartisServiceProvider.php.stub'));
}

function martisRegisterGatesDocblock(): string
{
    preg_match('#/\*\*((?:(?!\*/).)*)\*/\s*protected function registerGates\(\)#s', martisProviderStub(), $match);

    return $match[1] ?? '';
}

it('denies the ability to an ordinary user of a fresh application', function (string $ability) {
    expect(Gate::forUser(new GenericUser(['id' => 1]))->denies($ability))->toBeTrue();
})->with(martisDefaultDenyAbilities());

it('names the ability in the registerGates() docblock of the provider stub', function (string $ability) {
    expect(martisRegisterGatesDocblock())->toContain("`{$ability}`");
})->with(martisDefaultDenyAbilities());

it('no longer tells the host that the Martis defaults are open', function () {
    expect(martisProviderStub())
        ->not->toContain('every authenticated user passes')
        ->not->toContain('tighten the permissive Martis defaults');
});

// -----------------------------------------------------------------------------
// v2.4.0: the stub ships the panel gate active (F019)
// -----------------------------------------------------------------------------

/** The provider stub as a class of its own, so its gates can be registered. */
function martisProviderStubInstance(): object
{
    if (! class_exists('MartisStubGateTest\\StubProvider', false)) {
        $code = str_replace(
            ['namespace App\\Providers;', 'class MartisServiceProvider'],
            ['namespace MartisStubGateTest;', 'class StubProvider'],
            martisProviderStub(),
        );
        eval('?>'.$code);
    }

    return new StubProvider(app());
}

it('ships the viewMartis gate active: local is let in, anywhere else only the listed addresses', function () {
    expect(martisProviderStub())
        ->toMatch("/^\s*Gate::define\('viewMartis'/m")
        ->toContain("app()->environment(['local', 'testing'])");
    expect(martisRegisterGatesDocblock())->toContain('`viewMartis`')->toContain('shut')->not->toContain('every user the Martis guard signs in gets in');
});

it('registers a viewMartis gate that lets local in and an empty allow-list refuse everyone elsewhere', function () {
    martisProviderStubInstance()->boot();
    $user = new GenericUser(['id' => 1, 'email' => 'someone@example.com']);

    expect(Gate::has('viewMartis'))->toBeTrue();

    app()['env'] = 'production';
    expect(Gate::forUser($user)->check('viewMartis'))->toBeFalse();

    app()['env'] = 'staging';
    expect(Gate::forUser($user)->check('viewMartis'))->toBeFalse();

    app()['env'] = 'local';
    expect(Gate::forUser($user)->check('viewMartis'))->toBeTrue();

    // `testing` is open too, as config/martis.php says: a consumer's own suite keeps working.
    app()['env'] = 'testing';
    expect(Gate::forUser($user)->check('viewMartis'))->toBeTrue();
});
