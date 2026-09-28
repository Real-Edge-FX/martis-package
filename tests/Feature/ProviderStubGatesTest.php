<?php

declare(strict_types=1);

use Illuminate\Auth\GenericUser;
use Illuminate\Support\Facades\Gate;
use Martis\Stubs\StubResolver;

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
