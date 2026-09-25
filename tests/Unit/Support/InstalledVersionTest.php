<?php

declare(strict_types=1);

use Composer\InstalledVersions;
use Martis\MartisManager;
use Martis\Support\InstalledVersion;
use Martis\Tests\TestCase;

uses(TestCase::class);

it('reports the version Composer installed for a package', function () {
    expect(InstalledVersion::of('martis/martis'))->toBe(InstalledVersions::getPrettyVersion('martis/martis'));
});

it('answers null, without throwing, for a package Composer did not install', function () {
    expect(InstalledVersion::of('acme/not-installed'))->toBeNull();
});

it('reports the installed version without its leading v on the sidebar', function () {
    config()->set('martis.brand.version', null);

    expect(app(MartisManager::class)->version())->toBe(ltrim((string) InstalledVersions::getPrettyVersion('martis/martis'), 'v'));
});

it('lets the brand.version config override the installed version on the sidebar', function () {
    config()->set('martis.brand.version', '9.9.9-custom');

    expect(app(MartisManager::class)->version())->toBe('9.9.9-custom');
});

// The cache keys need a value that changes on every install of new code. A
// tagged version does; a `dev-*` branch keeps its name across
// `composer update`, so its commit reference is appended.

it('stamps a tagged version as is', function () {
    expect(InstalledVersion::stamp('v2.0.0', '0123456789abcdef0123456789abcdef01234567'))->toBe('v2.0.0');
});

it('stamps a dev branch with its commit reference', function () {
    expect(InstalledVersion::stamp('dev-main', '0123456789abcdef0123456789abcdef01234567'))->toBe('dev-main@0123456789ab')
        ->and(InstalledVersion::stamp('dev-main', 'fedcba9876543210fedcba9876543210fedcba98'))->toBe('dev-main@fedcba987654');
});

it('stamps a branch alias or a numbered dev branch with its commit reference', function () {
    expect(InstalledVersion::stamp('2.x-dev', '0123456789abcdef0123456789abcdef01234567'))->toBe('2.x-dev@0123456789ab')
        ->and(InstalledVersion::stamp('2.0.x-dev', '0123456789abcdef0123456789abcdef01234567'))->toBe('2.0.x-dev@0123456789ab')
        ->and(InstalledVersion::stamp('v2.0.0-beta1', '0123456789abcdef0123456789abcdef01234567'))->toBe('v2.0.0-beta1');
});

it('stamps a dev branch without a reference with its name, and nothing as null', function () {
    expect(InstalledVersion::stamp('dev-main', null))->toBe('dev-main')
        ->and(InstalledVersion::stamp('dev-main', ''))->toBe('dev-main')
        ->and(InstalledVersion::stamp(null, 'abc'))->toBeNull();
});

it('fingerprints the installed package with its version stamp', function () {
    expect(InstalledVersion::fingerprint('martis/martis'))->toBe(InstalledVersion::stamp(
        InstalledVersions::getPrettyVersion('martis/martis'),
        InstalledVersions::getReference('martis/martis'),
    ))->and(InstalledVersion::fingerprint('acme/not-installed'))->toBeNull();
});
